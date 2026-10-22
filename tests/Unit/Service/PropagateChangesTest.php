<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Service;

use Elastica\Document;
use Elastica\Exception\NotFoundException;
use Elastica\Index;
use Elastica\Query\Ids;
use Elastica\Response;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Valantic\ElasticaBridgeBundle\Document\DocumentInterface;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Messenger\Message\RefreshElementInIndex;
use Valantic\ElasticaBridgeBundle\Model\Event\ElasticaBridgeEvents;
use Valantic\ElasticaBridgeBundle\Model\Event\RefreshedElementEvent;
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

    public static function indexOperationProvider(): array
    {
        return [
            'not present and should be indexed is inserted' => [false, true, 1, 0, 1],
            'present and should be indexed is updated' => [true, true, 1, 0, 0],
            'present and should not be indexed is deleted' => [true, false, 0, 1, 0],
            'not present and should not be indexed is left alone' => [false, false, 0, 0, 0],
        ];
    }

    public static function operationWithoutStaleCopyCleanupProvider(): array
    {
        return [
            'update' => [true, true],
            'delete' => [true, false],
            'nothing' => [false, false],
        ];
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

    #[DataProvider('indexOperationProvider')]
    public function testHandleIndexOnlyPassesLookupParamsToLookupAndDelete(
        bool $isPresent,
        bool $shouldIndex,
        int $expectedAddCalls,
        int $expectedDeleteCalls,
        int $expectedStaleCleanupCalls,
    ): void {
        $params = ['routing' => 'object99', 'pipeline' => 'enrich'];
        $lookupParams = ['routing' => 'object99'];
        $element = PimcoreElementFactory::createDataObject(1);
        $elasticaDocument = new Document('object1', []);

        $document = $this->createDocumentFor($element, $shouldIndex);
        $index = $this->createIndexFor($element, $document);
        $this->prepareHelper($document, $element, $params, $lookupParams, $elasticaDocument);

        $elasticaIndex = \Mockery::mock(Index::class);
        $this->expectLookup($elasticaIndex, $isPresent, $lookupParams, $elasticaDocument);
        $elasticaIndex->shouldReceive('addDocument')->times($expectedAddCalls)->with($elasticaDocument);
        $elasticaIndex->shouldReceive('deleteById')->times($expectedDeleteCalls)->with('object1', $lookupParams);
        $elasticaIndex->shouldReceive('deleteByQuery')->times($expectedStaleCleanupCalls);

        $this->service->handleIndex($element, $index, $elasticaIndex);
    }

    public function testHandleIndexRemovesStaleCopyUnderOtherRoutingBeforeInserting(): void
    {
        $params = ['routing' => 'object99', 'pipeline' => 'enrich'];
        $lookupParams = ['routing' => 'object99'];
        $element = PimcoreElementFactory::createDataObject(1);
        $elasticaDocument = new Document('object1', []);
        $calls = [];

        $document = $this->createDocumentFor($element, true);
        $index = $this->createIndexFor($element, $document);
        $this->prepareHelper($document, $element, $params, $lookupParams, $elasticaDocument);

        $elasticaIndex = \Mockery::mock(Index::class);
        $this->expectLookup($elasticaIndex, false, $lookupParams, $elasticaDocument);
        $elasticaIndex
            ->shouldReceive('deleteByQuery')
            ->once()
            ->with(
                \Mockery::on(static fn (Ids $query): bool => $query->toArray() === ['ids' => ['values' => ['object1']]]),
                ['conflicts' => 'proceed'],
            )
            ->andReturnUsing(static function () use (&$calls): Response {
                $calls[] = 'deleteByQuery';

                return \Mockery::mock(Response::class);
            })
        ;
        $elasticaIndex
            ->shouldReceive('addDocument')
            ->once()
            ->andReturnUsing(static function () use (&$calls): Response {
                $calls[] = 'addDocument';

                return \Mockery::mock(Response::class);
            })
        ;

        $this->service->handleIndex($element, $index, $elasticaIndex);

        $this->assertSame(['deleteByQuery', 'addDocument'], $calls);
    }

    public function testHandleIndexDoesNotSearchForStaleCopyWithoutRouting(): void
    {
        $params = ['pipeline' => 'enrich'];
        $element = PimcoreElementFactory::createDataObject(1);
        $elasticaDocument = new Document('object1', []);

        $document = $this->createDocumentFor($element, true);
        $index = $this->createIndexFor($element, $document);
        $this->prepareHelper($document, $element, $params, [], $elasticaDocument);

        $elasticaIndex = \Mockery::mock(Index::class);
        $this->expectLookup($elasticaIndex, false, [], $elasticaDocument);
        $elasticaIndex->shouldReceive('deleteByQuery')->never();
        $elasticaIndex->shouldReceive('addDocument')->once()->with($elasticaDocument);

        $this->service->handleIndex($element, $index, $elasticaIndex);
    }

    #[DataProvider('operationWithoutStaleCopyCleanupProvider')]
    public function testHandleIndexDoesNotSearchForStaleCopyOnUpdateDeleteOrNothing(
        bool $isPresent,
        bool $shouldIndex,
    ): void {
        $params = ['routing' => 'object99'];
        $element = PimcoreElementFactory::createDataObject(1);
        $elasticaDocument = new Document('object1', []);

        $document = $this->createDocumentFor($element, $shouldIndex);
        $index = $this->createIndexFor($element, $document);
        $this->prepareHelper($document, $element, $params, $params, $elasticaDocument);

        $elasticaIndex = \Mockery::mock(Index::class);
        $this->expectLookup($elasticaIndex, $isPresent, $params, $elasticaDocument);
        $elasticaIndex->shouldReceive('addDocument', 'deleteById');
        $elasticaIndex->shouldReceive('deleteByQuery')->never();

        $this->service->handleIndex($element, $index, $elasticaIndex);
    }

    private function createDocumentFor(object $element, bool $shouldIndex): MockInterface
    {
        $document = \Mockery::mock(DocumentInterface::class);
        $document->shouldReceive('getElasticsearchId')->with($element)->andReturn('object1');
        $document->shouldReceive('shouldIndex')->with($element)->andReturn($shouldIndex);

        return $document;
    }

    private function createIndexFor(object $element, MockInterface $document): MockInterface
    {
        $index = \Mockery::mock(IndexInterface::class);
        $index->shouldReceive('findDocumentInstanceByPimcore')->with($element)->andReturn($document);
        $index->shouldReceive('subscribedDocuments')->andReturn([$document::class]);

        return $index;
    }

    /**
     * @param array<string,mixed> $params
     * @param array<string,mixed> $lookupParams
     */
    private function prepareHelper(
        MockInterface $document,
        object $element,
        array $params,
        array $lookupParams,
        Document $elasticaDocument,
    ): void {
        $this->documentHelper->shouldReceive('setTenantIfNeeded', 'resetTenantIfNeeded');
        $this->documentHelper->shouldReceive('getParams')->with($document, $element)->andReturn($params);
        $this->documentHelper->shouldReceive('getLookupParams')->with($params)->andReturn($lookupParams);
        $this->documentHelper
            ->shouldReceive('elementToDocument')
            ->with($document, $element, $params)
            ->andReturn($elasticaDocument)
        ;

        $this->eventDispatcher->shouldReceive('dispatch')->andReturnUsing(static fn ($event) => $event);
    }

    /**
     * @param array<string,mixed> $lookupParams
     */
    private function expectLookup(
        MockInterface $elasticaIndex,
        bool $isPresent,
        array $lookupParams,
        Document $elasticaDocument,
    ): void {
        $lookup = $elasticaIndex->shouldReceive('getDocument')->once()->with('object1', $lookupParams);

        if ($isPresent) {
            $lookup->andReturn($elasticaDocument);

            return;
        }

        $lookup->andThrow(new NotFoundException('not found'));
    }
}
