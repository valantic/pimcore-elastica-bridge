<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Service;

use Elastica\Index;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;
use Valantic\ElasticaBridgeBundle\Enum\IndexBlueGreenSuffix;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Service\IndexSetupService;
use Valantic\ElasticaBridgeBundle\Service\PopulateLogger;

class IndexSetupServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const array CREATE_ARGUMENTS = ['settings' => ['number_of_shards' => 1]];

    private ElasticsearchClient&MockInterface $esClient;

    private IndexInterface&MockInterface $indexConfig;

    private PopulateLogger $logger;

    private IndexSetupService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->esClient = \Mockery::mock(ElasticsearchClient::class);
        $this->indexConfig = \Mockery::mock(IndexInterface::class);
        $this->indexConfig->shouldReceive('getName')->andReturn('products');
        $this->indexConfig->shouldReceive('getCreateArguments')->andReturn(self::CREATE_ARGUMENTS);
        $this->logger = new PopulateLogger(\Mockery::spy(ConsoleOutputInterface::class));
        $this->service = new IndexSetupService($this->esClient, $this->logger);
    }

    public function testCreatesMissingSimpleIndex(): void
    {
        $index = $this->simpleIndex();
        $index->shouldReceive('exists')->andReturn(false);
        $index->shouldReceive('create')->once()->with(self::CREATE_ARGUMENTS);
        $index->shouldNotReceive('delete');

        $this->service->setupIndex($this->indexConfig);

        $this->assertSame(['products: <comment>Created index</comment>'], $this->logger->getLog());
    }

    public function testKeepsExistingSimpleIndex(): void
    {
        $index = $this->simpleIndex();
        $index->shouldReceive('exists')->andReturn(true);
        $index->shouldNotReceive('create');
        $index->shouldNotReceive('delete');

        $this->service->setupIndex($this->indexConfig);

        $this->assertSame([], $this->logger->getLog());
    }

    public function testRecreatesExistingSimpleIndexWhenDeletionIsRequested(): void
    {
        $index = $this->simpleIndex();
        $index->shouldReceive('exists')->andReturn(true, false);
        $index->shouldReceive('delete')->once()->ordered();
        $index->shouldReceive('create')->once()->ordered()->with(self::CREATE_ARGUMENTS);

        $this->service->setupIndex($this->indexConfig, deleteExisting: true);

        $this->assertSame(['products: <comment>Deleted index</comment>', 'products: <comment>Created index</comment>'], $this->logger->getLog());
    }

    public function testSetupOfBlueGreenIndicesAlwaysRecreatesTheInactiveIndex(): void
    {
        [$blue, $green] = $this->blueGreenIndices();
        $blue->shouldReceive('exists')->andReturn(true);
        $green->shouldReceive('exists')->andReturn(true);
        $blue->shouldNotReceive('delete');
        $green->shouldNotReceive('delete');
        $this->indexConfig->shouldReceive('getBlueGreenActiveSuffix')->andReturn(IndexBlueGreenSuffix::BLUE);
        $inactive = \Mockery::mock(Index::class);
        $inactive->shouldReceive('delete')->once()->ordered();
        $inactive->shouldReceive('create')->once()->ordered()->with(self::CREATE_ARGUMENTS);
        $this->indexConfig->shouldReceive('getBlueGreenInactiveElasticaIndex')->andReturn($inactive);

        $this->service->setupIndex($this->indexConfig);

        $this->assertContains('products: <comment>Re-created inactive blue/green index</comment>', $this->logger->getLog());
    }

    public function testSwitchNeverDeletesExistingIndices(): void
    {
        [$blue, $green] = $this->blueGreenIndices();
        $blue->shouldReceive('exists')->andReturn(true);
        $green->shouldReceive('exists')->andReturn(true);
        $blue->shouldNotReceive('delete');
        $green->shouldNotReceive('delete');
        $blue->shouldNotReceive('create');
        $green->shouldNotReceive('create');
        $this->indexConfig->shouldReceive('getBlueGreenActiveSuffix')->andReturn(IndexBlueGreenSuffix::BLUE);
        $this->indexConfig->shouldReceive('getBlueGreenActiveElasticaIndex')->andReturn($blue);
        $this->indexConfig->shouldReceive('getBlueGreenInactiveElasticaIndex')->andReturn($green);

        $blue->shouldReceive('getName')->andReturn('products--blue');
        $green->shouldReceive('getName')->andReturn('products--green');
        $green->shouldReceive('flush')->once()->ordered('flush-new');
        $blue->shouldReceive('removeAlias')->once()->with('products')->ordered('alias');
        $green->shouldReceive('addAlias')->once()->with('products')->ordered('alias');
        $blue->shouldReceive('flush')->once();
        $green->shouldReceive('refresh')->once();
        $this->esClient->shouldReceive('getIndex')->with('products')->andReturn($this->missingIndex());

        $this->service->switchBlueGreenIndex($this->indexConfig);
    }

    public function testSwitchDoesNothingForSimpleIndices(): void
    {
        $index = $this->simpleIndex();
        $index->shouldReceive('exists')->andReturn(true);
        $index->shouldNotReceive('delete');
        $index->shouldNotReceive('addAlias');
        $index->shouldNotReceive('removeAlias');

        $this->service->switchBlueGreenIndex($this->indexConfig);
    }

    private function simpleIndex(): Index&MockInterface
    {
        $index = \Mockery::mock(Index::class);
        $this->indexConfig->shouldReceive('usesBlueGreenIndices')->andReturn(false);
        $this->indexConfig->shouldReceive('getElasticaIndex')->andReturn($index);

        return $index;
    }

    /**
     * @return array{Index&MockInterface, Index&MockInterface}
     */
    private function blueGreenIndices(): array
    {
        $this->indexConfig->shouldReceive('usesBlueGreenIndices')->andReturn(true);
        $blue = \Mockery::mock(Index::class);
        $green = \Mockery::mock(Index::class);
        $this->esClient->shouldReceive('getIndex')->with('products')->andReturn($this->missingIndex())->byDefault();
        $this->esClient->shouldReceive('getIndex')->with('products' . IndexBlueGreenSuffix::BLUE->value)->andReturn($blue);
        $this->esClient->shouldReceive('getIndex')->with('products' . IndexBlueGreenSuffix::GREEN->value)->andReturn($green);

        return [$blue, $green];
    }

    private function missingIndex(): Index&MockInterface
    {
        $index = \Mockery::mock(Index::class);
        $index->shouldReceive('exists')->andReturn(false);

        return $index;
    }
}
