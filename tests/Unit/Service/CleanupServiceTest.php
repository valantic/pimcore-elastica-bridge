<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Service;

use Elastic\Elasticsearch\Exception\ElasticsearchException;
use Elastica\Cluster;
use Elastica\Index;
use Elastica\Index\Settings;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;
use Valantic\ElasticaBridgeBundle\Service\CleanupService;

class CleanupServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private ElasticsearchClient&MockInterface $esClient;
    private IndexRepository&MockInterface $indexRepository;
    private CleanupService $cleanupService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->esClient = \Mockery::mock(ElasticsearchClient::class);
        $this->indexRepository = \Mockery::mock(IndexRepository::class);

        $this->cleanupService = new CleanupService(
            $this->esClient,
            $this->indexRepository,
        );
    }

    public function testCleanUpDeletesKnownIndicesAndAliases(): void
    {
        $this->mockKnownIndices(['products' => false]);
        $index = $this->mockEsIndex('products', aliases: ['products_alias']);
        $index->shouldReceive('removeAlias')->once()->with('products_alias');
        $index->shouldReceive('delete')->once();

        $result = $this->cleanupService->cleanUp();

        $this->assertFalse($result->isDryRun());
        $this->assertSame(['products' => ['products_alias']], $result->getRemovedAliases());
        $this->assertSame(['products'], $result->getDeletedIndices());
        $this->assertFalse($result->hasErrors());
    }

    public function testCleanUpIncludesBothBlueGreenIndices(): void
    {
        $this->mockKnownIndices(['products' => true]);
        $this->mockEsIndex('products--blue')->shouldReceive('delete')->once();
        $this->mockEsIndex('products--green')->shouldReceive('delete')->once();

        $result = $this->cleanupService->cleanUp();

        $this->assertSame(['products--blue', 'products--green'], $result->getDeletedIndices());
    }

    public function testDryRunDoesNotDeleteAnything(): void
    {
        $this->mockKnownIndices(['products' => false]);
        $index = $this->mockEsIndex('products', aliases: ['products_alias']);
        $index->shouldNotReceive('removeAlias');
        $index->shouldNotReceive('delete');

        $result = $this->cleanupService->cleanUp(dryRun: true);

        $this->assertTrue($result->isDryRun());
        $this->assertSame(['products' => ['products_alias']], $result->getRemovedAliases());
        $this->assertSame(['products'], $result->getDeletedIndices());
    }

    public function testAllInClusterSkipsNonBundleAndHiddenIndices(): void
    {
        $this->indexRepository->shouldNotReceive('flattenedAll');

        $cluster = \Mockery::mock(Cluster::class);
        $cluster->shouldReceive('getIndexNames')->andReturn(['.geoip_databases', '.hidden_index', 'other']);
        $this->esClient->shouldReceive('getCluster')->andReturn($cluster);
        $this->esClient->shouldNotReceive('getIndex')->with('.geoip_databases');

        $hidden = $this->mockEsIndex('.hidden_index', hidden: true);
        $hidden->shouldNotReceive('delete');
        $this->mockEsIndex('other')->shouldReceive('delete')->once();

        $result = $this->cleanupService->cleanUp(allInCluster: true);

        $this->assertSame(['other'], $result->getDeletedIndices());
    }

    public function testDeleteErrorsAreCollected(): void
    {
        $this->mockKnownIndices(['broken' => false, 'products' => false]);
        $this->mockEsIndex('broken')
            ->shouldReceive('delete')
            ->andThrow(new class('delete failed') extends \RuntimeException implements ElasticsearchException {})
        ;
        $this->mockEsIndex('products')->shouldReceive('delete')->once();

        $result = $this->cleanupService->cleanUp();

        $this->assertSame(['products'], $result->getDeletedIndices());
        $this->assertSame(['broken' => 'delete failed'], $result->getErrors());
        $this->assertTrue($result->hasErrors());
    }

    /**
     * @param array<string,bool> $indices index name => uses blue/green indices
     */
    private function mockKnownIndices(array $indices): void
    {
        $indexConfigs = [];

        foreach ($indices as $name => $blueGreen) {
            $indexConfig = \Mockery::mock(IndexInterface::class);
            $indexConfig->shouldReceive('getName')->andReturn($name);
            $indexConfig->shouldReceive('usesBlueGreenIndices')->andReturn($blueGreen);

            if ($blueGreen) {
                $indexConfig->shouldReceive('getBlueGreenActiveElasticaIndex->getName')->andReturn($name . '--blue');
                $indexConfig->shouldReceive('getBlueGreenInactiveElasticaIndex->getName')->andReturn($name . '--green');
            }

            $indexConfigs[$name] = $indexConfig;
        }

        $this->indexRepository
            ->shouldReceive('flattenedAll')
            ->andReturnUsing(static fn (): \Generator => yield from $indexConfigs)
        ;
    }

    /**
     * @param string[] $aliases
     */
    private function mockEsIndex(string $name, array $aliases = [], bool $hidden = false): Index&MockInterface
    {
        $settings = \Mockery::mock(Settings::class);
        $settings->shouldReceive('getBool')->with('hidden')->andReturn($hidden);

        $index = \Mockery::mock(Index::class);
        $index->shouldReceive('getSettings')->andReturn($settings);
        $index->shouldReceive('getAliases')->andReturn($aliases);

        $this->esClient->shouldReceive('getIndex')->with($name)->andReturn($index);

        return $index;
    }
}
