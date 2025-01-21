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
    private function handleMessage(
        CreateDocumentMessage $message,
    ): void {
        $messageDecreased = false;
        $dataObject = null;
        $index = $this->indexRepository->flattenedGet($message->esIndex);

        try {
            $dataObject = $message->objectType::getById($message->objectId) ?? throw new \RuntimeException('DataObject not found');
            $event = $this->eventDispatcher->dispatch(new PreDocumentCreateEvent($index, $dataObject), ElasticaBridgeEvents::PRE_DOCUMENT_CREATE);

            if ($event->isExecutionStopped()) {
                return;
            }

            if ($this->consoleOutput->getVerbosity() > ConsoleOutputInterface::VERBOSITY_NORMAL) {
                $currentCount = $event->getCurrentCount();

                if ($this->synchronous) {
                    $currentCount = self::$messageCount;
                }

                $this->consoleOutput->writeln(
                    sprintf(
                        'Processing message of %s %s. ~%s left. (PID: %s)',
                        $message->esIndex,
                        $message->objectId,
                        $currentCount,
                        getmypid(),
                    ),
                    ConsoleOutputInterface::VERBOSITY_VERBOSE
                );
            }

            if ($message->callback?->shouldCallEvent() === true) {
                $this->eventDispatcher->dispatch($message->callback->getEvent(), $message->callback->getEventName());
            }

            $documentInstance = $this->documentRepository->get($message->document);


            $this->documentHelper->setTenantIfNeeded($documentInstance, $index);
            $esIndex = $index->getBlueGreenInactiveElasticaIndex();
            $esDocuments = [$this->documentHelper->elementToDocument($documentInstance, $dataObject)];

            if (count($esDocuments) > 0) {
                $esIndex->addDocuments($esDocuments);
            }
            $messageDecreased = true;
        } catch (\Throwable $throwable) {
            $this->consoleOutput->writeln(sprintf('Error processing message %s: %s', $message->esIndex, $throwable->getMessage()), ConsoleOutputInterface::VERBOSITY_NORMAL);

            if (!$this->configurationRepository->shouldSkipFailingDocuments()) {
                throw $throwable;
            }
        } finally {
            $this->eventDispatcher->dispatch(new PostDocumentCreateEvent($index, $message->objectType, $message->objectId, $dataObject, success: $messageDecreased, willRetry: true), ElasticaBridgeEvents::POST_DOCUMENT_CREATE);

            if (!$messageDecreased) {
                $this->consoleOutput->writeln(sprintf('Message %s not processed. (ID: %s)', $message->esIndex, $message->objectId), ConsoleOutputInterface::VERBOSITY_VERBOSE);
            } else {
                self::$messageCount--;
            }

            \Pimcore::collectGarbage();
        }
    }
}
