<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Handler;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Valantic\ElasticaBridgeBundle\Messenger\Message\CreateDocument;
use Valantic\ElasticaBridgeBundle\Messenger\Message\PopulateIndexMessage;
use Valantic\ElasticaBridgeBundle\Service\LockService;

#[AsMessageHandler]
class PopulateHandler
{
    public function __construct(
        private readonly MessageBusInterface $messengerBusElasticaBridge,
        private readonly LockService $lockService,
    ) {}

    public function __invoke(PopulateIndexMessage $message): void
    {
        $deferredMessage = $message->message;

        if ($deferredMessage instanceof CreateDocument) {
            $this->lockService->messageDispatched($deferredMessage->esIndex);
        }

        $this->messengerBusElasticaBridge->dispatch($deferredMessage);
    }
}
