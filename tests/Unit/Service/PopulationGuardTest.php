<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Service;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Valantic\ElasticaBridgeBundle\Exception\Index\PopulationNotStartedException;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Model\Event\ElasticaBridgeEvents;
use Valantic\ElasticaBridgeBundle\Model\Event\PreSwitchIndexEvent;
use Valantic\ElasticaBridgeBundle\Repository\ConfigurationRepository;
use Valantic\ElasticaBridgeBundle\Service\LockService;
use Valantic\ElasticaBridgeBundle\Service\PopulationGuard;

class PopulationGuardTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private InMemoryStore $lockStore;

    private EventDispatcher $eventDispatcher;

    private IndexInterface&MockInterface $index;

    private PopulationGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lockStore = new InMemoryStore();
        $this->eventDispatcher = new EventDispatcher();
        $this->index = \Mockery::mock(IndexInterface::class);
        $this->index->shouldReceive('getName')->andReturn('products');
        $this->guard = new PopulationGuard($this->createLockService(), $this->eventDispatcher);
    }

    public function testAllowsPopulationAndKeepsTheProcessingLock(): void
    {
        $this->guard->assertCanStart($this->index, 10);

        $this->assertFalse($this->otherProcess()->getIndexingLock($this->index)->acquire(), 'processing lock should be held');
    }

    public function testReleasesTheProcessingLockAutomaticallyWhenNotKept(): void
    {
        $this->guard->assertCanStart($this->index, 10, keepProcessingLock: false);

        // an autorelease lock is released when the guard's lock object goes out of scope
        $this->assertTrue($this->otherProcess()->getIndexingLock($this->index)->acquire());
    }

    public function testDoesNotKeepTheCooldownLockOnSuccess(): void
    {
        $this->guard->assertCanStart($this->index, 10);

        $this->assertTrue($this->otherProcess()->createLockFromKey($this->otherProcess()->getKey('products', 'cooldown'))->acquire(), 'cooldown should not be active');
    }

    public function testRefusesWithoutDocuments(): void
    {
        $this->assertNotStarted(PopulationNotStartedException::TYPE_NO_DOCUMENTS, fn () => $this->guard->assertCanStart($this->index, 0));
    }

    public function testRefusesDuringCooldown(): void
    {
        $this->otherProcess()->initiateCooldown('products');

        $this->assertNotStarted(PopulationNotStartedException::TYPE_COOLDOWN, fn () => $this->guard->assertCanStart($this->index, 10));
    }

    public function testIgnoresCooldownWhenRequested(): void
    {
        $this->otherProcess()->initiateCooldown('products');

        $this->guard->assertCanStart($this->index, 10, ignoreCooldown: true);

        $this->addToAssertionCount(1);
    }

    public function testRefusesWhileAnotherProcessHoldsTheProcessingLock(): void
    {
        $this->assertTrue($this->otherProcess()->getIndexingLock($this->index)->acquire());

        $this->assertNotStarted(PopulationNotStartedException::TYPE_PROCESSING, fn () => $this->guard->assertCanStart($this->index, 10));
    }

    public function testIgnoresTheProcessingLockWhenRequested(): void
    {
        $this->assertTrue($this->otherProcess()->getIndexingLock($this->index)->acquire());

        $this->guard->assertCanStart($this->index, 10, ignoreLock: true);

        $this->addToAssertionCount(1);
    }

    public function testRefusesWhileMessagesArePending(): void
    {
        $this->pendingMessages(3);

        $this->assertNotStarted(PopulationNotStartedException::TYPE_PROCESSING_MESSAGES, fn () => $this->guard->assertCanStart($this->index, 10));
    }

    public function testDoesNotLeaveCooldownActiveWhenMessagesArePending(): void
    {
        $this->pendingMessages(3);

        $this->assertNotStarted(PopulationNotStartedException::TYPE_PROCESSING_MESSAGES, fn () => $this->guard->assertCanStart($this->index, 10));

        $this->assertTrue($this->otherProcess()->createLockFromKey($this->otherProcess()->getKey('products', 'cooldown'))->acquire(), 'cooldown should not be active');
    }

    public function testChecksPendingMessagesBeforeRefusingWithoutDocuments(): void
    {
        $dispatched = 0;
        $this->eventDispatcher->addListener(ElasticaBridgeEvents::PRE_SWITCH_INDEX, static function () use (&$dispatched): void {
            $dispatched++;
        });

        $this->assertNotStarted(PopulationNotStartedException::TYPE_NO_DOCUMENTS, fn () => $this->guard->assertCanStart($this->index, 0));

        $this->assertSame(1, $dispatched);
    }

    public function testQueueLockIsIgnoredByDefault(): void
    {
        $this->assertTrue($this->otherProcess()->createLockFromKey($this->otherProcess()->getKey('products', 'queue'))->acquire());

        $this->guard->assertCanStart($this->index, 10);

        $this->addToAssertionCount(1);
    }

    public function testRefusesWhileTheQueueLockIsHeldIfNotIgnored(): void
    {
        $this->assertTrue($this->otherProcess()->createLockFromKey($this->otherProcess()->getKey('products', 'queue'))->acquire());

        $this->assertNotStarted(PopulationNotStartedException::TYPE_PROCESSING, fn () => $this->guard->assertCanStart($this->index, 10, ignoreQueueLock: false));
    }

    public function testKeepsTheQueueLockOnSuccess(): void
    {
        $this->guard->assertCanStart($this->index, 10, ignoreQueueLock: false);

        $this->assertFalse($this->otherProcess()->createLockFromKey($this->otherProcess()->getKey('products', 'queue'))->acquire(), 'queue lock should be held');
    }

    public function testReleasesTheQueueLockWhenPopulationCannotStart(): void
    {
        $this->assertTrue($this->otherProcess()->getIndexingLock($this->index)->acquire());

        $this->assertNotStarted(PopulationNotStartedException::TYPE_PROCESSING, fn () => $this->guard->assertCanStart($this->index, 10, ignoreQueueLock: false));

        $this->assertTrue($this->otherProcess()->createLockFromKey($this->otherProcess()->getKey('products', 'queue'))->acquire(), 'queue lock should have been released');
    }

    /**
     * A separate LockService on the same store, i.e. another process.
     */
    private function otherProcess(): LockService
    {
        return $this->createLockService();
    }

    private function createLockService(): LockService
    {
        $configurationRepository = \Mockery::mock(ConfigurationRepository::class);
        $configurationRepository->shouldReceive('getIndexingLockTimeout')->andReturn(300);
        $configurationRepository->shouldReceive('getCooldown')->andReturn(60);

        return new LockService(new LockFactory($this->lockStore), $configurationRepository, \Mockery::spy(ConsoleOutputInterface::class));
    }

    private function pendingMessages(int $remaining): void
    {
        $this->eventDispatcher->addListener(ElasticaBridgeEvents::PRE_SWITCH_INDEX, static function (PreSwitchIndexEvent $event) use ($remaining): void {
            $event->setRemainingMessages($remaining);
        });
    }

    /**
     * @param callable(): mixed $callback
     */
    private function assertNotStarted(string $type, callable $callback): void
    {
        try {
            $callback();
        } catch (PopulationNotStartedException $exception) {
            $this->assertSame($type, $exception->getType());

            return;
        }

        $this->fail(sprintf('Expected PopulationNotStartedException (%s)', $type));
    }
}
