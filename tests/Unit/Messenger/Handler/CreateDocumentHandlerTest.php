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
use Valantic\ElasticaBridgeBundle\Model\Event\ElasticaBridgeEvents;
use Valantic\ElasticaBridgeBundle\Model\Event\PostDocumentCreateEvent;
use Valantic\ElasticaBridgeBundle\Model\Event\PreDocumentCreateEvent;
use Valantic\ElasticaBridgeBundle\Repository\ConfigurationRepository;
use Valantic\ElasticaBridgeBundle\Repository\DocumentRepository;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;
use Valantic\ElasticaBridgeBundle\Service\DocumentHelper;
use Valantic\ElasticaBridgeBundle\Tests\Helpers\PimcoreElementFactory;
use Valantic\ElasticaBridgeBundle\Tests\Helpers\StaticElementRegistry;

class CreateDocumentHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private Index&MockInterface $esIndex;
    private DocumentHelper&MockInterface $documentHelper;
    private ConfigurationRepository&MockInterface $configurationRepository;
    private DocumentInterface&MockInterface $document;
    private IndexInterface&MockInterface $index;
    private EventDispatcher $eventDispatcher;
    private CreateDocumentHandler $handler;

    /**
     * @var PostDocumentCreateEvent[]
     */
    private array $postEvents = [];

    private ?KernelInterface $previousKernel;

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

        StaticElementRegistry::$elements = [];
        CreateDocumentHandler::$messageCount = 0;

        $this->esIndex = \Mockery::mock(Index::class);
        $this->index = \Mockery::mock(IndexInterface::class);
        $this->index->shouldReceive('getBlueGreenInactiveElasticaIndex')->andReturn($this->esIndex);
        $this->document = \Mockery::mock(DocumentInterface::class);

        $indexRepository = \Mockery::mock(IndexRepository::class);
        $indexRepository->shouldReceive('flattenedGet')->with('products')->andReturn($this->index);
        $documentRepository = \Mockery::mock(DocumentRepository::class);
        $documentRepository->shouldReceive('get')->with('product_document')->andReturn($this->document);
        $this->documentHelper = \Mockery::mock(DocumentHelper::class);
        $this->documentHelper->shouldReceive('setTenantIfNeeded');
        $this->configurationRepository = \Mockery::mock(ConfigurationRepository::class);

        $this->eventDispatcher = new EventDispatcher();
        $this->eventDispatcher->addListener(ElasticaBridgeEvents::POST_DOCUMENT_CREATE, function (PostDocumentCreateEvent $event): void {
            $this->postEvents[] = $event;
        });

        $this->handler = new CreateDocumentHandler(
            $this->documentHelper,
            $documentRepository,
            $indexRepository,
            $this->configurationRepository,
            $this->eventDispatcher,
            \Mockery::spy(ConsoleOutputInterface::class),
        );
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(\Pimcore::class, 'kernel'))->setValue(null, $this->previousKernel);

        parent::tearDown();
    }

    public function testBulkFailureIsReportedPerElementWhenSkippingFailingDocuments(): void
    {
        $this->givenElementsWithDocuments(1, 2);
        $this->configurationRepository->shouldReceive('shouldSkipFailingDocuments')->andReturn(true);
        $exception = new \RuntimeException('bulk failed');
        $this->esIndex->shouldReceive('addDocuments')->once()->andThrow($exception);

        $this->handle(1, 2);

        $this->assertSame([1, 2], array_map(static fn (PostDocumentCreateEvent $event): ?int => $event->elementId, $this->postEvents));

        foreach ($this->postEvents as $event) {
            $this->assertFalse($event->success);
            $this->assertFalse($event->willRetry);
            $this->assertSame($exception, $event->throwable);
        }
    }

    public function testBulkFailureIsRethrownForRetryWhenNotSkippingFailingDocuments(): void
    {
        $this->givenElementsWithDocuments(1, 2);
        $this->configurationRepository->shouldReceive('shouldSkipFailingDocuments')->andReturn(false);
        $exception = new \RuntimeException('bulk failed');
        $this->esIndex->shouldReceive('addDocuments')->once()->andThrow($exception);

        try {
            $this->handle(1, 2);
            $this->fail('Expected the bulk failure to be rethrown');
        } catch (\RuntimeException $e) {
            $this->assertSame($exception, $e);
        }

        $this->assertCount(2, $this->postEvents);

        foreach ($this->postEvents as $event) {
            $this->assertFalse($event->success);
            $this->assertTrue($event->willRetry);
        }
    }

    public function testSuccessfulBatchIsSentInOneBulkRequest(): void
    {
        $this->givenElementsWithDocuments(1, 2);
        $this->esIndex->shouldReceive('addDocuments')->once()->with(\Mockery::on(static fn (array $documents): bool => count($documents) === 2));

        $this->handle(1, 2);

        $this->assertCount(2, $this->postEvents);

        foreach ($this->postEvents as $event) {
            $this->assertTrue($event->success);
        }
    }

    public function testRetriedMessageOnlyReportsTheFailingElement(): void
    {
        $this->givenElementsWithoutDocuments(1);
        $this->givenSkippedElement(2);
        $exception = new \RuntimeException('normalization failed');
        $this->givenFailingElement(3, $exception);
        $this->configurationRepository->shouldReceive('shouldSkipFailingDocuments')->andReturn(false);
        $this->esIndex->shouldNotReceive('addDocuments');
        CreateDocumentHandler::$messageCount = 3;

        try {
            $this->handle(1, 2, 3);
            $this->fail('Expected the element failure to be rethrown');
        } catch (\RuntimeException $e) {
            $this->assertSame($exception, $e);
        }

        // Elements 1 and 2 are reported when the message is retried; reporting them now would count them twice.
        $this->assertCount(1, $this->postEvents);
        $this->assertSame(3, $this->postEvents[0]->elementId);
        $this->assertTrue($this->postEvents[0]->willRetry);
        $this->assertSame(3, CreateDocumentHandler::$messageCount);
    }

    public function testRetriedBulkFailureDoesNotReportElementsWithoutDocuments(): void
    {
        $this->givenElementsWithoutDocuments(1);
        $this->givenElementsWithDocuments(2);
        $this->configurationRepository->shouldReceive('shouldSkipFailingDocuments')->andReturn(false);
        $this->esIndex->shouldReceive('addDocuments')->once()->andThrow(new \RuntimeException('bulk failed'));

        try {
            $this->handle(1, 2);
            $this->fail('Expected the bulk failure to be rethrown');
        } catch (\RuntimeException) {
        }

        $this->assertSame([2], array_map(static fn (PostDocumentCreateEvent $event): ?int => $event->elementId, $this->postEvents));
        $this->assertTrue($this->postEvents[0]->willRetry);
    }

    public function testCompletedMessageReportsEveryElementOnce(): void
    {
        $this->givenElementsWithoutDocuments(1);
        $this->givenSkippedElement(2);
        $this->givenFailingElement(3, new \RuntimeException('normalization failed'));
        $this->givenElementsWithDocuments(4);
        $this->configurationRepository->shouldReceive('shouldSkipFailingDocuments')->andReturn(true);
        $this->esIndex->shouldReceive('addDocuments')->once();
        CreateDocumentHandler::$messageCount = 4;

        $this->handle(1, 2, 3, 4);

        $outcomes = [];

        foreach ($this->postEvents as $event) {
            $outcomes[$event->elementId] = [$event->success, $event->skipped, $event->willRetry];
        }

        ksort($outcomes);
        $this->assertSame([
            1 => [true, false, false],
            2 => [false, true, false],
            3 => [false, false, false],
            4 => [true, false, false],
        ], $outcomes);
        $this->assertSame(2, CreateDocumentHandler::$messageCount);
    }

    private function givenElementsWithoutDocuments(int ...$ids): void
    {
        foreach ($ids as $id) {
            $element = PimcoreElementFactory::createDataObject($id);
            StaticElementRegistry::$elements[$id] = $element;
            $this->documentHelper
                ->shouldReceive('elementToDocumentsForContexts')
                ->with($this->document, $element, $this->index)
                ->andReturn([])
            ;
        }
    }

    private function givenSkippedElement(int $id): void
    {
        $element = PimcoreElementFactory::createDataObject($id);
        StaticElementRegistry::$elements[$id] = $element;
        $this->eventDispatcher->addListener(ElasticaBridgeEvents::PRE_DOCUMENT_CREATE, static function (PreDocumentCreateEvent $event) use ($element): void {
            if ($event->element === $element) {
                $event->stopExecution();
            }
        });
    }

    private function givenFailingElement(int $id, \Throwable $throwable): void
    {
        $element = PimcoreElementFactory::createDataObject($id);
        StaticElementRegistry::$elements[$id] = $element;
        $this->documentHelper
            ->shouldReceive('elementToDocumentsForContexts')
            ->with($this->document, $element, $this->index)
            ->andThrow($throwable)
        ;
    }

    private function givenElementsWithDocuments(int ...$ids): void
    {
        foreach ($ids as $id) {
            $element = PimcoreElementFactory::createDataObject($id);
            StaticElementRegistry::$elements[$id] = $element;
            $this->documentHelper
                ->shouldReceive('elementToDocumentsForContexts')
                ->with($this->document, $element, $this->index)
                ->andReturn([new Document((string) $id)])
            ;
        }
    }

    private function handle(int ...$ids): void
    {
        ($this->handler)(new CreateDocumentMessage($ids, StaticElementRegistry::class, 'product_document', 'products'));
    }
}
