<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\EventListener\Messenger;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Valantic\ElasticaBridgeBundle\Messenger\Message\CreateDocument;
use Valantic\ElasticaBridgeBundle\Service\LockService;

#[AsEventListener]
class CreateDocumentFailedEvent implements EventSubscriberInterface
{
    public function __construct(private readonly LockService $lockService) {}

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry() || !$event->getEnvelope()->getMessage() instanceof CreateDocument) {
            return;
        }
        $this->lockService->lockExecution($event->getEnvelope()->getMessage()->esIndex);
    }

    public static function getSubscribedEvents()
    {
        return [
            WorkerMessageFailedEvent::class => 'onMessageFailed',
        ];
    }
}
