<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Messenger\Handler;

use Elastica\Index;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Messenger\Handler\RefreshElementInIndexHandler;
use Valantic\ElasticaBridgeBundle\Messenger\Message\RefreshElementInIndex;
use Valantic\ElasticaBridgeBundle\Repository\ConfigurationRepository;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;
use Valantic\ElasticaBridgeBundle\Service\LockService;
use Valantic\ElasticaBridgeBundle\Service\PropagateChanges;
use Valantic\ElasticaBridgeBundle\Tests\Helpers\StubDataObject;

class RefreshElementInIndexHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private LockService $lockService;
    private PropagateChanges&MockInterface $propagateChanges;
    private IndexInterface&MockInterface $index;
    private Index $inactiveIndex;
    private RefreshElementInIndexHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        StubDataObject::$existingIds = [42];

        $configurationRepository = \Mockery::mock(ConfigurationRepository::class);
        $configurationRepository->shouldReceive('getIndexingLockTimeout')->andReturn(300);

        $consoleOutput = \Mockery::spy(ConsoleOutputInterface::class);
        $this->lockService = new LockService(new LockFactory(new InMemoryStore()), $configurationRepository, $consoleOutput);

        $this->inactiveIndex = \Mockery::mock(Index::class);
        $this->index = \Mockery::mock(IndexInterface::class);
        $this->index->shouldReceive('getName')->andReturn('products');
        $this->index->shouldReceive('getBlueGreenInactiveElasticaIndex')->andReturn($this->inactiveIndex);

        $indexRepository = \Mockery::mock(IndexRepository::class);
        $indexRepository->shouldReceive('flattenedGet')->with('products')->andReturn($this->index);

        $this->propagateChanges = \Mockery::mock(PropagateChanges::class);

        $this->handler = new RefreshElementInIndexHandler(
            $this->propagateChanges,
            $this->lockService,
            $indexRepository,
            $consoleOutput,
        );
    }

    public function testUpdatesOnlyActiveIndexWhenNotPopulating(): void
    {
        $this->index->shouldReceive('usesBlueGreenIndices')->andReturn(true);

        $this->propagateChanges->shouldReceive('handleIndex')->once()->with(\Mockery::type(StubDataObject::class), $this->index);

        ($this->handler)($this->createMessage());

        $this->assertFalse($this->lockService->isIndexingLocked($this->index));
    }

    public function testAlsoUpdatesInactiveIndexWhilePopulating(): void
    {
        $this->index->shouldReceive('usesBlueGreenIndices')->andReturn(true);

        $populationLock = $this->lockService->getIndexingLock($this->index);
        $this->assertTrue($populationLock->acquire());

        $this->propagateChanges->shouldReceive('handleIndex')->once()->with(\Mockery::type(StubDataObject::class), $this->index, $this->inactiveIndex);
        $this->propagateChanges->shouldReceive('handleIndex')->once()->with(\Mockery::type(StubDataObject::class), $this->index);

        ($this->handler)($this->createMessage());

        // the handler must not release the population's lock
        $this->assertTrue($populationLock->isAcquired());
        $this->assertTrue($this->lockService->isIndexingLocked($this->index));
    }

    public function testIgnoresLockWithoutBlueGreenIndices(): void
    {
        $this->index->shouldReceive('usesBlueGreenIndices')->andReturn(false);

        $this->assertTrue($this->lockService->getIndexingLock($this->index)->acquire());

        $this->propagateChanges->shouldReceive('handleIndex')->once()->with(\Mockery::type(StubDataObject::class), $this->index);

        ($this->handler)($this->createMessage());
    }

    private function createMessage(): RefreshElementInIndex
    {
        $element = new StubDataObject();
        $element->setId(42);

        return new RefreshElementInIndex($element, 'products');
    }
}
