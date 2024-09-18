<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Handler;

use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\MissingParameterException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Valantic\ElasticaBridgeBundle\Messenger\Message\ReleaseIndexLock;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;
use Valantic\ElasticaBridgeBundle\Service\LockService;

#[AsMessageHandler]
class SwitchIndexHandler
{
    public function __construct(
        private readonly IndexRepository $indexRepository,
        private readonly LockFactory $lockFactory,
        private readonly LockService $lockService,
    ) {}

    /**
     * @throws ServerResponseException
     * @throws ClientResponseException
     * @throws MissingParameterException
     */
    public function __invoke(ReleaseIndexLock $message): void
    {
        try {
            if ($message->swtichIndex === false || $this->lockService->isExecutionLocked($message->indexName)) {
                return;
            }

            $this->lockService->waitForFinish($message->indexName);
            $indexConfig = $this->indexRepository->flattenedGet($message->indexName);
            $oldIndex = $indexConfig->getBlueGreenActiveElasticaIndex();
            $newIndex = $indexConfig->getBlueGreenInactiveElasticaIndex();

            $newIndex->flush();
            $oldIndex->removeAlias($indexConfig->getName());
            $newIndex->addAlias($indexConfig->getName());
            $oldIndex->flush();
        } finally {
            $key = $message->key;

            $lock = $this->lockFactory->createLockFromKey($key);
            $lock->release();

            \Pimcore::collectGarbage();
        }
    }
}
