<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Handler;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Valantic\ElasticaBridgeBundle\Exception\Index\PopulationNotStartedException;
use Valantic\ElasticaBridgeBundle\Messenger\Message\PopulateIndexMessage;
use Valantic\ElasticaBridgeBundle\Messenger\Message\TriggerSingleIndexMessage;
use Valantic\ElasticaBridgeBundle\Service\LockService;
use Valantic\ElasticaBridgeBundle\Service\PopulateIndexService;

#[AsMessageHandler(fromTransport: 'elastica_bridge_populate')]
class PopulateHandler
{
    public function __construct(
        private readonly MessageBusInterface $messengerBusElasticaBridge,
        private readonly PopulateIndexService $populateIndexService,
        private readonly LockService $lockService,
    ) {}

    public function __invoke(PopulateIndexMessage|TriggerSingleIndexMessage $message, bool $synchronous): void
    {
        if ($message instanceof PopulateIndexMessage) {
            $deferredMessage = $message->message;
            $this->messengerBusElasticaBridge->dispatch($deferredMessage);

            return;
        }

        try {

            if ($synchronous) {
                throw new PopulationNotStartedException(PopulationNotStartedException::TYPE_NOT_AVAILABLE_IN_SYNC);
            }

            foreach ($this->populateIndexService->triggerSingleIndex($message->indexName, $message->populate, $message->ignoreLock, $message->ignoreCooldown) as $generator) {
                $this->messengerBusElasticaBridge->dispatch($generator->message);
            }
        } finally {
            $this->lockService->createLockFromKey($message->key)->release();
        }
    }
}
