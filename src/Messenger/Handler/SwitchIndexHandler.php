<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Handler;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Valantic\ElasticaBridgeBundle\Exception\Index\SwitchIndexException;
use Valantic\ElasticaBridgeBundle\Messenger\Message\ReleaseIndexLock;
use Valantic\ElasticaBridgeBundle\Messenger\Message\SwitchIndex;
use Valantic\ElasticaBridgeBundle\Model\Event\ElasticaBridgeEvents;
use Valantic\ElasticaBridgeBundle\Model\Event\PostSwitchIndexEvent;
use Valantic\ElasticaBridgeBundle\Model\Event\PreSwitchIndexEvent;
use Valantic\ElasticaBridgeBundle\Model\Event\WaitForCompletionEvent;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;
use Valantic\ElasticaBridgeBundle\Service\LockService;
use Valantic\ElasticaBridgeBundle\Service\PopulateIndexService;

#[AsMessageHandler]
class SwitchIndexHandler
{
    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly LockService $lockService,
        private readonly ConsoleOutputInterface $consoleOutput,
        private readonly PopulateIndexService $populateIndexService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly MessageBusInterface $messengerBusElasticaBridge,
        private readonly IndexRepository $indexRepository,
    ) {}

    public function __invoke(ReleaseIndexLock|SwitchIndex $message): void
    {
        try {
            match ($message::class) {
                SwitchIndex::class => $this->switchIndex($message),
                ReleaseIndexLock::class => $this->releaseLock($message),
                default => throw new \RuntimeException(sprintf('Unknown message type %s', $message::class)),
            };
        } finally {
            \Pimcore::collectGarbage();
        }
    }

    private function waitForCompletion(ReleaseIndexLock|SwitchIndex $message): void
    {
        $index = $this->indexRepository->flattenedGet($message->indexName);

        $event = $this->eventDispatcher->dispatch(new WaitForCompletionEvent($index, 0, $message->retries), ElasticaBridgeEvents::WAIT_FOR_COMPLETION_EVENT);
        $retries = 0;

        if (!$event->isSuccess()) {
            throw new SwitchIndexException($message::class . ' failed');
        }

        while ($retries < $event->maximumRetries && $event->getRemainingMessages() !== 0) {
            sleep($event->getSleepDuration());
            $retries++;
            $event = $this->eventDispatcher->dispatch(new WaitForCompletionEvent($index, $retries, $message->retries), ElasticaBridgeEvents::WAIT_FOR_COMPLETION_EVENT);
            $this->populateIndexService->log($message->indexName, sprintf('Attempt %d. %d messages remaining.', $retries, $event->getRemainingMessages()));
        }

        if ($event->getRemainingMessages() > 0) {
            $delayStamp = new DelayStamp($event->rescheduleIntervalSeconds * 1000);
            $this->consoleOutput->writeln(sprintf('Max attempts reached, rescheduling in %s seconds', $delayStamp->getDelay() / 1000), ConsoleOutputInterface::VERBOSITY_VERBOSE);

            $this->messengerBusElasticaBridge->dispatch($message->retry(), [$delayStamp]);

            throw new RecoverableMessageHandlingException('Max attempts reached, rescheduling');
        }

        if ($event->getRemainingMessages() < 0) {
            $this->consoleOutput->writeln('Remaining messages is negative, skipping', ConsoleOutputInterface::VERBOSITY_QUIET);

            throw new SwitchIndexException('Remaining messages is negative, skipping');
        }
    }

    private function switchIndex(SwitchIndex $message): void
    {
        $index = $this->indexRepository->flattenedGet($message->indexName);
        $event = $this->eventDispatcher->dispatch(new PreSwitchIndexEvent($index, $message->cooldown), ElasticaBridgeEvents::PRE_SWITCH_INDEX);

        try {
            $this->waitForCompletion($message);
        } catch (RecoverableMessageHandlingException) {
            return;
        } catch (\Throwable $e) {
            $this->populateIndexService->log($message->indexName, sprintf('Switch failed: %s', $e->getMessage()));

            throw new SwitchIndexException('Switch failed', previous: $e);
        }

        $this->populateIndexService->switchBlueGreenIndex($message->indexName);

        if ($event->initiateCooldown) {
            $this->lockService->initiateCooldown($message->indexName);
        }

        $this->eventDispatcher->dispatch(new PostSwitchIndexEvent($index), ElasticaBridgeEvents::POST_SWITCH_INDEX);

    }

    private function releaseLock(ReleaseIndexLock $message): void
    {
        if ($message->key instanceof Key) {
            try {
                $this->waitForCompletion($message);
            } catch (RecoverableMessageHandlingException) {
                return;
            } catch (\Throwable $e) {
                $this->populateIndexService->log($message->indexName, sprintf('Release failed: %s', $e->getMessage()));

                throw new SwitchIndexException('Release failed', previous: $e);
            }

            $this->consoleOutput->writeln(sprintf('releasing lock %s (%s)', $message->key, hash('sha256', (string) $message->key)), ConsoleOutputInterface::VERBOSITY_VERBOSE);

            $this->lockFactory->createLockFromKey($message->key)->release();
        }
    }
}
