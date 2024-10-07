<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Handler;

use Elastic\Elasticsearch\Exception\MissingParameterException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Valantic\ElasticaBridgeBundle\Messenger\Message\CreateDocument;
use Valantic\ElasticaBridgeBundle\Repository\ConfigurationRepository;
use Valantic\ElasticaBridgeBundle\Repository\DocumentRepository;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;
use Valantic\ElasticaBridgeBundle\Service\DocumentHelper;
use Valantic\ElasticaBridgeBundle\Service\LockService;

#[AsMessageHandler]
class CreateDocumentHandler
{
    public function __construct(
        private readonly DocumentHelper $documentHelper,
        private readonly DocumentRepository $documentRepository,
        private readonly IndexRepository $indexRepository,
        private readonly ConfigurationRepository $configurationRepository,
        private readonly LockService $lockService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ConsoleOutputInterface $consoleOutput,
    ) {}

    public function __invoke(CreateDocument $message): void
    {
        $this->handleMessage($message);
    }

    /**
     * @throws \Throwable
     * @throws ServerResponseException
     * @throws MissingParameterException
     */
    private function handleMessage(CreateDocument $message): void
    {
        $messageDecreased = false;

        try {
            if ($this->lockService->isExecutionLocked($message->esIndex)) {
                return;
            }

            if ($this->consoleOutput->getVerbosity() > ConsoleOutputInterface::VERBOSITY_NORMAL) {

                $count = $this->lockService->getCurrentCount($message->esIndex);
                $this->consoleOutput->writeln(
                    sprintf(
                        'Processing message of %s %s. ~%s left. (PID: %s)',
                        $message->esIndex,
                        $message->objectId,
                        $count,
                        getmypid(),
                    ),
                    ConsoleOutputInterface::VERBOSITY_VERBOSE
                );
            }

            if ($message->callback->shouldCallEvent()) {
                $this->eventDispatcher->dispatch($message->callback->getEvent(), $message->callback->getEventName());
            }

            if ($message->objectId === null || $message->objectType === null) {
                $this->lockService->messageProcessed($message->esIndex);
                $messageDecreased = true;

                return;
            }

            $documentInstance = $this->documentRepository->get($message->document);
            $dataObject = $message->objectType::getById($message->objectId) ?? throw new \RuntimeException('DataObject not found');

            try {
                $index = $this->indexRepository->flattenedGet($message->esIndex);
                $this->documentHelper->setTenantIfNeeded($documentInstance, $index);
                $esIndex = $index->getBlueGreenInactiveElasticaIndex();
                $esDocuments = [$this->documentHelper->elementToDocument($documentInstance, $dataObject)];

                if (count($esDocuments) > 0) {
                    $esIndex->addDocuments($esDocuments);
                }
            } catch (\Throwable $throwable) {
                $this->consoleOutput->writeln(sprintf('Error processing message %s: %s', $message->esIndex, $throwable->getMessage()), ConsoleOutputInterface::VERBOSITY_NORMAL);

                if (!$this->configurationRepository->shouldSkipFailingDocuments()) {
                    $this->lockService->lockExecution($message->esIndex);

                    if ($this->configurationRepository->shouldPopulateAsync()) {
                        throw new UnrecoverableMessageHandlingException($throwable->getMessage(), previous: $throwable);
                    }
                }

            }
            $messageDecreased = true;
            $this->lockService->messageProcessed($message->esIndex);
        } finally {
            if (!$messageDecreased) {
                $this->consoleOutput->writeln(sprintf('Message %s not processed. (ID: %s)', $message->esIndex, $message->objectId), ConsoleOutputInterface::VERBOSITY_VERBOSE);
            }

            if ($message->lastItem && $message->cooldown > 0) {
                $key = $this->lockService->getKey($message->esIndex, 'cooldown');
                $this->lockService->createLockFromKey($key, ttl: $message->cooldown)->acquire();
            }


            \Pimcore::collectGarbage();
        }
    }
}
