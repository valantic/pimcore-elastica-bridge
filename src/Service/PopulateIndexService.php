<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Service;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandlerArgumentsStamp;
use Valantic\ElasticaBridgeBundle\Enum\PopulationSource;
use Valantic\ElasticaBridgeBundle\Exception\Index\PopulationNotStartedException;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Messenger\Message\PopulateIndexMessage;
use Valantic\ElasticaBridgeBundle\Messenger\Message\TriggerSingleIndexMessage;
use Valantic\ElasticaBridgeBundle\Messenger\Middleware\SyncTransportDetectionInterface;
use Valantic\ElasticaBridgeBundle\Model\Event\ElasticaBridgeEvents;
use Valantic\ElasticaBridgeBundle\Model\Event\PreExecuteEvent;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;

class PopulateIndexService
{
    public function __construct(
        private readonly IndexRepository $indexRepository,
        private readonly IndexSetupService $indexSetupService,
        private readonly PopulationGuard $populationGuard,
        private readonly LockService $lockService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly MessageBusInterface $messengerBusElasticaBridge,
        private readonly PopulateLogger $logger,
        private readonly PopulationMessageGenerator $messageGenerator,
    ) {
    }

    /**
     * @return \Generator<PopulateIndexMessage|Envelope>
     */
    public function processScheduler(): \Generator
    {
        foreach ($this->indexRepository->flattenedAll() as $indexConfig) {
            try {
                $this->eventDispatcher->dispatch(new PreExecuteEvent($indexConfig, PopulationSource::SCHEDULER), ElasticaBridgeEvents::PRE_EXECUTE);
                $this->populationGuard->assertCanStart($indexConfig, $this->messageGenerator->getDocumentCount($indexConfig));

                $this->indexSetupService->setupIndex($indexConfig);

                foreach ($this->messageGenerator->generate($indexConfig) as $message) {
                    yield (new Envelope($message))->with(new HandlerArgumentsStamp([
                        SyncTransportDetectionInterface::SYNCHRONOUS_ARGUMENT => false,
                    ]));
                }
            } catch (PopulationNotStartedException $e) {
                if (!$e->isSilentModeEnabled()) {
                    $this->logger->log($indexConfig->getName(), '<fg=red>' . $e->getMessage() . '</>');
                }

                continue;
            }
        }
    }

    public function processApi(
        IndexInterface|string $indexConfig,
        bool $populate = false,
        bool $ignoreLock = false,
        bool $ignoreCooldown = false,
    ): void {
        if (is_string($indexConfig)) {
            $indexConfig = $this->indexRepository->flattenedGet($indexConfig);
        }

        $this->eventDispatcher->dispatch(new PreExecuteEvent($indexConfig, PopulationSource::API), ElasticaBridgeEvents::PRE_EXECUTE);

        $this->populationGuard->assertCanStart($indexConfig, $this->messageGenerator->getDocumentCount($indexConfig), $ignoreCooldown, $ignoreLock, false, ignoreQueueLock: false);

        $key = $this->lockService->getKey($indexConfig->getName(), 'queue');
        $this->messengerBusElasticaBridge->dispatch(new TriggerSingleIndexMessage($indexConfig->getName(), $populate, $ignoreCooldown, $ignoreLock, $key));
    }

    /**
     * @return \Generator<PopulateIndexMessage>
     */
    public function triggerSingleIndex(
        IndexInterface|string $indexConfig,
        bool $populate = false,
        bool $ignoreLock = false,
        bool $ignoreCooldown = false,
        bool $deleteExisting = false,
    ): \Generator {
        try {
            if (is_string($indexConfig)) {
                $indexConfig = $this->indexRepository->flattenedGet($indexConfig);
            }

            $this->populationGuard->assertCanStart($indexConfig, $this->messageGenerator->getDocumentCount($indexConfig), $ignoreCooldown, $ignoreLock, $populate);

            $this->indexSetupService->setupIndex($indexConfig, $deleteExisting);

            if (!$populate) {
                return;
            }

            yield from $this->messageGenerator->generate($indexConfig, $ignoreCooldown);
        } catch (PopulationNotStartedException $populationNotStartedException) {
            if ($populationNotStartedException->isSilentModeEnabled()) {
                throw $populationNotStartedException;
            }

            $this->logger->log($indexConfig->getName(), '<fg=red>' . $populationNotStartedException->getMessage() . '</>');

            throw $populationNotStartedException;
        }
    }

    public function isPopulating(IndexInterface $indexConfig): bool
    {
        return $this->lockService->isIndexingLocked($indexConfig);
    }
}
