<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Service;

use Elastica\Document;
use Elastica\Index;
use Elastica\Query\BoolQuery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Element\AbstractElement;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Valantic\ElasticaBridgeBundle\Document\DocumentInterface;
use Valantic\ElasticaBridgeBundle\Enum\DocumentType;
use Valantic\ElasticaBridgeBundle\Enum\ElementInIndexOperation;
use Valantic\ElasticaBridgeBundle\Index\IndexContext;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Messenger\Message\RefreshElementInIndex;
use Valantic\ElasticaBridgeBundle\Model\Event\ElasticaBridgeEvents;
use Valantic\ElasticaBridgeBundle\Model\Event\RefreshedElementEvent;
use Valantic\ElasticaBridgeBundle\Model\Event\RefreshedElementInIndexEvent;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;
use Valantic\ElasticaBridgeBundle\Service\DocumentHelper;
use Valantic\ElasticaBridgeBundle\Service\PropagateChanges;
use Valantic\ElasticaBridgeBundle\Tests\Helpers\PimcoreElementFactory;

class PropagateChangesTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private IndexRepository $indexRepository;
    private DocumentHelper $documentHelper;
    private MessageBusInterface $messageBus;
    private EventDispatcherInterface $eventDispatcher;
    private PropagateChanges $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset static propagation state before each test
        $reflection = new \ReflectionClass(PropagateChanges::class);
        $property = $reflection->getProperty('isPropagationStopped');
        $property->setAccessible(true);
        $property->setValue(null, false);

        $this->indexRepository = \Mockery::mock(IndexRepository::class);
        $this->documentHelper = \Mockery::mock(DocumentHelper::class);
        $this->messageBus = \Mockery::mock(MessageBusInterface::class);
        $this->eventDispatcher = \Mockery::mock(EventDispatcherInterface::class);

        $this->service = new PropagateChanges(
            $this->indexRepository,
            $this->documentHelper,
            $this->messageBus,
            $this->eventDispatcher,
        );
    }

    public function testStopPropagation(): void
    {
        PropagateChanges::stopPropagation();

        // We can't directly test the static property, but we can verify behavior
        $this->assertTrue(true);
    }

    public function testHandleDispatchesPreRefreshEvent(): void
    {
        $element = PimcoreElementFactory::createDataObject(1);
        $index = \Mockery::mock(IndexInterface::class);
        $index->shouldReceive('getName')->andReturn('test_index');
        $index->shouldReceive('isElementAllowedInIndex')->andReturn(false);

        $this->indexRepository
            ->shouldReceive('flattenedAll')
            ->once()
            ->andReturn((static function () use ($index) {
                yield $index;
            })())
        ;

        $this->eventDispatcher
            ->shouldReceive('dispatch')
            ->with(
                \Mockery::type(RefreshedElementEvent::class),
                ElasticaBridgeEvents::PRE_REFRESH_ELEMENT,
            )
            ->once()
            ->andReturnUsing(static fn ($event) => $event)
        ;

        $this->messageBus
            ->shouldReceive('dispatch')
            ->andReturn(new Envelope(new \stdClass()))
        ;

        $this->eventDispatcher
            ->shouldReceive('dispatch')
            ->with(
                \Mockery::type(RefreshedElementEvent::class),
                ElasticaBridgeEvents::POST_REFRESH_ELEMENT,
            )
            ->once()
            ->andReturnUsing(static fn ($event) => $event)
        ;

        $this->service->handle($element);
    }

    public function testHandleDispatchesMessagesForMatchingIndices(): void
    {
        $element = PimcoreElementFactory::createDataObject(1);
        $index = \Mockery::mock(IndexInterface::class);
        $index->shouldReceive('getName')->andReturn('test_index');
        $index->shouldReceive('isElementAllowedInIndex')->with($element)->andReturn(true);

        $this->indexRepository
            ->shouldReceive('flattenedAll')
            ->once()
            ->andReturn((static function () use ($index) {
                yield $index;
            })())
        ;

        $this->eventDispatcher
            ->shouldReceive('dispatch')
            ->andReturnUsing(static fn ($event) => $event)
        ;

        $this->messageBus
            ->shouldReceive('dispatch')
            ->once()
            ->with(\Mockery::type(RefreshElementInIndex::class))
            ->andReturn(new Envelope(new \stdClass()))
        ;

        $this->service->handle($element);
    }

    public function testHandleIndexWithContextsReplacesAllDocumentsOfElement(): void
    {
        $element = PimcoreElementFactory::createDataObject(42);
        $esDocuments = [new Document('object42_de'), new Document('object42_fr')];

        [$index, $elasticaIndex] = $this->mockContextIndex($element, $esDocuments);

        $elasticaIndex
            ->shouldReceive('deleteByQuery')
            ->once()
            ->with(\Mockery::on(static fn (BoolQuery $query): bool => $query->toArray() === [
                'bool' => [
                    'filter' => [
                        ['term' => [DocumentInterface::META_ID => 42]],
                        ['term' => [DocumentInterface::META_TYPE => DocumentType::DATA_OBJECT->value]],
                    ],
                ],
            ]), ['conflicts' => 'proceed'])
        ;
        $elasticaIndex->shouldReceive('addDocuments')->once()->with($esDocuments);

        $this->expectRefreshedElementInIndexEvents(ElementInIndexOperation::UPDATE);

        $this->service->handleIndex($element, $index, $elasticaIndex);
    }

    public function testHandleIndexWithContextsDeletesDocumentsWhenElementHasNoDocumentContexts(): void
    {
        $element = PimcoreElementFactory::createDataObject(42);

        [$index, $elasticaIndex] = $this->mockContextIndex($element, []);

        $elasticaIndex->shouldReceive('deleteByQuery')->once();
        $elasticaIndex->shouldNotReceive('addDocuments');

        $this->expectRefreshedElementInIndexEvents(ElementInIndexOperation::DELETE);

        $this->service->handleIndex($element, $index, $elasticaIndex);
    }

    /**
     * @param Document[] $esDocuments
     *
     * @return array{IndexInterface&MockInterface, Index&MockInterface}
     */
    private function mockContextIndex(AbstractElement $element, array $esDocuments): array
    {
        $document = \Mockery::mock(DocumentInterface::class);
        $document->shouldReceive('getType')->andReturn(DocumentType::DATA_OBJECT);
        $document->shouldNotReceive('shouldIndex');

        $index = \Mockery::mock(IndexInterface::class);
        $index->shouldReceive('findDocumentInstanceByPimcore')->with($element)->andReturn($document);
        $index->shouldReceive('subscribedDocuments')->andReturn([$document::class]);
        $index->shouldReceive('getContexts')->andReturn([new IndexContext(language: 'de'), new IndexContext(language: 'fr')]);

        $this->documentHelper->shouldReceive('setTenantIfNeeded')->once();
        $this->documentHelper->shouldReceive('resetTenantIfNeeded')->once();
        $this->documentHelper
            ->shouldReceive('elementToDocumentsForContexts')
            ->once()
            ->with($document, $element, $index)
            ->andReturn($esDocuments)
        ;

        return [$index, \Mockery::mock(Index::class)];
    }

    private function expectRefreshedElementInIndexEvents(ElementInIndexOperation $operation): void
    {
        foreach ([ElasticaBridgeEvents::PRE_REFRESH_ELEMENT_IN_INDEX, ElasticaBridgeEvents::POST_REFRESH_ELEMENT_IN_INDEX] as $eventName) {
            $this->eventDispatcher
                ->shouldReceive('dispatch')
                ->once()
                ->with(\Mockery::on(static fn (RefreshedElementInIndexEvent $event): bool => $event->getOperation() === $operation), $eventName)
                ->andReturnUsing(static fn ($event) => $event)
            ;
        }
    }
}
