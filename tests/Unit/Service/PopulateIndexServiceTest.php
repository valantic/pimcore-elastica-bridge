<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Service;

use Elastica\Index;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset\Image;
use Pimcore\Model\Asset\Video;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;
use Valantic\ElasticaBridgeBundle\Enum\IndexBlueGreenSuffix;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Repository\DocumentRepository;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;
use Valantic\ElasticaBridgeBundle\Service\DocumentHelper;
use Valantic\ElasticaBridgeBundle\Service\LockService;
use Valantic\ElasticaBridgeBundle\Service\PopulateIndexService;

class PopulateIndexServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const BULK_SETTINGS = ['refresh_interval' => '-1', 'number_of_replicas' => 0];
    private const POST_SETTINGS = ['refresh_interval' => '1s', 'number_of_replicas' => 1];

    private IndexRepository&MockInterface $indexRepository;
    private ElasticsearchClient&MockInterface $esClient;
    private PopulateIndexService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->indexRepository = \Mockery::mock(IndexRepository::class);
        $this->esClient = \Mockery::mock(ElasticsearchClient::class);
        // Indices touched by ensureCorrectIndexSetup() are irrelevant to these tests.
        $this->esClient->shouldReceive('getIndex')->andReturn(\Mockery::spy(Index::class))->byDefault();

        $this->service = new PopulateIndexService(
            $this->indexRepository,
            $this->esClient,
            \Mockery::mock(LockService::class),
            \Mockery::mock(DocumentRepository::class),
            \Mockery::mock(DocumentHelper::class),
            \Mockery::mock(EventDispatcherInterface::class),
            \Mockery::mock(MessageBusInterface::class),
            \Mockery::spy(ConsoleOutputInterface::class),
        );
    }

    public function testSwitchRestoresSettingsOnNewIndexBeforeMovingAlias(): void
    {
        $oldIndex = $this->mockIndex('products--blue');
        $newIndex = $this->mockIndex('products--green');
        $indexConfig = $this->mockIndexConfig(blueGreen: true, active: $oldIndex, inactive: $newIndex);
        $this->indexRepository->shouldReceive('flattenedGet')->with('products')->andReturn($indexConfig);

        $newIndex->shouldReceive('setSettings')->once()->with(self::POST_SETTINGS)->ordered();
        $newIndex->shouldReceive('refresh')->once()->ordered();
        $oldIndex->shouldReceive('removeAlias')->once()->with('products')->ordered();
        $newIndex->shouldReceive('addAlias')->once()->with('products')->ordered();
        $oldIndex->shouldNotReceive('setSettings');

        $this->service->switchBlueGreenIndex('products');
    }

    public function testSetupAppliesBulkSettingsToRecreatedInactiveIndex(): void
    {
        $inactiveIndex = $this->mockIndex('products--green');
        $indexConfig = $this->mockIndexConfig(blueGreen: true, inactive: $inactiveIndex);

        $inactiveIndex->shouldReceive('delete')->once()->ordered();
        $inactiveIndex->shouldReceive('create')->once()->ordered();
        $inactiveIndex->shouldReceive('setSettings')->once()->with(self::BULK_SETTINGS)->ordered();

        $this->service->setupIndex($indexConfig);
    }

    public function testSetupDoesNotApplyBulkSettingsToNonBlueGreenIndex(): void
    {
        $liveIndex = \Mockery::mock(Index::class)->shouldIgnoreMissing();
        $liveIndex->shouldReceive('exists')->andReturn(true);
        $liveIndex->shouldNotReceive('setSettings');
        $indexConfig = $this->mockIndexConfig(blueGreen: false);
        $indexConfig->shouldReceive('getElasticaIndex')->andReturn($liveIndex);
        $this->esClient->shouldReceive('getIndex')->with('products')->andReturn($liveIndex);

        $this->service->setupIndex($indexConfig);
    }

    public function testPostPopulateOnlyRefreshesNonBlueGreenIndex(): void
    {
        $liveIndex = \Mockery::mock(Index::class);
        $liveIndex->shouldReceive('refresh')->once();
        $liveIndex->shouldNotReceive('setSettings');
        $this->esClient->shouldReceive('getIndex')->with('products')->andReturn($liveIndex);

        $this->service->postPopulateIndex($this->mockIndexConfig(blueGreen: false));
    }

    public function testChunkIdsByTypeNeverMixesElementTypes(): void
    {
        $chunks = $this->chunkIdsByType([
            1 => Image::class,
            2 => Video::class,
            3 => Image::class,
            4 => Image::class,
            5 => Video::class,
            6 => Image::class,
        ], 2);

        $this->assertSame([
            [Image::class, [1, 3]],
            [Video::class, [2, 5]],
            [Image::class, [4, 6]],
        ], $chunks);
    }

    public function testChunkIdsByTypeFlushesPartialChunks(): void
    {
        $chunks = $this->chunkIdsByType([
            1 => Image::class,
            2 => Image::class,
            3 => Image::class,
            4 => Video::class,
        ], 2);

        $this->assertSame([
            [Image::class, [1, 2]],
            [Image::class, [3]],
            [Video::class, [4]],
        ], $chunks);
    }

    public function testChunkIdsByTypeYieldsNothingForNoElements(): void
    {
        $this->assertSame([], $this->chunkIdsByType([], 50));
    }

    /**
     * @param array<int, class-string> $elementTypes
     *
     * @return list<array{class-string, int[]}>
     */
    private function chunkIdsByType(array $elementTypes, int $size): array
    {
        $method = new \ReflectionMethod(PopulateIndexService::class, 'chunkIdsByType');

        return iterator_to_array($method->invoke(null, $elementTypes, $size), false);
    }

    private function mockIndex(string $name): Index&MockInterface
    {
        $index = \Mockery::mock(Index::class);
        $index->shouldReceive('getName')->andReturn($name);
        $index->shouldReceive('flush')->byDefault();

        return $index;
    }

    private function mockIndexConfig(bool $blueGreen, ?Index $active = null, ?Index $inactive = null): IndexInterface&MockInterface
    {
        $indexConfig = \Mockery::mock(IndexInterface::class);
        $indexConfig->shouldReceive('getName')->andReturn('products');
        $indexConfig->shouldReceive('usesBlueGreenIndices')->andReturn($blueGreen);
        $indexConfig->shouldReceive('getCreateArguments')->andReturn([]);
        $indexConfig->shouldReceive('getBlueGreenActiveSuffix')->andReturn(IndexBlueGreenSuffix::BLUE);
        $indexConfig->shouldReceive('getBulkIndexingSettings')->andReturn(self::BULK_SETTINGS);
        $indexConfig->shouldReceive('getPostBulkIndexingSettings')->andReturn(self::POST_SETTINGS);

        if ($active instanceof Index) {
            $indexConfig->shouldReceive('getBlueGreenActiveElasticaIndex')->andReturn($active);
        }

        if ($inactive instanceof Index) {
            $indexConfig->shouldReceive('getBlueGreenInactiveElasticaIndex')->andReturn($inactive);
        }

        return $indexConfig;
    }
}
