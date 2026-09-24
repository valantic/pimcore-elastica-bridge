<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Document;

use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Elastica\Index;
use Elastica\Query\BoolQuery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Valantic\ElasticaBridgeBundle\Document\DocumentInterface;
use Valantic\ElasticaBridgeBundle\Document\DocumentRelationAwareDataObjectTrait;
use Valantic\ElasticaBridgeBundle\Enum\DocumentType;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Service\PopulateIndexService;
use Valantic\ElasticaBridgeBundle\Tests\Helpers\PimcoreElementFactory;

class DocumentRelationAwareDataObjectTraitTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private IndexInterface&MockInterface $index;
    private Index&MockInterface $activeIndex;
    private Index&MockInterface $inactiveIndex;
    private PopulateIndexService&MockInterface $populateIndexService;
    private object $document;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activeIndex = \Mockery::mock(Index::class);
        $this->inactiveIndex = \Mockery::mock(Index::class);
        $this->index = \Mockery::mock(IndexInterface::class);
        $this->index->shouldReceive('getElasticaIndex')->andReturn($this->activeIndex);
        $this->index->shouldReceive('getBlueGreenInactiveElasticaIndex')->andReturn($this->inactiveIndex);
        $this->populateIndexService = \Mockery::mock(PopulateIndexService::class);

        $this->document = new class($this->index) {
            use DocumentRelationAwareDataObjectTrait;

            public function __construct(IndexInterface $index)
            {
                $this->index = $index;
            }
        };
        $this->document->setIndexPopulationService($this->populateIndexService);
    }

    public function testCountsDocumentsReferencingTheElementInActiveIndex(): void
    {
        $this->givenPopulating(false, blueGreen: true);
        $this->activeIndex
            ->shouldReceive('count')
            ->once()
            ->with(\Mockery::on(static fn (BoolQuery $query): bool => $query->toArray() === [
                'bool' => [
                    'filter' => [
                        ['match' => [DocumentInterface::META_TYPE => DocumentType::DOCUMENT]],
                        ['match' => [DocumentInterface::ATTRIBUTE_RELATED_OBJECTS => 7]],
                    ],
                ],
            ]))
            ->andReturn(1)
        ;
        $this->inactiveIndex->shouldNotReceive('count');

        $this->assertTrue($this->document->shouldIndex(PimcoreElementFactory::createDataObject(7)));
    }

    public function testDoesNotIndexElementWithoutReferencingDocuments(): void
    {
        $this->givenPopulating(false, blueGreen: true);
        $this->activeIndex->shouldReceive('count')->andReturn(0);

        $this->assertFalse($this->document->shouldIndex(PimcoreElementFactory::createDataObject(7)));
    }

    public function testQueriesInactiveIndexWhilePopulatingBlueGreenIndex(): void
    {
        $this->givenPopulating(true, blueGreen: true);
        $this->inactiveIndex->shouldReceive('count')->once()->andReturn(3);
        $this->activeIndex->shouldNotReceive('count');

        $this->assertTrue($this->document->shouldIndex(PimcoreElementFactory::createDataObject(7)));
    }

    public function testQueriesActiveIndexWhilePopulatingWithoutBlueGreenIndices(): void
    {
        $this->givenPopulating(true, blueGreen: false);
        $this->activeIndex->shouldReceive('count')->once()->andReturn(3);
        $this->inactiveIndex->shouldNotReceive('count');

        $this->assertTrue($this->document->shouldIndex(PimcoreElementFactory::createDataObject(7)));
    }

    public function testClientErrorMeansNotIndexing(): void
    {
        $this->givenPopulating(false, blueGreen: true);
        $this->activeIndex->shouldReceive('count')->andThrow(new ClientResponseException('index_not_found_exception'));

        $this->assertFalse($this->document->shouldIndex(PimcoreElementFactory::createDataObject(7)));
    }

    public function testServerErrorMeansNotIndexing(): void
    {
        $this->givenPopulating(false, blueGreen: true);
        $this->activeIndex->shouldReceive('count')->andThrow(new ServerResponseException('unavailable'));

        $this->assertFalse($this->document->shouldIndex(PimcoreElementFactory::createDataObject(7)));
    }

    private function givenPopulating(bool $populating, bool $blueGreen): void
    {
        $this->populateIndexService->shouldReceive('isPopulating')->with($this->index)->andReturn($populating);
        $this->index->shouldReceive('usesBlueGreenIndices')->andReturn($blueGreen);
    }
}
