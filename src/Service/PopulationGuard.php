<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Service;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Valantic\ElasticaBridgeBundle\Exception\Index\PopulationNotStartedException;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Model\Event\ElasticaBridgeEvents;
use Valantic\ElasticaBridgeBundle\Model\Event\PreSwitchIndexEvent;

/**
 * Decides whether a population may start and takes the locks that keep concurrent populations apart.
 */
class PopulationGuard
{
    public function __construct(
        private readonly LockService $lockService,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * On success the queue lock (unless ignored) and the processing lock (unless ignored) stay acquired.
     * If the population must not start, no lock acquired here is kept.
     *
     * @param bool $keepProcessingLock if false, the processing lock is released automatically instead of by ReleaseIndexLock
     *
     * @throws PopulationNotStartedException
     */
    public function assertCanStart(
        IndexInterface $indexConfig,
        int $documentCount,
        bool $ignoreCooldown = false,
        bool $ignoreLock = false,
        bool $keepProcessingLock = true,
        bool $ignoreQueueLock = true,
    ): void {
        $cooldownKey = $this->lockService->getKey($indexConfig->getName(), 'cooldown');
        $queueKey = $this->lockService->getKey($indexConfig->getName(), 'queue');
        $queueLock = $this->lockService->createLockFromKey($queueKey);
        $cooldownLock = $this->lockService->createLockFromKey($cooldownKey, ttl: 0);
        $messagesProcessed = $this->eventDispatcher->dispatch(new PreSwitchIndexEvent($indexConfig), ElasticaBridgeEvents::PRE_SWITCH_INDEX)->getRemainingMessages() === 0;
        $processingLock = $this->lockService->getIndexingLock($indexConfig, autorelease: !$keepProcessingLock);

        if ($documentCount === 0) {
            throw new PopulationNotStartedException(PopulationNotStartedException::TYPE_NO_DOCUMENTS);
        }

        if (!$ignoreQueueLock && !$queueLock->acquire()) {
            throw new PopulationNotStartedException(PopulationNotStartedException::TYPE_PROCESSING);
        }

        try {
            if (!$ignoreCooldown && !$cooldownLock->acquire()) {
                throw new PopulationNotStartedException(PopulationNotStartedException::TYPE_COOLDOWN);
            }

            if (!$messagesProcessed) {
                throw new PopulationNotStartedException(PopulationNotStartedException::TYPE_PROCESSING_MESSAGES);
            }

            $cooldownLock->release();

            if (!$ignoreLock && !$processingLock->acquire()) {
                throw new PopulationNotStartedException(PopulationNotStartedException::TYPE_PROCESSING);
            }
        } catch (PopulationNotStartedException $exception) {
            // population is not starting, so do not keep the locks acquired above
            if (!$ignoreQueueLock) {
                $queueLock->release();
            }

            if ($cooldownLock->isAcquired()) {
                $cooldownLock->release();
            }

            throw $exception;
        }
    }
}
