<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\EventListener\Messenger;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Valantic\ElasticaBridgeBundle\Messenger\Message\CreateDocumentMessage;
use Valantic\ElasticaBridgeBundle\Model\Event\PostDocumentCreateEvent;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;

#[AsEventListener]
class CreateDocumentFailedEvent implements EventSubscriberInterface
{
    public function __construct(private readonly EventDispatcherInterface $eventDispatcher, private readonly IndexRepository $indexRepository) {}

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();

        if (!$message instanceof CreateDocumentMessage || $event->willRetry()) {
            return;
        }

        $index = $this->indexRepository->flattenedGet($message->esIndex);
        $this->eventDispatcher->dispatch(
            new PostDocumentCreateEvent(
                $index,
                $message->objectType,
                $message->objectId,
                null,
                false,
                willRetry: false
            )
        );
    }

    public static function getSubscribedEvents()
    {
        return [
            WorkerMessageFailedEvent::class => 'onMessageFailed',
        ];
    }
}
