<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Handler;

use Elastic\Elasticsearch\Exception\MissingParameterException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Envelope;
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
    ) {}

    /**
     * @throws \Throwable
     * @throws ServerResponseException
     * @throws MissingParameterException
     */
    public function __invoke(CreateDocument $message, int $retryCount = 0): void
    {

        try {
            if ($this->lockService->isExecutionLocked($message->esIndex)) {
                return;
            }

            if ($message->callback->shouldCallEvent()) {
                $this->eventDispatcher->dispatch($message->callback->getEvent(), $message->callback->getEventName());
            }

            if ($message->objectId === null || $message->objectType === null) {
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
                        $envelope = new Envelope(new ReleaseIndexLock($message->esIndex, $key), [new DelayStamp(5000)]);
                        $this->messageBus->dispatch($envelope);
                    }

                    if ($this->configurationRepository->shouldPopulateAsync()) {
                        throw new UnrecoverableMessageHandlingException($throwable->getMessage(), previous: $throwable);
                    }
                }
            }
        } finally {
            if ($message->lastItem && $message->cooldown > 0) {
                $key = $this->lockService->getKey($message->esIndex, 'cooldown');
                $this->lockService->createLockFromKey($key, ttl: $message->cooldown)->acquire();
            }

            \Pimcore::collectGarbage();
        }
    }
}
