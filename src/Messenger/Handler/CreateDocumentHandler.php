<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Handler;

use Elastic\Elasticsearch\Exception\MissingParameterException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Elastica\Document;
use Elastica\Exception\Bulk\ResponseException as BulkResponseException;
use Pimcore\Model\Element\AbstractElement;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Messenger\Message\CreateDocumentMessage;
use Valantic\ElasticaBridgeBundle\Model\Event\ElasticaBridgeEvents;
use Valantic\ElasticaBridgeBundle\Model\Event\PostDocumentCreateEvent;
use Valantic\ElasticaBridgeBundle\Model\Event\PreDocumentCreateEvent;
use Valantic\ElasticaBridgeBundle\Repository\ConfigurationRepository;
use Valantic\ElasticaBridgeBundle\Repository\DocumentRepository;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;
use Valantic\ElasticaBridgeBundle\Service\DocumentHelper;
use Valantic\ElasticaBridgeBundle\Service\PopulationProgress;

#[AsMessageHandler]
class CreateDocumentHandler
{
    private bool $synchronous;

    public function __construct(
        private readonly DocumentHelper $documentHelper,
        private readonly DocumentRepository $documentRepository,
        private readonly IndexRepository $indexRepository,
        private readonly ConfigurationRepository $configurationRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ConsoleOutputInterface $consoleOutput,
        private readonly PopulationProgress $populationProgress,
        private readonly MessageBusInterface $messengerBusElasticaBridge,
    ) {
    }

    // parameter name must match SyncTransportDetectionInterface::SYNCHRONOUS_ARGUMENT
    public function __invoke(CreateDocumentMessage $message, bool $synchronous = true): void
    {
        $this->synchronous = $synchronous;

        try {
            $this->handleMessage($message);
        } finally {
            \Pimcore::collectGarbage();
        }
    }

