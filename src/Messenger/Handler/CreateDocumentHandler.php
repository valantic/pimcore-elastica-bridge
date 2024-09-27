<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Handler;

use Elastic\Elasticsearch\Exception\MissingParameterException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Valantic\ElasticaBridgeBundle\Messenger\Message\CreateDocument;
use Valantic\ElasticaBridgeBundle\Messenger\Message\ReleaseIndexLock;
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
        private readonly MessageBusInterface $messageBus,
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
        try {
            if ($this->consoleOutput->getVerbosity() > ConsoleOutputInterface::VERBOSITY_NORMAL) {

                $count = $this->lockService->getCurrentCount($message->esIndex);
                $this->consoleOutput->writeln(
                    sprintf(
                        'Processing message of %s %s. ~ %s left. (PID: %s)',
                        $message->esIndex,
                        $message->objectId,
                        $count,
                        getmypid(),
                    ),
                    ConsoleOutputInterface::VERBOSITY_VERBOSE
                );
            }

            if ($this->lockService->isExecutionLocked($message->esIndex)) {
                return;
            }

            if ($message->callback->shouldCallEvent()) {
                $this->eventDispatcher->dispatch($message->callback->getEvent(), $message->callback->getEventName());
            }

            if ($message->objectId === null || $message->objectType === null) {
                $this->lockService->messageProcessed($message->esIndex);

                return;
            }

            $documentInstance = $this->documentRepository->get($message->document);
            $dataObject = $message->objectType::getById($message->objectId) ?? throw new \RuntimeException('DataObject not found');
            $esDocuments = [$this->documentHelper->elementToDocument($documentInstance, $dataObject)];
            $esIndex = $this->indexRepository->flattenedGet($message->esIndex)->getBlueGreenInactiveElasticaIndex();

            if (count($esDocuments) > 0) {
                try {
                    $esIndex->addDocuments($esDocuments);
                } catch (\Throwable $throwable) {
                    if (!$this->configurationRepository->shouldSkipFailingDocuments()) {
                        $key = $this->lockService->lockExecution($message->esIndex);
                        $this->messageBus->dispatch(new ReleaseIndexLock($message->esIndex, $key), [new DelayStamp(5000)]);
                    }

                    if ($this->configurationRepository->shouldPopulateAsync()) {
                        throw new UnrecoverableMessageHandlingException($throwable->getMessage(), previous: $throwable);
                    }
                }
            }
            $this->lockService->messageProcessed($message->esIndex);
        } finally {
            if ($message->lastItem && $message->cooldown > 0) {
                $key = $this->lockService->getKey($message->esIndex, 'cooldown');
                $this->lockService->createLockFromKey($key, ttl: $message->cooldown)->acquire();
            }


            \Pimcore::collectGarbage();
        }
    }
}
