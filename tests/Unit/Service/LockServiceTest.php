<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Service;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\InMemoryStore;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Repository\ConfigurationRepository;
use Valantic\ElasticaBridgeBundle\Service\LockService;

class LockServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private LockFactory $lockFactory;
    private ConfigurationRepository $configurationRepository;
    private LockService $lockService;
    private ?InMemoryStore $lockStore = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lockFactory = \Mockery::mock(LockFactory::class);
        $this->configurationRepository = \Mockery::mock(ConfigurationRepository::class);

        $this->lockService = new LockService(
            $this->lockFactory,
            $this->configurationRepository,
            \Mockery::mock(ConsoleOutputInterface::class),
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
            ->shouldReceive('createLockFromKey')
            ->once()
            ->with(\Mockery::on(static fn (Key $key): bool => (string) $key === 'pimcore-elastica-bridge:indexing:test_index'), 300.0, false)
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
            ->shouldReceive('createLockFromKey')
            ->once()
            ->with(\Mockery::on(static fn (Key $key): bool => (string) $key === 'pimcore-elastica-bridge:indexing:another_index'), 600.0, false)
            ->andReturn($lock)
        ;

        $result = $this->lockService->getIndexingLock($index);

        $this->assertInstanceOf(SharedLockInterface::class, $result);
    }

    public function testIndexingIsNotLockedWhenIdle(): void
    {
        $index = $this->createIndex();

        $this->assertFalse($this->createRealLockService()->isIndexingLocked($index));
        $this->assertTrue($this->createRealLockService()->getIndexingLock($index)->acquire(), 'checking must not keep the indexing lock');
    }

    public function testIndexingIsLockedWhileAnotherProcessHoldsTheLock(): void
    {
        $index = $this->createIndex();
        $this->assertTrue($this->createRealLockService()->getIndexingLock($index)->acquire());

        $this->assertTrue($this->createRealLockService()->isIndexingLocked($index));
    }

    public function testIndexingIsLockedWhileThisProcessHoldsTheLock(): void
    {
        $index = $this->createIndex();
        $lockService = $this->createRealLockService();
        $this->assertTrue($lockService->getIndexingLock($index)->acquire());

        $this->assertTrue($lockService->isIndexingLocked($index));
    }

    private function createIndex(): IndexInterface
    {
        $index = \Mockery::mock(IndexInterface::class);
        $index->shouldReceive('getName')->andReturn('products');

        return $index;
    }

    /**
     * A LockService on a shared in-memory store, i.e. a separate process for every instance.
     */
    private function createRealLockService(): LockService
    {
        $this->lockStore ??= new InMemoryStore();
        $configurationRepository = \Mockery::mock(ConfigurationRepository::class);
        $configurationRepository->shouldReceive('getIndexingLockTimeout')->andReturn(300);

        return new LockService(new LockFactory($this->lockStore), $configurationRepository, \Mockery::spy(ConsoleOutputInterface::class));
    }
}