    /**
     * @throws \Throwable
     * @throws ServerResponseException
     * @throws MissingParameterException
     */
    private function handleMessage(CreateDocumentMessage $message): void
    {
        $index = $this->indexRepository->flattenedGet($message->esIndex);

        try {
            $documentInstance = $this->documentRepository->get($message->document);
            $this->documentHelper->setTenantIfNeeded($documentInstance, $index);
        } catch (\Throwable $throwable) {
            // none of the elements can be indexed
            $this->handleMessageFailure($message, $index, array_fill_keys($message->objectIds, null), [], $throwable);

            return;
        }

        // Post events are held back until the outcome of the whole message is final: if the message
        // is retried, every element in it is processed and reported again.
        $postEvents = [];
        // Elements that contributed ES documents, keyed by ID; reported once addDocuments() succeeded.
        $pendingElements = [];
        /** @var array<int, Document[]> $esDocumentsPerElement element ID => its ES documents */
        $esDocumentsPerElement = [];
        /** @var array<int, array{?AbstractElement, \Throwable}> $failures element ID => element and why it could not be indexed */
        $failures = [];
        $isAnyElementProcessed = false;

        foreach ($message->objectIds as $objectId) {
            $dataObject = null;

            try {
                $dataObject = $message->objectType::getById($objectId);

                if ($dataObject === null) {
                    // the element was deleted after the message was generated, so there is nothing to index or to retry
                    $this->consoleOutput->writeln(sprintf('Element %s %s no longer exists. (Index: %s)', $message->objectType, $objectId, $message->esIndex), ConsoleOutputInterface::VERBOSITY_VERBOSE);
                    $postEvents[] = new PostDocumentCreateEvent($index, $message->objectType, $objectId, null);

                    continue;
                }

                $event = $this->eventDispatcher->dispatch(new PreDocumentCreateEvent($index, $dataObject), ElasticaBridgeEvents::PRE_DOCUMENT_CREATE);

                if ($event->isExecutionStopped()) {
                    $postEvents[] = new PostDocumentCreateEvent($index, $message->objectType, $objectId, $dataObject, success: false, skipped: true);

                    continue;
                }

                $isAnyElementProcessed = true;

                if ($this->consoleOutput->getVerbosity() > ConsoleOutputInterface::VERBOSITY_NORMAL) {
                    $currentCount = $event->getCurrentCount();

                    if ($this->synchronous) {
                        $currentCount = $this->populationProgress->getRemaining();
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

                $esDocumentsPerElement[$objectId] = $esDocuments;
                $pendingElements[$objectId] = $dataObject;
            } catch (\Throwable $throwable) {
                $this->consoleOutput->writeln(sprintf(
                    'Error processing %s (objectId %s): %s (%s)',
                    $message->esIndex,
                    $objectId,
                    $throwable->getMessage(),
                    $throwable::class,
                ), ConsoleOutputInterface::VERBOSITY_NORMAL);

                $failures[$objectId] = [$dataObject, $throwable];
            }
        }

        if ($isAnyElementProcessed && $message->callback?->shouldCallEvent() === true) {
            $this->eventDispatcher->dispatch($message->callback->getEvent(), $message->callback->getEventName());
        }

        if ($esDocumentsPerElement !== []) {
            try {
                $index->getBlueGreenInactiveElasticaIndex()->addDocuments(array_merge(...array_values($esDocumentsPerElement)));
            } catch (BulkResponseException $exception) {
                // only some documents failed, the others are indexed
                $failedElementIds = $this->getFailedElementIds($exception, $esDocumentsPerElement);

                foreach ($failedElementIds as $objectId) {
                    $failures[$objectId] = [$pendingElements[$objectId], $exception];
                    unset($pendingElements[$objectId]);
                }

                $this->consoleOutput->writeln(sprintf(
                    'Error indexing %s (objectIds %s): %s',
                    $message->esIndex,
                    implode(', ', $failedElementIds),
                    $exception->getActionExceptionsAsString(),
                ), ConsoleOutputInterface::VERBOSITY_NORMAL);
            } catch (\Throwable $throwable) {
                // the whole request failed, so none of the documents are indexed
                $this->consoleOutput->writeln(sprintf(
                    'Error indexing %s (objectIds %s): %s (%s)',
                    $message->esIndex,
                    implode(', ', array_keys($pendingElements)),
                    $throwable->getMessage(),
                    $throwable::class,
                ), ConsoleOutputInterface::VERBOSITY_NORMAL);

                $this->handleMessageFailure($message, $index, $pendingElements, $postEvents, $throwable, $failures);

                return;
            }
        }

        foreach ($pendingElements as $objectId => $dataObject) {
            $postEvents[] = new PostDocumentCreateEvent($index, $message->objectType, $objectId, $dataObject);
        }

        if ($failures === [] || $this->configurationRepository->shouldSkipFailingDocuments()) {
            foreach ($failures as $objectId => [$dataObject, $throwable]) {
                $postEvents[] = new PostDocumentCreateEvent($index, $message->objectType, $objectId, $dataObject, success: false, skipped: true, throwable: $throwable);
            }

            $this->dispatchPosts($postEvents);

            return;
        }

        if (!$this->synchronous && count($message->objectIds) > 1) {
            // Retrying the whole message would keep one failing element from ever indexing the others,
            // and report the others again; the failed elements are retried in messages of their own instead.
            $this->dispatchPosts($postEvents);

            foreach (array_keys($failures) as $objectId) {
                $this->messengerBusElasticaBridge->dispatch(new CreateDocumentMessage([$objectId], $message->objectType, $message->document, $message->esIndex));
            }

            return;
        }

        // synchronous messages are not retried, and a message with a single element only reports that element
        $this->dispatchPosts($postEvents);

        foreach ($failures as $objectId => [$dataObject, $throwable]) {
            $this->dispatchPost(new PostDocumentCreateEvent($index, $message->objectType, $objectId, $dataObject, success: false, willRetry: true, throwable: $throwable));
        }

        throw reset($failures)[1];
    }

    /**
     * Handles a failure that affects all remaining elements of a message: they are skipped or the whole message is retried.
     *
     * @param array<int, ?AbstractElement> $elements element ID => element affected by the failure
     * @param PostDocumentCreateEvent[] $postEvents outcomes of the other elements, reported unless the message is retried
     * @param array<int, array{?AbstractElement, \Throwable}> $failures elements that failed on their own
     *
     * @throws \Throwable
     */
    private function handleMessageFailure(
        CreateDocumentMessage $message,
        IndexInterface $index,
        array $elements,
        array $postEvents,
        \Throwable $throwable,
        array $failures = [],
    ): void {
        $skipFailingDocuments = $this->configurationRepository->shouldSkipFailingDocuments();
        $failures += array_map(static fn (?AbstractElement $element): array => [$element, $throwable], $elements);

        if ($skipFailingDocuments) {
            foreach ($failures as $objectId => [$dataObject, $elementThrowable]) {
                $postEvents[] = new PostDocumentCreateEvent($index, $message->objectType, $objectId, $dataObject, success: false, skipped: true, throwable: $elementThrowable);
            }

            $this->dispatchPosts($postEvents);

            return;
        }

        // the other elements are reported when the message is retried
        foreach ($failures as $objectId => [$dataObject, $elementThrowable]) {
            $this->dispatchPost(new PostDocumentCreateEvent($index, $message->objectType, $objectId, $dataObject, success: false, willRetry: true, throwable: $elementThrowable));
        }

        throw $throwable;
    }

    /**
     * @param array<int, Document[]> $esDocumentsPerElement
     *
     * @return int[] IDs of the elements of which at least one document failed; all elements if a failure can't be attributed
     */
    private function getFailedElementIds(BulkResponseException $exception, array $esDocumentsPerElement): array
    {
        $elementIdsByDocumentId = [];

        foreach ($esDocumentsPerElement as $objectId => $esDocuments) {
            foreach ($esDocuments as $esDocument) {
                $elementIdsByDocumentId[(string) $esDocument->getId()] = $objectId;
            }
        }

        $failedElementIds = [];

        foreach ($exception->getActionExceptions() as $actionException) {
            $documentId = $actionException->getAction()->getMetadata()['_id'] ?? null;

            if ($documentId === null || !isset($elementIdsByDocumentId[$documentId])) {
                return array_keys($esDocumentsPerElement);
            }

            $failedElementIds[$elementIdsByDocumentId[$documentId]] = true;
        }

        return array_keys($failedElementIds);
    }

    /**
     * @param PostDocumentCreateEvent[] $events
     */
    private function dispatchPosts(array $events): void
    {
        foreach ($events as $event) {
            $this->dispatchPost($event);
        }
    }

    private function dispatchPost(PostDocumentCreateEvent $event): void
    {
        $this->eventDispatcher->dispatch($event, ElasticaBridgeEvents::POST_DOCUMENT_CREATE);

        if ($event->success && $this->synchronous) {
            $this->populationProgress->markProcessed();
        }
    }
}
