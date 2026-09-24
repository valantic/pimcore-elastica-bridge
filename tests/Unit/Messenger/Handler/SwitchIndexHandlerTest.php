<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Messenger\Handler;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Pimcore\Helper\LongRunningHelper;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Valantic\ElasticaBridgeBundle\Exception\Index\SwitchIndexException;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Messenger\Handler\SwitchIndexHandler;
use Valantic\ElasticaBridgeBundle\Messenger\Message\ReleaseIndexLock;
use Valantic\ElasticaBridgeBundle\Messenger\Message\SwitchIndex;
use Valantic\ElasticaBridgeBundle\Model\Event\ElasticaBridgeEvents;
use Valantic\ElasticaBridgeBundle\Model\Event\PostSwitchIndexEvent;
use Valantic\ElasticaBridgeBundle\Model\Event\PreSwitchIndexEvent;
use Valantic\ElasticaBridgeBundle\Model\Event\WaitForCompletionEvent;
use Valantic\ElasticaBridgeBundle\Repository\ConfigurationRepository;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;
use Valantic\ElasticaBridgeBundle\Service\LockService;
use Valantic\ElasticaBridgeBundle\Service\PopulateIndexService;

class SwitchIndexHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private LockFactory $lockFactory;
    private LockService $lockService;
    private PopulateIndexService&MockInterface $populateIndexService;
    private MessageBusInterface&MockInterface $bus;
    private EventDispatcher $eventDispatcher;
    private SwitchIndexHandler $handler;
    private ?KernelInterface $previousKernel;

    /**
     * @var list<int> remaining message counts reported by consecutive WAIT_FOR_COMPLETION_EVENT dispatches
     */
    private array $remainingMessages = [0];

    protected function setUp(): void
    {
        parent::setUp();

        // The handler calls \Pimcore::collectGarbage(), which needs a kernel.
        $this->previousKernel = \Pimcore::getKernel();
        $container = \Mockery::mock(ContainerInterface::class);
        $container->shouldReceive('get')->with(LongRunningHelper::class)->andReturn(\Mockery::spy());
        $kernel = \Mockery::mock(KernelInterface::class);
        $kernel->shouldReceive('getContainer')->andReturn($container);
        \Pimcore::setKernel($kernel);

        $index = \Mockery::mock(IndexInterface::class);
        $index->shouldReceive('getName')->andReturn('products');
        $indexRepository = \Mockery::mock(IndexRepository::class);
        $indexRepository->shouldReceive('flattenedGet')->with('products')->andReturn($index);

        $configurationRepository = \Mockery::mock(ConfigurationRepository::class);
        $configurationRepository->shouldReceive('getIndexingLockTimeout')->andReturn(300);
        $configurationRepository->shouldReceive('getCooldown')->andReturn(60);

        $consoleOutput = \Mockery::spy(ConsoleOutputInterface::class);
        $this->lockFactory = new LockFactory(new InMemoryStore());
        $this->lockService = new LockService($this->lockFactory, $configurationRepository, $consoleOutput);
        $this->populateIndexService = \Mockery::mock(PopulateIndexService::class);
        $this->populateIndexService->shouldReceive('log')->byDefault();
        $this->bus = \Mockery::mock(MessageBusInterface::class);

        $this->eventDispatcher = new EventDispatcher();
        $this->eventDispatcher->addListener(ElasticaBridgeEvents::WAIT_FOR_COMPLETION_EVENT, function (WaitForCompletionEvent $event): void {
            $event->setSleepDuration(0);
            $event->setRemainingMessages(count($this->remainingMessages) > 1 ? array_shift($this->remainingMessages) : $this->remainingMessages[0]);
        });

        $this->handler = new SwitchIndexHandler(
            $this->lockFactory,
            $this->lockService,
            $consoleOutput,
            $this->populateIndexService,
            $this->eventDispatcher,
            $this->bus,
            $indexRepository,
        );
    }

    protected function tearDown(): void
    {
        if ($this->previousKernel instanceof KernelInterface) {
            \Pimcore::setKernel($this->previousKernel);
        }

        parent::tearDown();
    }

    public function testSwitchesIndexAndInitiatesCooldown(): void
    {
        $postSwitchEvents = $this->collectEvents(ElasticaBridgeEvents::POST_SWITCH_INDEX);
        $this->populateIndexService->shouldReceive('switchBlueGreenIndex')->once()->with('products');

        ($this->handler)(new SwitchIndex('products', cooldown: true));

        $this->assertCount(1, $postSwitchEvents);
        $this->assertInstanceOf(PostSwitchIndexEvent::class, $postSwitchEvents[0]);
        $this->assertFalse($this->lockService->createLockFromKey($this->lockService->getKey('products', 'cooldown'))->acquire(), 'cooldown lock should be held');
    }

    public function testDoesNotInitiateCooldownWhenDisabled(): void
    {
        $this->populateIndexService->shouldReceive('switchBlueGreenIndex')->once()->with('products');

        ($this->handler)(new SwitchIndex('products', cooldown: false));

        $this->assertTrue($this->lockService->createLockFromKey($this->lockService->getKey('products', 'cooldown'))->acquire(), 'cooldown lock should be free');
    }

    public function testPreSwitchListenerCanDisableCooldown(): void
    {
        $this->eventDispatcher->addListener(ElasticaBridgeEvents::PRE_SWITCH_INDEX, static function (PreSwitchIndexEvent $event): void {
            $event->initiateCooldown = false;
        });
        $this->populateIndexService->shouldReceive('switchBlueGreenIndex')->once();

        ($this->handler)(new SwitchIndex('products', cooldown: true));

        $this->assertTrue($this->lockService->createLockFromKey($this->lockService->getKey('products', 'cooldown'))->acquire());
    }

    public function testWaitsUntilAllMessagesAreProcessed(): void
    {
        $this->remainingMessages = [5, 2, 0];
        $this->populateIndexService->shouldReceive('switchBlueGreenIndex')->once()->with('products');

        ($this->handler)(new SwitchIndex('products'));
    }

    public function testReschedulesWhenMessagesRemainAfterMaximumRetries(): void
    {
        $this->remainingMessages = [3];
        $this->populateIndexService->shouldNotReceive('switchBlueGreenIndex');
        $postSwitchEvents = $this->collectEvents(ElasticaBridgeEvents::POST_SWITCH_INDEX);

        $this->bus
            ->shouldReceive('dispatch')
            ->once()
            ->withArgs(static fn (object $message, array $stamps): bool => $message instanceof SwitchIndex
                && $message->indexName === 'products'
                && $message->retries === 1
                && $stamps[0] instanceof DelayStamp
                && $stamps[0]->getDelay() === 60_000)
            ->andReturnUsing(static fn (object $message): Envelope => new Envelope($message))
        ;

        ($this->handler)(new SwitchIndex('products'));

        $this->assertCount(0, $postSwitchEvents);
    }

    public function testFailsWhenListenerSkipsSwitch(): void
    {
        $this->eventDispatcher->addListener(ElasticaBridgeEvents::WAIT_FOR_COMPLETION_EVENT, static function (WaitForCompletionEvent $event): void {
            $event->skipSwitch();
        });
        $this->populateIndexService->shouldNotReceive('switchBlueGreenIndex');
        $this->populateIndexService->shouldReceive('log')->once()->with('products', \Mockery::pattern('/^Switch failed:/'));

        $this->expectException(SwitchIndexException::class);

        ($this->handler)(new SwitchIndex('products'));
    }

    public function testFailsWhenRemainingMessagesAreNegative(): void
    {
        $this->remainingMessages = [-1];
        $this->populateIndexService->shouldNotReceive('switchBlueGreenIndex');

        $this->expectException(SwitchIndexException::class);

        ($this->handler)(new SwitchIndex('products'));
    }

    public function testReleasesIndexingLock(): void
    {
        $key = $this->lockService->getKey('products', 'indexing');
        $lock = $this->lockFactory->createLockFromKey($key, autoRelease: false);
        $this->assertTrue($lock->acquire());
        $this->assertFalse($this->lockFactory->createLock((string) $key)->acquire(), 'precondition: indexing lock is held');

        ($this->handler)(new ReleaseIndexLock('products', $key));

        $this->assertFalse($lock->isAcquired());
        $this->assertTrue($this->lockFactory->createLock((string) $key)->acquire(), 'indexing lock should be released');
    }

    public function testKeepsIndexingLockWhileMessagesRemain(): void
    {
        $this->remainingMessages = [3];
        $key = $this->lockService->getKey('products', 'indexing');
        $lock = $this->lockFactory->createLockFromKey($key, autoRelease: false);
        $this->assertTrue($lock->acquire());
        $this->bus
            ->shouldReceive('dispatch')
            ->once()
            ->withArgs(static fn (object $message): bool => $message instanceof ReleaseIndexLock && $message->retries === 1 && $message->key === $key)
            ->andReturnUsing(static fn (object $message): Envelope => new Envelope($message))
        ;

        ($this->handler)(new ReleaseIndexLock('products', $key));

        $this->assertFalse($this->lockFactory->createLock((string) $key)->acquire(), 'indexing lock should still be held');
    }

    public function testReleaseWithoutKeyDoesNothing(): void
    {
        $waitEvents = $this->collectEvents(ElasticaBridgeEvents::WAIT_FOR_COMPLETION_EVENT);

        ($this->handler)(new ReleaseIndexLock('products'));

        $this->assertCount(0, $waitEvents);
    }

    /**
     * @return \ArrayObject<int, object>
     */
    private function collectEvents(string $eventName): \ArrayObject
    {
        $events = new \ArrayObject();
        $this->eventDispatcher->addListener($eventName, static function (object $event) use ($events): void {
            $events[] = $event;
        });

        return $events;
    }
}
