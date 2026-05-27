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
    ) {}

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
        // IDs that contributed ES documents — PostDocumentCreateEvent dispatched after addDocuments().
        $pendingSuccessIds = [];

        foreach ($message->objectIds as $objectId) {
            $dataObject = null;

            try {
                $dataObject = $message->objectType::getById($objectId) ?? throw new \RuntimeException('DataObject not found: ' . $objectId);
                $event = $this->eventDispatcher->dispatch(new PreDocumentCreateEvent($index, $dataObject), ElasticaBridgeEvents::PRE_DOCUMENT_CREATE);

                if ($event->isExecutionStopped()) {
                    $this->dispatchPost($index, $message->objectType, $objectId, $dataObject, skipped: true);
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

                if (count($esDocuments) === 0) {
                    // Nothing to index for this element — count it as success immediately.
                    $this->dispatchPost($index, $message->objectType, $objectId, $dataObject, success: true);

                    if ($this->synchronous) {
                        self::$messageCount--;
                    }

                    continue;
                }

                $allEsDocuments = [...$allEsDocuments, ...$esDocuments];
                $pendingSuccessIds[] = $objectId;
            } catch (\Throwable $throwable) {
                $this->consoleOutput->writeln(sprintf(
                    'Error processing %s (objectId %s): %s (%s)',
                    $message->esIndex,
                    $objectId,
                    $throwable->getMessage(),
                    $throwable::class,
                ), ConsoleOutputInterface::VERBOSITY_NORMAL);

                if (!$this->configurationRepository->shouldSkipFailingDocuments()) {
                    $this->dispatchPost($index, $message->objectType, $objectId, $dataObject, success: false, willRetry: true, throwable: $throwable);
                    throw $throwable;
                }

                $this->dispatchPost($index, $message->objectType, $objectId, $dataObject, success: false, willRetry: false, throwable: $throwable);
            }
        }

        if ($allEsDocuments !== []) {
            $esIndex->addDocuments($allEsDocuments);
        }

        foreach ($pendingSuccessIds as $objectId) {
            $this->dispatchPost($index, $message->objectType, $objectId, null, success: true);

            if ($this->synchronous) {
                self::$messageCount--;
            }
        }

        if ($message->callback?->shouldCallEvent() === true) {
            $this->eventDispatcher->dispatch($message->callback->getEvent(), $message->callback->getEventName());
        }

        \Pimcore::collectGarbage();
    }

    private function dispatchPost(
        mixed $index,
        string $elementType,
        int $elementId,
        mixed $element,
        bool $success = false,
        bool $skipped = false,
        bool $willRetry = false,
        ?\Throwable $throwable = null,
    ): void {
        $this->eventDispatcher->dispatch(
            new PostDocumentCreateEvent(
                $index,
                $elementType,
                $elementId,
                $element,
                success: $success,
                skipped: $skipped,
                willRetry: $willRetry,
                throwable: $throwable,
            ),
            ElasticaBridgeEvents::POST_DOCUMENT_CREATE,
        );
    }
}
