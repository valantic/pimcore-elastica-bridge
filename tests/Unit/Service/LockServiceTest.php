<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Service;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Repository\ConfigurationRepository;
use Valantic\ElasticaBridgeBundle\Service\LockService;

class LockServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private LockFactory $lockFactory;
    private ConfigurationRepository $configurationRepository;
    private LockService $lockService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lockFactory = \Mockery::mock(LockFactory::class);
        $this->configurationRepository = \Mockery::mock(ConfigurationRepository::class);

        $this->lockService = new LockService(
            $this->lockFactory,
            $this->configurationRepository,
        );
    }

    public function testGetIndexingLockCreatesLockWithCorrectName(): void
    {
        $index = \Mockery::mock(IndexInterface::class);
        $index->shouldReceive('getName')->andReturn('test_index');

        $lock = \Mockery::mock(SharedLockInterface::class);

        $this->configurationRepository
            ->shouldReceive('getIndexingLockTimeout')
            ->once()
            ->andReturn(300.0)
        ;

        $this->lockFactory
            ->shouldReceive('createLock')
            ->once()
            ->with('pimcore-elastica-bridge:indexing:test_index', 300.0)
            ->andReturn($lock)
        ;

        $result = $this->lockService->getIndexingLock($index);

        $this->assertSame($lock, $result);
    }

    public function testGetIndexingLockUsesTtlFromConfiguration(): void
    {
        $index = \Mockery::mock(IndexInterface::class);
        $index->shouldReceive('getName')->andReturn('another_index');

        $lock = \Mockery::mock(SharedLockInterface::class);

        $this->configurationRepository
            ->shouldReceive('getIndexingLockTimeout')
            ->once()
            ->andReturn(600.0)
        ;

        $this->lockFactory
            ->shouldReceive('createLock')
            ->once()
            ->with('pimcore-elastica-bridge:indexing:another_index', 600.0)
            ->andReturn($lock)
        ;

        $result = $this->lockService->getIndexingLock($index);

        $this->assertInstanceOf(SharedLockInterface::class, $result);
    }
}
