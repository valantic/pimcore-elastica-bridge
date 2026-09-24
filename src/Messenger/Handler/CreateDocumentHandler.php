<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Handler;

use Elastic\Elasticsearch\Exception\MissingParameterException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Valantic\ElasticaBridgeBundle\Messenger\Message\CreateDocumentMessage;
use Valantic\ElasticaBridgeBundle\Model\Event\ElasticaBridgeEvents;
use Valantic\ElasticaBridgeBundle\Model\Event\PostDocumentCreateEvent;
use Valantic\ElasticaBridgeBundle\Model\Event\PreDocumentCreateEvent;
use Valantic\ElasticaBridgeBundle\Repository\ConfigurationRepository;
use Valantic\ElasticaBridgeBundle\Repository\DocumentRepository;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;
use Valantic\ElasticaBridgeBundle\Service\DocumentHelper;

#[AsMessageHandler]
class CreateDocumentHandler
{
    public static int $messageCount = 0;

    private bool $synchronous;

    public function __construct(
        private readonly DocumentHelper $documentHelper,
        private readonly DocumentRepository $documentRepository,
        private readonly IndexRepository $indexRepository,
        private readonly ConfigurationRepository $configurationRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ConsoleOutputInterface $consoleOutput,
    ) {
    }

    public function __invoke(CreateDocumentMessage $message, int $retryCount = 0, bool $synchronous = true): void
    {
        $this->synchronous = $synchronous;
        $this->handleMessage($message);
    }

    /**
     * @throws \Throwable
     * @throws ServerResponseException
     * @throws MissingParameterException
     */
    private function handleMessage(CreateDocumentMessage $message): void
    {
        $index = $this->indexRepository->flattenedGet($message->esIndex);
        $documentInstance = $this->documentRepository->get($message->document);
        $this->documentHelper->setTenantIfNeeded($documentInstance, $index);
        $esIndex = $index->getBlueGreenInactiveElasticaIndex();

        // Collect ES documents from all elements in the batch, then send in one bulk call.
        $allEsDocuments = [];
        // Elements that contributed ES documents, keyed by ID; reported once addDocuments() succeeded.
        $pendingElements = [];
        // Post events are held back until the outcome of the whole message is final: if the message
        // is retried, every element in it is processed and reported again.
        $postEvents = [];

        foreach ($message->objectIds as $objectId) {
            $dataObject = null;

            try {
                $dataObject = $message->objectType::getById($objectId) ?? throw new \RuntimeException('DataObject not found: ' . $objectId);
                $event = $this->eventDispatcher->dispatch(new PreDocumentCreateEvent($index, $dataObject), ElasticaBridgeEvents::PRE_DOCUMENT_CREATE);

                if ($event->isExecutionStopped()) {
                    $postEvents[] = new PostDocumentCreateEvent($index, $message->objectType, $objectId, $dataObject, success: false, skipped: true);

                    continue;
                }

                if ($this->consoleOutput->getVerbosity() > ConsoleOutputInterface::VERBOSITY_NORMAL) {
                    $currentCount = $event->getCurrentCount();

                    if ($this->synchronous) {
                        $currentCount = self::$messageCount;
                    }

                    $this->consoleOutput->writeln(
                        sprintf(
                            'Processing message of %s %s. ~%s left. (PID: %s) (%s)',
                            $message->esIndex,
                            $objectId,
                            $currentCount,
                            getmypid(),
                            $this->synchronous ? 'sync' : 'async',
                        ),
                        ConsoleOutputInterface::VERBOSITY_VERBOSE,
                    );
                }

                $esDocuments = $this->documentHelper->elementToDocumentsForContexts($documentInstance, $dataObject, $index);

                if ($esDocuments === []) {
                    // Nothing to index for this element — count it as success.
                    $postEvents[] = new PostDocumentCreateEvent($index, $message->objectType, $objectId, $dataObject);

                    continue;
                }

                $allEsDocuments = [...$allEsDocuments, ...$esDocuments];
                $pendingElements[$objectId] = $dataObject;
            } catch (\Throwable $throwable) {
                $this->consoleOutput->writeln(sprintf(
                    'Error processing %s (objectId %s): %s (%s)',
                    $message->esIndex,
                    $objectId,
                    $throwable->getMessage(),
                    $throwable::class,
                ), ConsoleOutputInterface::VERBOSITY_NORMAL);

                if (!$this->configurationRepository->shouldSkipFailingDocuments()) {
                    $this->dispatchPost(new PostDocumentCreateEvent($index, $message->objectType, $objectId, $dataObject, success: false, willRetry: true, throwable: $throwable));

                    throw $throwable;
                }

                $postEvents[] = new PostDocumentCreateEvent($index, $message->objectType, $objectId, $dataObject, success: false, throwable: $throwable);
            }
        }

        if ($allEsDocuments !== []) {
            try {
                $esIndex->addDocuments($allEsDocuments);
            } catch (\Throwable $throwable) {
                $this->consoleOutput->writeln(sprintf(
                    'Error indexing %s (objectIds %s): %s (%s)',
                    $message->esIndex,
                    implode(', ', array_keys($pendingElements)),
                    $throwable->getMessage(),
                    $throwable::class,
                ), ConsoleOutputInterface::VERBOSITY_NORMAL);

                if (!$this->configurationRepository->shouldSkipFailingDocuments()) {
                    foreach ($pendingElements as $objectId => $dataObject) {
                        $this->dispatchPost(new PostDocumentCreateEvent($index, $message->objectType, $objectId, $dataObject, success: false, willRetry: true, throwable: $throwable));
                    }

                    throw $throwable;
                }

                foreach ($pendingElements as $objectId => $dataObject) {
                    $postEvents[] = new PostDocumentCreateEvent($index, $message->objectType, $objectId, $dataObject, success: false, throwable: $throwable);
                }

                $pendingElements = [];
            }
        }

        foreach ($pendingElements as $objectId => $dataObject) {
            $postEvents[] = new PostDocumentCreateEvent($index, $message->objectType, $objectId, $dataObject);
        }

        foreach ($postEvents as $postEvent) {
            $this->dispatchPost($postEvent);

            if ($postEvent->success && $this->synchronous) {
                self::$messageCount--;
            }
        }

        if ($message->callback?->shouldCallEvent() === true) {
            $this->eventDispatcher->dispatch($message->callback->getEvent(), $message->callback->getEventName());
        }

        \Pimcore::collectGarbage();
    }

    private function dispatchPost(PostDocumentCreateEvent $event): void
    {
        $this->eventDispatcher->dispatch($event, ElasticaBridgeEvents::POST_DOCUMENT_CREATE);
    }
}
