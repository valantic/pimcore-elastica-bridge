<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Messenger\Handler;

use Elastica\Document;
use Elastica\Index;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Pimcore\Helper\LongRunningHelper;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpKernel\KernelInterface;
use Valantic\ElasticaBridgeBundle\Document\DocumentInterface;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Messenger\Handler\CreateDocumentHandler;
use Valantic\ElasticaBridgeBundle\Messenger\Message\CreateDocumentMessage;
use Valantic\ElasticaBridgeBundle\Model\Event\CallbackEvent;
use Valantic\ElasticaBridgeBundle\Model\Event\ElasticaBridgeEvents;
use Valantic\ElasticaBridgeBundle\Model\Event\PostDocumentCreateEvent;
use Valantic\ElasticaBridgeBundle\Model\Event\PreDocumentCreateEvent;
use Valantic\ElasticaBridgeBundle\Model\Event\PreSwitchIndexEvent;
use Valantic\ElasticaBridgeBundle\Repository\ConfigurationRepository;
use Valantic\ElasticaBridgeBundle\Repository\DocumentRepository;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;
use Valantic\ElasticaBridgeBundle\Service\DocumentHelper;
use Valantic\ElasticaBridgeBundle\Tests\Helpers\StubDataObject;

class CreateDocumentHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private DocumentInterface&MockInterface $document;
    private Index&MockInterface $inactiveIndex;
    private ConfigurationRepository&MockInterface $configurationRepository;
    private EventDispatcher $eventDispatcher;
    private CreateDocumentHandler $handler;
    private ?KernelInterface $previousKernel;

    /**
     * @var list<PostDocumentCreateEvent>
     */
    private array $postEvents = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The handler calls \Pimcore::collectGarbage(), which needs a kernel.
        $this->previousKernel = \Pimcore::getKernel();
        $container = \Mockery::mock(ContainerInterface::class);
        $container->shouldReceive('get')->with(LongRunningHelper::class)->andReturn(\Mockery::spy());
        $kernel = \Mockery::mock(KernelInterface::class);
        $kernel->shouldReceive('getContainer')->andReturn($container);
        \Pimcore::setKernel($kernel);

        StubDataObject::$existingIds = [42];
        CreateDocumentHandler::$messageCount = 10;

        $this->inactiveIndex = \Mockery::mock(Index::class);
        $index = \Mockery::mock(IndexInterface::class);
        $index->shouldReceive('getName')->andReturn('products');
        $index->shouldReceive('getBlueGreenInactiveElasticaIndex')->andReturn($this->inactiveIndex);
        $indexRepository = \Mockery::mock(IndexRepository::class);
        $indexRepository->shouldReceive('flattenedGet')->with('products')->andReturn($index);

        $this->document = \Mockery::mock(DocumentInterface::class);
        $documentRepository = \Mockery::mock(DocumentRepository::class);
        $documentRepository->shouldReceive('get')->with('ProductDocument')->andReturn($this->document);

        $documentHelper = \Mockery::mock(DocumentHelper::class);
        $documentHelper->shouldReceive('setTenantIfNeeded');
        $documentHelper->shouldReceive('elementToDocument')->andReturnUsing(
            static fn (DocumentInterface $document, StubDataObject $element): Document => new Document((string) $element->getId()),
        );

        $this->configurationRepository = \Mockery::mock(ConfigurationRepository::class);
        $this->configurationRepository->shouldReceive('shouldSkipFailingDocuments')->andReturn(false)->byDefault();

        $consoleOutput = \Mockery::spy(ConsoleOutputInterface::class);
        $consoleOutput->shouldReceive('getVerbosity')->andReturn(ConsoleOutputInterface::VERBOSITY_NORMAL);

        $this->eventDispatcher = new EventDispatcher();
        $this->eventDispatcher->addListener(ElasticaBridgeEvents::POST_DOCUMENT_CREATE, function (PostDocumentCreateEvent $event): void {
            $this->postEvents[] = $event;
        });

        $this->handler = new CreateDocumentHandler(
            $documentHelper,
            $documentRepository,
            $indexRepository,
            $this->configurationRepository,
            $this->eventDispatcher,
            $consoleOutput,
        );
    }

    protected function tearDown(): void
    {
        if ($this->previousKernel instanceof KernelInterface) {
            \Pimcore::setKernel($this->previousKernel);
        }

        parent::tearDown();
    }

    public function testIndexesDocumentIntoInactiveIndex(): void
    {
        $this->document->shouldReceive('shouldIndex')->once()->andReturn(true);
        $this->inactiveIndex
            ->shouldReceive('addDocuments')
            ->once()
            ->with(\Mockery::on(static fn (array $documents): bool => count($documents) === 1 && $documents[0]->getId() === '42'))
        ;

        ($this->handler)($this->createMessage());

        $event = $this->getPostEvent();
        $this->assertTrue($event->success);
        $this->assertFalse($event->skipped);
        $this->assertFalse($event->willRetry);
        $this->assertNull($event->throwable);
        $this->assertInstanceOf(StubDataObject::class, $event->element);
        $this->assertSame(9, CreateDocumentHandler::$messageCount);
    }

    public function testAsynchronousMessagesDoNotChangeMessageCount(): void
    {
        $this->document->shouldReceive('shouldIndex')->andReturn(true);
        $this->inactiveIndex->shouldReceive('addDocuments')->once();

        ($this->handler)($this->createMessage(), synchronous: false);

        $this->assertTrue($this->getPostEvent()->success);
        $this->assertSame(10, CreateDocumentHandler::$messageCount);
    }

    public function testElementThatShouldNotBeIndexedCountsAsProcessed(): void
    {
        $this->document->shouldReceive('shouldIndex')->once()->andReturn(false);
        $this->inactiveIndex->shouldNotReceive('addDocuments');

        ($this->handler)($this->createMessage());

        $this->assertTrue($this->getPostEvent()->success);
        $this->assertSame(9, CreateDocumentHandler::$messageCount);
    }

    public function testStoppedExecutionIsReportedAsSkipped(): void
    {
        $this->eventDispatcher->addListener(ElasticaBridgeEvents::PRE_DOCUMENT_CREATE, static function (PreDocumentCreateEvent $event): void {
            $event->stopExecution();
        });
        $this->document->shouldNotReceive('shouldIndex');
        $this->inactiveIndex->shouldNotReceive('addDocuments');

        ($this->handler)($this->createMessage());

        $event = $this->getPostEvent();
        $this->assertFalse($event->success);
        $this->assertTrue($event->skipped);
        $this->assertFalse($event->willRetry);
        $this->assertSame(10, CreateDocumentHandler::$messageCount);
    }

    public function testFailureIsRethrownAndReportedAsRetryWhenNotSkippingFailingDocuments(): void
    {
        $this->document->shouldReceive('shouldIndex')->andReturn(true);
        $this->inactiveIndex->shouldReceive('addDocuments')->andThrow(new \RuntimeException('bulk failed'));

        try {
            ($this->handler)($this->createMessage());
            $this->fail('Expected the failure to be rethrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('bulk failed', $e->getMessage());
        }

        $event = $this->getPostEvent();
        $this->assertFalse($event->success);
        $this->assertFalse($event->skipped);
        $this->assertTrue($event->willRetry);
        $this->assertSame($e, $event->throwable);
        $this->assertSame(10, CreateDocumentHandler::$messageCount);
    }

    public function testFailureIsReportedAsSkippedWhenSkippingFailingDocuments(): void
    {
        $this->configurationRepository->shouldReceive('shouldSkipFailingDocuments')->andReturn(true);
        $this->document->shouldReceive('shouldIndex')->andReturn(true);
        $this->inactiveIndex->shouldReceive('addDocuments')->andThrow(new \RuntimeException('bulk failed'));

        ($this->handler)($this->createMessage());

        $event = $this->getPostEvent();
        $this->assertFalse($event->success);
        $this->assertTrue($event->skipped);
        $this->assertFalse($event->willRetry);
        $this->assertInstanceOf(\RuntimeException::class, $event->throwable);
    }

    public function testMissingElementIsSkippedWhenSkippingFailingDocuments(): void
    {
        $this->configurationRepository->shouldReceive('shouldSkipFailingDocuments')->andReturn(true);
        $this->document->shouldNotReceive('shouldIndex');

        ($this->handler)($this->createMessage(objectId: 404));

        $event = $this->getPostEvent();
        $this->assertFalse($event->success);
        $this->assertTrue($event->skipped);
        $this->assertNull($event->element);
        $this->assertSame(404, $event->elementId);
        $this->assertSame('DataObject not found', $event->throwable?->getMessage());
    }

    public function testDispatchesCallbackEvent(): void
    {
        $this->document->shouldReceive('shouldIndex')->andReturn(false);

        $callbackEvents = [];
        $this->eventDispatcher->addListener(ElasticaBridgeEvents::PRE_SWITCH_INDEX, static function (PreSwitchIndexEvent $event) use (&$callbackEvents): void {
            $callbackEvents[] = $event;
        });

        $callback = new CallbackEvent();
        $callback->setEvent(ElasticaBridgeEvents::PRE_SWITCH_INDEX, PreSwitchIndexEvent::class, [\Mockery::mock(IndexInterface::class)]);

        ($this->handler)($this->createMessage(callback: $callback));

        $this->assertCount(1, $callbackEvents);
    }

    private function createMessage(int $objectId = 42, ?CallbackEvent $callback = null): CreateDocumentMessage
    {
        return new CreateDocumentMessage($objectId, StubDataObject::class, 'ProductDocument', 'products', $callback);
    }

    private function getPostEvent(): PostDocumentCreateEvent
    {
        $this->assertCount(1, $this->postEvents);

        return $this->postEvents[0];
    }
}
