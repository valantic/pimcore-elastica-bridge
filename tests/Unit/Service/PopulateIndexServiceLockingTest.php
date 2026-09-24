<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Service;

use Elastica\Index as ElasticaIndex;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Pimcore\Helper\LongRunningHelper;
use Pimcore\Model\DataObject\Listing;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandlerArgumentsStamp;
use Valantic\ElasticaBridgeBundle\Document\DocumentInterface;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;
use Valantic\ElasticaBridgeBundle\Exception\Index\PopulationNotStartedException;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Messenger\Message\PopulateIndexMessage;
use Valantic\ElasticaBridgeBundle\Messenger\Message\ReleaseIndexLock;
use Valantic\ElasticaBridgeBundle\Messenger\Message\TriggerSingleIndexMessage;
use Valantic\ElasticaBridgeBundle\Model\Event\ElasticaBridgeEvents;
use Valantic\ElasticaBridgeBundle\Model\Event\PreExecuteEvent;
use Valantic\ElasticaBridgeBundle\Model\Event\PreSwitchIndexEvent;
use Valantic\ElasticaBridgeBundle\Repository\ConfigurationRepository;
use Valantic\ElasticaBridgeBundle\Repository\DocumentRepository;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;
use Valantic\ElasticaBridgeBundle\Service\DocumentHelper;
use Valantic\ElasticaBridgeBundle\Service\LockService;
use Valantic\ElasticaBridgeBundle\Service\PopulateIndexService;

/**
 * Covers when population may start (documents, cooldown, locks, pending messages) and what gets dispatched.
 */
class PopulateIndexServiceLockingTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private InMemoryStore $lockStore;
    private LockService $lockService;
    private IndexRepository&MockInterface $indexRepository;
    private MessageBusInterface&MockInterface $bus;
    private EventDispatcher $eventDispatcher;
    private DocumentRepository&MockInterface $documentRepository;
    private DocumentHelper&MockInterface $documentHelper;
    private PopulateIndexService $service;
    private int $documentCount = 10;
    private ?KernelInterface $previousKernel;

    /**
     * @var list<object>
     */
    private array $dispatched = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Message generation calls \Pimcore::collectGarbage(), which needs a kernel.
        $this->previousKernel = \Pimcore::getKernel();
        $container = \Mockery::mock(ContainerInterface::class);
        $container->shouldReceive('get')->with(LongRunningHelper::class)->andReturn(\Mockery::spy());
        $kernel = \Mockery::mock(KernelInterface::class);
        $kernel->shouldReceive('getContainer')->andReturn($container);
        \Pimcore::setKernel($kernel);

        $this->lockStore = new InMemoryStore();
        $this->lockService = $this->createLockService();

        $listing = \Mockery::mock(Listing::class);
        $listing->shouldReceive('getTotalCount')->andReturnUsing(fn (): int => $this->documentCount);
        $listing->shouldReceive('setOffset', 'setLimit')->andReturnSelf();
        $listing->shouldReceive('loadIdList')->andReturn([]);
        $document = \Mockery::mock(DocumentInterface::class);
        $document->shouldReceive('getListingInstance')->andReturn($listing);
        $documentRepository = \Mockery::mock(DocumentRepository::class);
        $documentRepository->shouldReceive('get')->with('product_document')->andReturn($document);
        $documentHelper = \Mockery::mock(DocumentHelper::class);
        $documentHelper->shouldReceive('setTenantIfNeeded');

        $this->documentRepository = $documentRepository;
        $this->documentHelper = $documentHelper;
        $this->indexRepository = \Mockery::mock(IndexRepository::class);
        $this->bus = \Mockery::mock(MessageBusInterface::class);
        $this->bus->shouldReceive('dispatch')->andReturnUsing(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });
        $this->eventDispatcher = new EventDispatcher();

        $this->service = $this->createService($this->lockService);
    }

    protected function tearDown(): void
    {
        if ($this->previousKernel instanceof KernelInterface) {
            \Pimcore::setKernel($this->previousKernel);
        }

        parent::tearDown();
    }

    public function testRefusesToPopulateIndexWithoutDocuments(): void
    {
        $this->documentCount = 0;

        $this->assertNotStarted(PopulationNotStartedException::TYPE_NO_DOCUMENTS, fn () => iterator_to_array($this->service->triggerSingleIndex($this->createIndex(), populate: true)));
    }

    public function testRefusesToPopulateDuringCooldown(): void
    {
        $this->createLockService()->initiateCooldown('products');

        $this->assertNotStarted(PopulationNotStartedException::TYPE_COOLDOWN, fn () => iterator_to_array($this->service->triggerSingleIndex($this->createIndex(), populate: true)));
    }

    public function testIgnoresCooldownWhenRequested(): void
    {
        $this->createLockService()->initiateCooldown('products');

        $messages = iterator_to_array($this->service->triggerSingleIndex($this->createIndex(), populate: false, ignoreCooldown: true), false);

        $this->assertSame([], $messages);
    }

    public function testRefusesToPopulateWhileAnotherProcessHoldsTheIndexingLock(): void
    {
        $otherProcess = $this->createLockService()->getIndexingLock($this->createIndex());
        $this->assertTrue($otherProcess->acquire());

        $this->assertNotStarted(PopulationNotStartedException::TYPE_PROCESSING, fn () => iterator_to_array($this->service->triggerSingleIndex($this->createIndex(), populate: true)));
    }

    public function testIgnoresIndexingLockWhenRequested(): void
    {
        $otherProcess = $this->createLockService()->getIndexingLock($this->createIndex());
        $this->assertTrue($otherProcess->acquire());

        $messages = iterator_to_array($this->service->triggerSingleIndex($this->createIndex(), populate: false, ignoreLock: true), false);

        $this->assertSame([], $messages);
    }

    public function testRefusesToPopulateWhileMessagesArePending(): void
    {
        $this->eventDispatcher->addListener(ElasticaBridgeEvents::PRE_SWITCH_INDEX, static function (PreSwitchIndexEvent $event): void {
            $event->setRemainingMessages(3);
        });

        $this->assertNotStarted(PopulationNotStartedException::TYPE_PROCESSING_MESSAGES, fn () => iterator_to_array($this->service->triggerSingleIndex($this->createIndex(), populate: true)));
    }

    public function testPopulationKeepsIndexingLockUntilReleaseMessage(): void
    {
        $index = $this->createIndex();

        $generator = $this->service->triggerSingleIndex($index, populate: true);
        $generator->current();

        $this->assertFalse($this->createLockService()->getIndexingLock($index)->acquire(), 'indexing lock should be held by the population');
    }

    public function testMessageGenerationWithoutDocumentsOnlyReleasesLockAndStartsCooldown(): void
    {
        $this->documentCount = 0;
        $index = $this->createIndex();

        $messages = iterator_to_array($this->service->generateMessagesForIndex($index), false);

        $this->assertCount(1, $messages);
        $this->assertInstanceOf(PopulateIndexMessage::class, $messages[0]);
        $this->assertInstanceOf(ReleaseIndexLock::class, $messages[0]->message);
        $this->assertSame('products', $messages[0]->message->indexName);
        $this->assertSame($this->lockService->getIndexingKey($index), $messages[0]->message->key);
        $this->assertFalse($this->createLockService()->createLockFromKey($this->lockService->getKey('products', 'cooldown'))->acquire(), 'cooldown should be active');
    }

    public function testMessageGenerationWithoutDocumentsSkipsCooldownWhenIgnored(): void
    {
        $this->documentCount = 0;

        iterator_to_array($this->service->generateMessagesForIndex($this->createIndex(), ignoreCooldown: true), false);

        $this->assertTrue($this->createLockService()->createLockFromKey($this->lockService->getKey('products', 'cooldown'))->acquire(), 'cooldown should not be active');
    }

    public function testProcessApiQueuesTriggerMessageAndHoldsQueueLock(): void
    {
        $index = $this->createIndex();
        $preExecuteEvents = $this->collectEvents(ElasticaBridgeEvents::PRE_EXECUTE);

        $this->service->processApi($index, populate: true, ignoreCooldown: true);

        $this->assertCount(1, $this->dispatched);
        $message = $this->dispatched[0];
        $this->assertInstanceOf(TriggerSingleIndexMessage::class, $message);
        $this->assertSame('products', $message->indexName);
        $this->assertTrue($message->populate);
        $this->assertTrue($message->ignoreCooldown);
        $this->assertFalse($message->ignoreLock);
        $this->assertSame('pimcore-elastica-bridge:queue:products', (string) $message->key);
        $this->assertSame(PreExecuteEvent::SOURCE_API, $preExecuteEvents[0]->source);

        $this->assertNotStarted(PopulationNotStartedException::TYPE_PROCESSING, fn () => $this->createService($this->createLockService())->processApi($index, populate: true, ignoreCooldown: true));
        $this->assertCount(1, $this->dispatched);
    }

    public function testProcessApiReleasesQueueLockWhenPopulationCannotStart(): void
    {
        $index = $this->createIndex();
        $otherProcess = $this->createLockService()->getIndexingLock($index);
        $this->assertTrue($otherProcess->acquire());

        $this->assertNotStarted(PopulationNotStartedException::TYPE_PROCESSING, fn () => $this->service->processApi($index, populate: true, ignoreCooldown: true));

        $otherProcess->release();
        $this->createService($this->createLockService())->processApi($index, populate: true, ignoreCooldown: true);

        $this->assertCount(1, $this->dispatched);
    }

    public function testDoesNotLeaveCooldownActiveWhenMessagesArePending(): void
    {
        $this->eventDispatcher->addListener(ElasticaBridgeEvents::PRE_SWITCH_INDEX, static function (PreSwitchIndexEvent $event): void {
            $event->setRemainingMessages(3);
        });

        $this->assertNotStarted(PopulationNotStartedException::TYPE_PROCESSING_MESSAGES, fn () => iterator_to_array($this->service->triggerSingleIndex($this->createIndex(), populate: true)));

        $this->assertTrue($this->createLockService()->createLockFromKey($this->lockService->getKey('products', 'cooldown'))->acquire(), 'cooldown should not be active');
    }

    public function testProcessApiResolvesIndexByName(): void
    {
        $index = $this->createIndex();
        $this->indexRepository->shouldReceive('flattenedGet')->with('products')->andReturn($index);

        $this->service->processApi('products');

        $this->assertInstanceOf(TriggerSingleIndexMessage::class, $this->dispatched[0]);
    }

    public function testSchedulerSkipsIndicesThatCannotStartAndMarksMessagesAsAsync(): void
    {
        $cooldownIndex = $this->createIndex('categories');
        $this->createLockService()->initiateCooldown('categories');
        $index = $this->createIndex();
        $this->indexRepository->shouldReceive('flattenedAll')->andReturnUsing(static function () use ($cooldownIndex, $index): \Generator {
            yield 'categories' => $cooldownIndex;

            yield 'products' => $index;
        });
        $preExecuteEvents = $this->collectEvents(ElasticaBridgeEvents::PRE_EXECUTE);

        $generator = $this->service->processScheduler();
        $envelope = $generator->current();

        $this->assertInstanceOf(Envelope::class, $envelope);
        $this->assertInstanceOf(PopulateIndexMessage::class, $envelope->getMessage());
        $this->assertSame(['synchronous' => false], $envelope->last(HandlerArgumentsStamp::class)?->getAdditionalArguments());
        $this->assertSame([PreExecuteEvent::SOURCE_SCHEDULER, PreExecuteEvent::SOURCE_SCHEDULER], array_map(static fn (PreExecuteEvent $event): int => $event->source, $preExecuteEvents->getArrayCopy()));
        $this->assertContains('categories: <fg=red>Process not started (cooldown)</>', $this->service->getLog());
    }

    private function createIndex(string $name = 'products'): IndexInterface&MockInterface
    {
        $elasticaIndex = \Mockery::mock(ElasticaIndex::class);
        $elasticaIndex->shouldReceive('exists')->andReturnTrue();

        $index = \Mockery::mock(IndexInterface::class);
        $index->shouldReceive('getName')->andReturn($name);
        $index->shouldReceive('getAllowedDocuments')->andReturn(['product_document']);
        $index->shouldReceive('getBatchSize')->andReturn(500);
        $index->shouldReceive('usesBlueGreenIndices')->andReturnFalse();
        $index->shouldReceive('getElasticaIndex')->andReturn($elasticaIndex);

        return $index;
    }

    /**
     * A separate LockService on the same store, i.e. another process.
     */
    private function createLockService(): LockService
    {
        $configurationRepository = \Mockery::mock(ConfigurationRepository::class);
        $configurationRepository->shouldReceive('getIndexingLockTimeout')->andReturn(300);
        $configurationRepository->shouldReceive('getCooldown')->andReturn(60);

        return new LockService(new LockFactory($this->lockStore), $configurationRepository, \Mockery::spy(ConsoleOutputInterface::class));
    }

    private function createService(LockService $lockService): PopulateIndexService
    {
        return new PopulateIndexService(
            $this->indexRepository,
            \Mockery::mock(ElasticsearchClient::class),
            $lockService,
            $this->documentRepository,
            $this->documentHelper,
            $this->eventDispatcher,
            $this->bus,
            \Mockery::spy(ConsoleOutputInterface::class),
        );
    }

    /**
     * @param callable(): mixed $callback
     */
    private function assertNotStarted(string $type, callable $callback): void
    {
        try {
            $callback();
        } catch (PopulationNotStartedException $exception) {
            $this->assertSame($type, $exception->getType());

            return;
        }

        $this->fail(sprintf('Expected PopulationNotStartedException (%s)', $type));
    }

    /**
     * @return \ArrayObject<int, object>
     */
    private function collectEvents(string $eventName): \ArrayObject
    {
        $events = new \ArrayObject();
        $this->eventDispatcher->addListener($eventName, static function (object $event) use ($events): void {
            $events[] = $event;
        });

        return $events;
    }
}
