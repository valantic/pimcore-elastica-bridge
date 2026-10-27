<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Messenger\Handler;

use Elastica\Bulk\Action;
use Elastica\Bulk\Response as BulkResponse;
use Elastica\Bulk\ResponseSet as BulkResponseSet;
use Elastica\Document;
use Elastica\Exception\Bulk\ResponseException as BulkResponseException;
use Elastica\Index;
use Elastica\Response;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Pimcore\Helper\LongRunningHelper;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
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
use Valantic\ElasticaBridgeBundle\Service\PopulationProgress;
use Valantic\ElasticaBridgeBundle\Tests\Helpers\StubDataObject;

class CreateDocumentHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private DocumentInterface&MockInterface $document;
    private DocumentRepository&MockInterface $documentRepository;
    private Index&MockInterface $inactiveIndex;
    private ConfigurationRepository&MockInterface $configurationRepository;
    private EventDispatcher $eventDispatcher;
    private CreateDocumentHandler $handler;
    private PopulationProgress $populationProgress;
    private ?KernelInterface $previousKernel;

    /**
     * @var list<PostDocumentCreateEvent>
     */
    private array $postEvents = [];

    /**
     * @var list<object>
     */
    private array $dispatchedMessages = [];

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
        $this->populationProgress = new PopulationProgress();
        $this->populationProgress->start(10);

        $this->inactiveIndex = \Mockery::mock(Index::class);
        $index = \Mockery::mock(IndexInterface::class);
        $index->shouldReceive('getName')->andReturn('products');
        $index->shouldReceive('getBlueGreenInactiveElasticaIndex')->andReturn($this->inactiveIndex);
        $indexRepository = \Mockery::mock(IndexRepository::class);
        $indexRepository->shouldReceive('flattenedGet')->with('products')->andReturn($index);

        $this->document = \Mockery::mock(DocumentInterface::class);
        $this->documentRepository = \Mockery::mock(DocumentRepository::class);
        $this->documentRepository->shouldReceive('get')->with('ProductDocument')->andReturn($this->document)->byDefault();

        $documentHelper = \Mockery::mock(DocumentHelper::class);
        $documentHelper->shouldReceive('setTenantIfNeeded');
        $documentHelper->shouldReceive('elementToDocumentsForContexts')->andReturnUsing(
            static fn (DocumentInterface $document, StubDataObject $element): array => $document->shouldIndex($element)
                ? [new Document((string) $element->getId())]
                : [],
        );

        $this->configurationRepository = \Mockery::mock(ConfigurationRepository::class);
        $this->configurationRepository->shouldReceive('shouldSkipFailingDocuments')->andReturn(false)->byDefault();

        $consoleOutput = \Mockery::spy(ConsoleOutputInterface::class);
        $consoleOutput->shouldReceive('getVerbosity')->andReturn(ConsoleOutputInterface::VERBOSITY_NORMAL);

        $this->eventDispatcher = new EventDispatcher();
        $this->eventDispatcher->addListener(ElasticaBridgeEvents::POST_DOCUMENT_CREATE, function (PostDocumentCreateEvent $event): void {
            $this->postEvents[] = $event;
        });

        $messageBus = \Mockery::mock(MessageBusInterface::class);
        $messageBus->shouldReceive('dispatch')->andReturnUsing(function (object $message): Envelope {
            $this->dispatchedMessages[] = $message;

            return new Envelope($message);
        });

        $this->handler = new CreateDocumentHandler(
            $documentHelper,
            $this->documentRepository,
            $indexRepository,
            $this->configurationRepository,
            $this->eventDispatcher,
            $consoleOutput,
            $this->populationProgress,
            $messageBus,
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
        $this->assertSame(9, $this->populationProgress->getRemaining());
    }

    public function testAsynchronousMessagesDoNotChangeMessageCount(): void
    {
        $this->document->shouldReceive('shouldIndex')->andReturn(true);
        $this->inactiveIndex->shouldReceive('addDocuments')->once();

        ($this->handler)($this->createMessage(), synchronous: false);

        $this->assertTrue($this->getPostEvent()->success);
        $this->assertSame(10, $this->populationProgress->getRemaining());
    }

    public function testElementThatShouldNotBeIndexedCountsAsProcessed(): void
    {
        $this->document->shouldReceive('shouldIndex')->once()->andReturn(false);
        $this->inactiveIndex->shouldNotReceive('addDocuments');

        ($this->handler)($this->createMessage());

        $this->assertTrue($this->getPostEvent()->success);
        $this->assertSame(9, $this->populationProgress->getRemaining());
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
        $this->assertSame(10, $this->populationProgress->getRemaining());
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
        $this->assertSame(10, $this->populationProgress->getRemaining());
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

    public function testSuccessfulBatchIsSentInOneBulkRequest(): void
    {
        StubDataObject::$existingIds = [1, 2];
        $this->document->shouldReceive('shouldIndex')->andReturn(true);
        $this->inactiveIndex->shouldReceive('addDocuments')->once()->with(\Mockery::on(static fn (array $documents): bool => count($documents) === 2));

        ($this->handler)($this->createMessage(objectIds: [1, 2]));

        $this->assertSame([1, 2], $this->getPostEventElementIds());

        foreach ($this->postEvents as $event) {
            $this->assertTrue($event->success);
        }

        $this->assertSame(8, $this->populationProgress->getRemaining());
    }

    public function testBulkFailureIsReportedPerElementWhenSkippingFailingDocuments(): void
    {
        StubDataObject::$existingIds = [1, 2];
        $this->configurationRepository->shouldReceive('shouldSkipFailingDocuments')->andReturn(true);
        $this->document->shouldReceive('shouldIndex')->andReturn(true);
        $exception = new \RuntimeException('bulk failed');
        $this->inactiveIndex->shouldReceive('addDocuments')->once()->andThrow($exception);

        ($this->handler)($this->createMessage(objectIds: [1, 2]));

        $this->assertSame([1, 2], $this->getPostEventElementIds());

        foreach ($this->postEvents as $event) {
            $this->assertFalse($event->success);
            $this->assertTrue($event->skipped);
            $this->assertFalse($event->willRetry);
            $this->assertSame($exception, $event->throwable);
        }
    }

    public function testBulkFailureIsRethrownForRetryWhenNotSkippingFailingDocuments(): void
    {
        StubDataObject::$existingIds = [1, 2];
        $this->document->shouldReceive('shouldIndex')->andReturn(true);
        $exception = new \RuntimeException('bulk failed');
        $this->inactiveIndex->shouldReceive('addDocuments')->once()->andThrow($exception);

        try {
            ($this->handler)($this->createMessage(objectIds: [1, 2]));
            $this->fail('Expected the bulk failure to be rethrown');
        } catch (\RuntimeException $e) {
            $this->assertSame($exception, $e);
        }

        $this->assertSame([1, 2], $this->getPostEventElementIds());

        foreach ($this->postEvents as $event) {
            $this->assertFalse($event->success);
            $this->assertTrue($event->willRetry);
        }

        $this->assertSame(10, $this->populationProgress->getRemaining());
    }

    public function testSynchronousMessageIndexesTheOtherElementsBeforeRethrowingAFailure(): void
    {
        StubDataObject::$existingIds = [1, 2, 3];
        $exception = new \RuntimeException('normalization failed');
        $this->givenShouldIndex([1 => true, 2 => $exception, 3 => true]);
        $this->inactiveIndex->shouldReceive('addDocuments')->once()->with(\Mockery::on(static fn (array $documents): bool => count($documents) === 2));

        try {
            ($this->handler)($this->createMessage(objectIds: [1, 2, 3]));
            $this->fail('Expected the element failure to be rethrown');
        } catch (\RuntimeException $e) {
            $this->assertSame($exception, $e);
        }

        // synchronous messages are not retried, so reporting the other elements can't count them twice
        $this->assertSame([1, 3, 2], $this->getPostEventElementIds());
        $this->assertTrue($this->postEvents[2]->willRetry);
        $this->assertSame([], $this->dispatchedMessages);
        $this->assertSame(8, $this->populationProgress->getRemaining());
    }

    public function testAsynchronousMessageRetriesFailingElementsInMessagesOfTheirOwn(): void
    {
        StubDataObject::$existingIds = [1, 2, 3];
        $this->givenShouldIndex([1 => true, 2 => new \RuntimeException('normalization failed'), 3 => true]);
        $this->inactiveIndex->shouldReceive('addDocuments')->once()->with(\Mockery::on(static fn (array $documents): bool => count($documents) === 2));

        ($this->handler)($this->createMessage(objectIds: [1, 2, 3]), synchronous: false);

        // element 2 is reported when its own message is handled
        $this->assertSame([1, 3], $this->getPostEventElementIds());
        $this->assertEquals([new CreateDocumentMessage([2], StubDataObject::class, 'ProductDocument', 'products')], $this->dispatchedMessages);
    }

    public function testAsynchronousMessageWithASingleElementIsRetried(): void
    {
        $exception = new \RuntimeException('normalization failed');
        $this->givenShouldIndex([42 => $exception]);

        try {
            ($this->handler)($this->createMessage(), synchronous: false);
            $this->fail('Expected the element failure to be rethrown');
        } catch (\RuntimeException $e) {
            $this->assertSame($exception, $e);
        }

        $this->assertTrue($this->getPostEvent()->willRetry);
        $this->assertSame([], $this->dispatchedMessages);
    }

    public function testPartialBulkFailureOnlyFailsTheAffectedElements(): void
    {
        StubDataObject::$existingIds = [1, 2];
        $this->givenShouldIndex([1 => true, 2 => true]);
        $exception = $this->bulkFailure('2');
        $this->inactiveIndex->shouldReceive('addDocuments')->once()->andThrow($exception);

        ($this->handler)($this->createMessage(objectIds: [1, 2]), synchronous: false);

        $this->assertSame([1], $this->getPostEventElementIds());
        $this->assertTrue($this->postEvents[0]->success);
        $this->assertEquals([new CreateDocumentMessage([2], StubDataObject::class, 'ProductDocument', 'products')], $this->dispatchedMessages);
    }

    public function testPartialBulkFailureIsReportedAsSkippedWhenSkippingFailingDocuments(): void
    {
        StubDataObject::$existingIds = [1, 2];
        $this->configurationRepository->shouldReceive('shouldSkipFailingDocuments')->andReturn(true);
        $this->givenShouldIndex([1 => true, 2 => true]);
        $exception = $this->bulkFailure('2');
        $this->inactiveIndex->shouldReceive('addDocuments')->once()->andThrow($exception);

        ($this->handler)($this->createMessage(objectIds: [1, 2]));

        $this->assertSame([1, 2], $this->getPostEventElementIds());
        $this->assertTrue($this->postEvents[0]->success);
        $this->assertFalse($this->postEvents[1]->success);
        $this->assertTrue($this->postEvents[1]->skipped);
        $this->assertSame($exception, $this->postEvents[1]->throwable);
        $this->assertSame(9, $this->populationProgress->getRemaining());
    }

    public function testUnattributableBulkFailureFailsAllElementsOfTheRequest(): void
    {
        StubDataObject::$existingIds = [1, 2];
        $this->configurationRepository->shouldReceive('shouldSkipFailingDocuments')->andReturn(true);
        $this->givenShouldIndex([1 => true, 2 => true]);
        $this->inactiveIndex->shouldReceive('addDocuments')->once()->andThrow($this->bulkFailure('unknown'));

        ($this->handler)($this->createMessage(objectIds: [1, 2]));

        $this->assertSame([1, 2], $this->getPostEventElementIds());

        foreach ($this->postEvents as $event) {
            $this->assertTrue($event->skipped);
        }
    }

    public function testDocumentLookupFailureFailsEveryElement(): void
    {
        StubDataObject::$existingIds = [1, 2];
        $exception = new \RuntimeException('no such document');
        $this->documentRepository->shouldReceive('get')->andThrow($exception);
        $this->inactiveIndex->shouldNotReceive('addDocuments');

        try {
            ($this->handler)($this->createMessage(objectIds: [1, 2]));
            $this->fail('Expected the failure to be rethrown');
        } catch (\RuntimeException $e) {
            $this->assertSame($exception, $e);
        }

        $this->assertSame([1, 2], $this->getPostEventElementIds());

        foreach ($this->postEvents as $event) {
            $this->assertTrue($event->willRetry);
            $this->assertSame($exception, $event->throwable);
        }
    }

    public function testDocumentLookupFailureIsReportedAsSkippedWhenSkippingFailingDocuments(): void
    {
        StubDataObject::$existingIds = [1, 2];
        $this->configurationRepository->shouldReceive('shouldSkipFailingDocuments')->andReturn(true);
        $this->documentRepository->shouldReceive('get')->andThrow(new \RuntimeException('no such document'));

        ($this->handler)($this->createMessage(objectIds: [1, 2]));

        $this->assertSame([1, 2], $this->getPostEventElementIds());

        foreach ($this->postEvents as $event) {
            $this->assertTrue($event->skipped);
            $this->assertFalse($event->willRetry);
        }
    }

    public function testCallbackEventIsNotDispatchedWhenEveryElementWasStopped(): void
    {
        $this->givenSkippedElement(42);
        $callbackEvents = [];
        $this->eventDispatcher->addListener(ElasticaBridgeEvents::PRE_SWITCH_INDEX, static function (PreSwitchIndexEvent $event) use (&$callbackEvents): void {
            $callbackEvents[] = $event;
        });

        $callback = new CallbackEvent();
        $callback->setEvent(ElasticaBridgeEvents::PRE_SWITCH_INDEX, PreSwitchIndexEvent::class, [\Mockery::mock(IndexInterface::class)]);

        ($this->handler)($this->createMessage(callback: $callback));

        $this->assertSame([], $callbackEvents);
    }

    public function testRetriedBulkFailureDoesNotReportElementsWithoutDocuments(): void
    {
        StubDataObject::$existingIds = [1, 2];
        $this->givenShouldIndex([1 => false, 2 => true]);
        $this->inactiveIndex->shouldReceive('addDocuments')->once()->andThrow(new \RuntimeException('bulk failed'));

        try {
            ($this->handler)($this->createMessage(objectIds: [1, 2]));
            $this->fail('Expected the bulk failure to be rethrown');
        } catch (\RuntimeException) {
        }

        $this->assertSame([2], $this->getPostEventElementIds());
        $this->assertTrue($this->postEvents[0]->willRetry);
    }

    public function testCompletedMessageReportsEveryElementOnce(): void
    {
        StubDataObject::$existingIds = [1, 2, 3, 4];
        $this->configurationRepository->shouldReceive('shouldSkipFailingDocuments')->andReturn(true);
        $this->givenShouldIndex([1 => false, 3 => new \RuntimeException('normalization failed'), 4 => true]);
        $this->givenSkippedElement(2);
        $this->inactiveIndex->shouldReceive('addDocuments')->once();

        ($this->handler)($this->createMessage(objectIds: [1, 2, 3, 4]));

        $outcomes = [];

        foreach ($this->postEvents as $event) {
            $outcomes[$event->elementId] = [$event->success, $event->skipped, $event->willRetry];
        }

        ksort($outcomes);
        $this->assertSame([
            1 => [true, false, false],
            2 => [false, true, false],
            3 => [false, true, false],
            4 => [true, false, false],
        ], $outcomes);
        $this->assertSame(8, $this->populationProgress->getRemaining());
    }

    public function testMissingElementCountsAsProcessedWithoutRetry(): void
    {
        $this->document->shouldNotReceive('shouldIndex');
        $this->inactiveIndex->shouldNotReceive('addDocuments');

        ($this->handler)($this->createMessage(objectIds: [404]));

        $event = $this->getPostEvent();
        $this->assertTrue($event->success);
        $this->assertFalse($event->skipped);
        $this->assertFalse($event->willRetry);
        $this->assertNull($event->throwable);
        $this->assertNull($event->element);
        $this->assertSame(404, $event->elementId);
        $this->assertSame(9, $this->populationProgress->getRemaining());
    }

    public function testMissingElementDoesNotDependOnSkippingFailingDocuments(): void
    {
        $this->configurationRepository->shouldReceive('shouldSkipFailingDocuments')->andReturn(true);

        ($this->handler)($this->createMessage(objectIds: [404]));

        $event = $this->getPostEvent();
        $this->assertTrue($event->success);
        $this->assertFalse($event->skipped);
        $this->assertNull($event->throwable);
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

    /**
     * @param int[] $objectIds
     */
    private function createMessage(array $objectIds = [42], ?CallbackEvent $callback = null): CreateDocumentMessage
    {
        return new CreateDocumentMessage($objectIds, StubDataObject::class, 'ProductDocument', 'products', $callback);
    }

    /**
     * @param array<int, bool|\Throwable> $outcomes element ID => shouldIndex() result, or the exception it throws
     */
    private function givenShouldIndex(array $outcomes): void
    {
        $this->document->shouldReceive('shouldIndex')->andReturnUsing(static function (StubDataObject $element) use ($outcomes): bool {
            $outcome = $outcomes[$element->getId()];

            if ($outcome instanceof \Throwable) {
                throw $outcome;
            }

            return $outcome;
        });
    }

    private function givenSkippedElement(int $id): void
    {
        $this->eventDispatcher->addListener(ElasticaBridgeEvents::PRE_DOCUMENT_CREATE, static function (PreDocumentCreateEvent $event) use ($id): void {
            if ($event->element->getId() === $id) {
                $event->stopExecution();
            }
        });
    }

    private function bulkFailure(string ...$failedDocumentIds): BulkResponseException
    {
        $responses = array_map(
            static fn (string $id): BulkResponse => new BulkResponse(['error' => ['type' => 'mapper_parsing_exception', 'reason' => 'failed']], (new Action())->setId($id), Action::OP_TYPE_INDEX),
            $failedDocumentIds,
        );

        return new BulkResponseException(new BulkResponseSet(new Response([]), $responses));
    }

    /**
     * @return array<int|null>
     */
    private function getPostEventElementIds(): array
    {
        return array_map(static fn (PostDocumentCreateEvent $event): ?int => $event->elementId, $this->postEvents);
    }

    private function getPostEvent(): PostDocumentCreateEvent
    {
        $this->assertCount(1, $this->postEvents);

        return $this->postEvents[0];
    }
}
