<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Messenger\Handler;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Valantic\ElasticaBridgeBundle\Exception\Index\PopulationNotStartedException;
use Valantic\ElasticaBridgeBundle\Messenger\Handler\PopulateHandler;
use Valantic\ElasticaBridgeBundle\Messenger\Message\CreateDocumentMessage;
use Valantic\ElasticaBridgeBundle\Messenger\Message\PopulateIndexMessage;
use Valantic\ElasticaBridgeBundle\Messenger\Message\ReleaseIndexLock;
use Valantic\ElasticaBridgeBundle\Messenger\Message\SwitchIndex;
use Valantic\ElasticaBridgeBundle\Messenger\Message\TriggerSingleIndexMessage;
use Valantic\ElasticaBridgeBundle\Repository\ConfigurationRepository;
use Valantic\ElasticaBridgeBundle\Service\LockService;
use Valantic\ElasticaBridgeBundle\Service\PopulateIndexService;

class PopulateHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private LockService $lockService;
    private LockFactory $lockFactory;
    private PopulateIndexService&MockInterface $populateIndexService;
    private MessageBusInterface&MockInterface $bus;
    private PopulateHandler $handler;

    /**
     * @var list<object>
     */
    private array $dispatched = [];

    protected function setUp(): void
    {
        parent::setUp();

        $configurationRepository = \Mockery::mock(ConfigurationRepository::class);
        $configurationRepository->shouldReceive('getIndexingLockTimeout')->andReturn(300);
        $configurationRepository->shouldReceive('getCooldown')->andReturn(60);

        $this->lockFactory = new LockFactory(new InMemoryStore());
        $this->lockService = new LockService($this->lockFactory, $configurationRepository, \Mockery::spy(ConsoleOutputInterface::class));
        $this->populateIndexService = \Mockery::mock(PopulateIndexService::class);
        $this->bus = \Mockery::mock(MessageBusInterface::class);
        $this->bus->shouldReceive('dispatch')->andReturnUsing(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        $this->handler = new PopulateHandler($this->bus, $this->populateIndexService, $this->lockService);
    }

    public function testUnwrapsPopulateIndexMessage(): void
    {
        $inner = new SwitchIndex('products');

        ($this->handler)(new PopulateIndexMessage($inner), synchronous: false);

        $this->assertSame([$inner], $this->dispatched);
    }

    public function testDispatchesGeneratedMessagesAndReleasesQueueLock(): void
    {
        [$key, $queueLock] = $this->acquireQueueLock();
        $messages = [
            new CreateDocumentMessage(1, \stdClass::class, 'product_document', 'products'),
            new SwitchIndex('products'),
            new ReleaseIndexLock('products'),
        ];
        $this->populateIndexService
            ->shouldReceive('triggerSingleIndex')
            ->once()
            ->with('products', true, false, true)
            ->andReturnUsing(static function () use ($messages): \Generator {
                foreach ($messages as $message) {
                    yield new PopulateIndexMessage($message);
                }
            })
        ;

        ($this->handler)(new TriggerSingleIndexMessage('products', populate: true, ignoreCooldown: true, ignoreLock: false, key: $key), synchronous: false);

        $this->assertSame($messages, $this->dispatched);
        $this->assertFalse($queueLock->isAcquired());
    }

    public function testRefusesToRunSynchronouslyAndReleasesQueueLock(): void
    {
        [$key, $queueLock] = $this->acquireQueueLock();
        $this->populateIndexService->shouldNotReceive('triggerSingleIndex');

        try {
            ($this->handler)(new TriggerSingleIndexMessage('products', true, false, false, $key), synchronous: true);
            $this->fail('Expected PopulationNotStartedException');
        } catch (PopulationNotStartedException $exception) {
            $this->assertSame(PopulationNotStartedException::TYPE_NOT_AVAILABLE_IN_SYNC, $exception->getType());
        }

        $this->assertSame([], $this->dispatched);
        $this->assertFalse($queueLock->isAcquired());
    }

    public function testReleasesQueueLockWhenPopulationCannotStart(): void
    {
        [$key, $queueLock] = $this->acquireQueueLock();
        $this->populateIndexService
            ->shouldReceive('triggerSingleIndex')
            ->andReturnUsing(static function (): \Generator {
                yield from [];

                throw new PopulationNotStartedException(PopulationNotStartedException::TYPE_COOLDOWN);
            })
        ;

        $this->expectException(PopulationNotStartedException::class);

        try {
            ($this->handler)(new TriggerSingleIndexMessage('products', true, false, false, $key), synchronous: false);
        } finally {
            $this->assertFalse($queueLock->isAcquired());
        }
    }

    /**
     * @return array{Key, LockInterface}
     */
    private function acquireQueueLock(): array
    {
        $key = $this->lockService->getKey('products', 'queue');
        $lock = $this->lockFactory->createLockFromKey($key, autoRelease: false);
        $this->assertTrue($lock->acquire());

        return [$key, $lock];
    }
}
