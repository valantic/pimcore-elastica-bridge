<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\PopulateService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Valantic\ElasticaBridgeBundle\Model\Event\ElasticaBridgeEvents;
use Valantic\ElasticaBridgeBundle\Model\Event\PostDocumentCreateEvent;
use Valantic\ElasticaBridgeBundle\Model\Event\PreDocumentCreateEvent;
use Valantic\ElasticaBridgeBundle\Model\Event\PreExecuteEvent;
use Valantic\ElasticaBridgeBundle\Model\Event\PreProcessMessagesEvent;
use Valantic\ElasticaBridgeBundle\Model\Event\PreSwitchIndexEvent;
use Valantic\ElasticaBridgeBundle\Model\Event\WaitForCompletionEvent;

class PopulateListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly PopulateService $populateService,
    ) {}

    public function onPostDocumentCreate(PostDocumentCreateEvent $event): void
    {
        if ($event->success) {
            $this->populateService->decrementRemainingMessages($event->index->getName());

            return;
        }

        if ($event->willRetry || $event->skipped) {
            return;
        }

        $this->populateService->lockExecution($event->index->getName());
    }

    public function onPostSwitchIndex(): void {}

    public function onPreDocumentCreate(PreDocumentCreateEvent $event): void
    {
        if ($this->populateService->isExecutionLocked($event->index->getName())) {
            $event->stopExecution();

            return;
        }

        $event->setCurrentCount($this->populateService->getRemainingMessages($event->index->getName()));
    }

    public function onPrePopulateIndex(PreExecuteEvent $prePopulateEvent): void
    {
        if ($prePopulateEvent->source === PreExecuteEvent::SOURCE_CLI) {
            $this->populateService->unlockExecution($prePopulateEvent->index->getName());
        }
    }

    public function onPreSwitchIndex(PreSwitchIndexEvent $event): void
    {
        if ($this->populateService->isExecutionLocked($event->index->getName())) {
            $event->skipSwitch();
            $event->initiateCooldown = false;
        }

        $event->setRemainingMessages($this->populateService->getRemainingMessages($event->index->getName()));
    }

    public function onWaitForCompletion(WaitForCompletionEvent $event): void
    {
        if ($this->populateService->isExecutionLocked($event->index->getName())) {
            $event->skipSwitch();

            return;
        }

        $retryCount = $event->retries;
        $event->setRemainingMessages($this->populateService->getRemainingMessages($event->index->getName()));

        if ($retryCount > $event->maximumRetries - 1) {
            $remainingMessages = $this->populateService->getActualMessageCount($event->index->getName());
            $event->setRemainingMessages($remainingMessages);
            $this->populateService->setExpectedMessages($event->index->getName(), $remainingMessages);
        }
    }

    public function preProcessMessagesEvent(PreProcessMessagesEvent $messageQueueInitializedEvent): void
    {
        $this->populateService->setExpectedMessages($messageQueueInitializedEvent->index->getName(), $messageQueueInitializedEvent->expectedMessages);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ElasticaBridgeEvents::PRE_EXECUTE => 'onPrePopulateIndex',
            ElasticaBridgeEvents::PRE_PROCESS_MESSAGES_EVENT => 'preProcessMessagesEvent',
            ElasticaBridgeEvents::PRE_DOCUMENT_CREATE => 'onPreDocumentCreate',
            ElasticaBridgeEvents::POST_DOCUMENT_CREATE => 'onPostDocumentCreate',
            ElasticaBridgeEvents::PRE_SWITCH_INDEX => 'onPreSwitchIndex',
            ElasticaBridgeEvents::WAIT_FOR_COMPLETION_EVENT => 'onWaitForCompletion',
            ElasticaBridgeEvents::POST_SWITCH_INDEX => 'onPostSwitchIndex',
        ];
    }
}
