<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Service;

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
use Valantic\ElasticaBridgeBundle\Document\DocumentInterface;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Messenger\Message\PopulateIndexMessage;
use Valantic\ElasticaBridgeBundle\Messenger\Message\ReleaseIndexLock;
use Valantic\ElasticaBridgeBundle\Model\Event\ElasticaBridgeEvents;
use Valantic\ElasticaBridgeBundle\Model\Event\PreProcessMessagesEvent;
use Valantic\ElasticaBridgeBundle\Repository\ConfigurationRepository;
use Valantic\ElasticaBridgeBundle\Repository\DocumentRepository;
use Valantic\ElasticaBridgeBundle\Service\DocumentHelper;
use Valantic\ElasticaBridgeBundle\Service\LockService;
use Valantic\ElasticaBridgeBundle\Service\PopulationMessageGenerator;
use Valantic\ElasticaBridgeBundle\Service\PopulationProgress;

class PopulationMessageGeneratorTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private InMemoryStore $lockStore;

    private LockService $lockService;

    private EventDispatcher $eventDispatcher;

    private PopulationProgress $progress;

    private PopulationMessageGenerator $generator;

    private ?KernelInterface $previousKernel;

    /**
     * @var array<string, int> total count per document class
     */
    private array $documentCounts = [];

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
        $this->eventDispatcher = new EventDispatcher();
        $this->progress = new PopulationProgress();

        $documentRepository = \Mockery::mock(DocumentRepository::class);
        $documentRepository->shouldReceive('get')->andReturnUsing(function (string $name): DocumentInterface {
            $listing = \Mockery::mock(Listing::class);
            $listing->shouldReceive('getTotalCount')->andReturnUsing(fn (): int => $this->documentCounts[$name]);
            $listing->shouldReceive('setOffset', 'setLimit')->andReturnSelf();
            $listing->shouldReceive('loadIdList')->andReturn([]);
            $document = \Mockery::mock(DocumentInterface::class);
            $document->shouldReceive('getListingInstance')->andReturn($listing);

            return $document;
        });
        $documentHelper = \Mockery::mock(DocumentHelper::class);
        $documentHelper->shouldReceive('setTenantIfNeeded');

        $this->generator = new PopulationMessageGenerator(
            $documentRepository,
            $documentHelper,
            $this->lockService,
            $this->eventDispatcher,
            \Mockery::spy(ConsoleOutputInterface::class),
            $this->progress,
        );
    }

    protected function tearDown(): void
    {
        if ($this->previousKernel instanceof KernelInterface) {
            \Pimcore::setKernel($this->previousKernel);
        }

        parent::tearDown();
    }

    public function testCountsDocumentsOfAllAllowedDocumentClasses(): void
    {
        $this->documentCounts = ['product_document' => 7, 'variant_document' => 5];

        $this->assertSame(12, $this->generator->getDocumentCount($this->createIndex(['product_document', 'variant_document'])));
    }

    public function testCountIsZeroWithoutAllowedDocuments(): void
    {
        $this->assertSame(0, $this->generator->getDocumentCount($this->createIndex([])));
    }

    public function testAnnouncesDocumentCountAndStartsProgress(): void
    {
        $this->documentCounts = ['product_document' => 7, 'variant_document' => 5];
        $announced = [];
        $this->eventDispatcher->addListener(ElasticaBridgeEvents::PRE_PROCESS_MESSAGES_EVENT, static function (PreProcessMessagesEvent $event) use (&$announced): void {
            $announced[] = $event;
        });

        iterator_to_array($this->generator->generate($this->createIndex(['product_document', 'variant_document'])), false);

        $this->assertCount(1, $announced);
        $this->assertSame(12, $announced[0]->expectedMessages);
        $this->assertSame(12, $this->progress->getRemaining());
    }

    public function testWithoutDocumentsOnlyReleasesLockAndStartsCooldown(): void
    {
        $this->documentCounts = ['product_document' => 0];
        $index = $this->createIndex();

        $messages = iterator_to_array($this->generator->generate($index), false);

        $this->assertCount(1, $messages);
        $this->assertInstanceOf(PopulateIndexMessage::class, $messages[0]);
        $this->assertInstanceOf(ReleaseIndexLock::class, $messages[0]->message);
        $this->assertSame('products', $messages[0]->message->indexName);
        $this->assertSame($this->lockService->getIndexingKey($index), $messages[0]->message->key);
        $this->assertFalse($this->createLockService()->createLockFromKey($this->lockService->getKey('products', 'cooldown'))->acquire(), 'cooldown should be active');
    }

    public function testWithoutDocumentsSkipsCooldownWhenIgnored(): void
    {
        $this->documentCounts = ['product_document' => 0];

        iterator_to_array($this->generator->generate($this->createIndex(), ignoreCooldown: true), false);

        $this->assertTrue($this->createLockService()->createLockFromKey($this->lockService->getKey('products', 'cooldown'))->acquire(), 'cooldown should not be active');
    }

    public function testStartsCooldownAndQueuesNoSwitchWhenNoElementResolvesToAMessage(): void
    {
        // documents are counted, but none of the listed ids yields a message
        $this->documentCounts = ['product_document' => 3];

        $messages = iterator_to_array($this->generator->generate($this->createIndex()), false);

        $this->assertCount(1, $messages);
        $this->assertInstanceOf(ReleaseIndexLock::class, $messages[0]->message);
        $this->assertFalse($this->createLockService()->createLockFromKey($this->lockService->getKey('products', 'cooldown'))->acquire(), 'cooldown should be active');
    }

    /**
     * @param list<string> $allowedDocuments
     */
    private function createIndex(array $allowedDocuments = ['product_document']): IndexInterface&MockInterface
    {
        $index = \Mockery::mock(IndexInterface::class);
        $index->shouldReceive('getName')->andReturn('products');
        $index->shouldReceive('getAllowedDocuments')->andReturn($allowedDocuments);
        $index->shouldReceive('getBatchSize')->andReturn(500);

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
}
