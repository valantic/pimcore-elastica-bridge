<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Handler;

use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\MissingParameterException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
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
        private readonly ConsoleOutputInterface $consoleOutput,
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
            $this->consoleOutput->writeln('waiting for lock release', ConsoleOutputInterface::VERBOSITY_VERBOSE);
            $this->lockService->waitForFinish($message->indexName);
            $indexConfig = $this->indexRepository->flattenedGet($message->indexName);
            $oldIndex = $indexConfig->getBlueGreenActiveElasticaIndex();
            $newIndex = $indexConfig->getBlueGreenInactiveElasticaIndex();
            $this->consoleOutput->writeln('Switching index', ConsoleOutputInterface::VERBOSITY_VERBOSE);
            $newIndex->flush();
            $oldIndex->removeAlias($indexConfig->getName());
            $this->consoleOutput->writeln('removed alias from ' . $oldIndex->getName(), ConsoleOutputInterface::VERBOSITY_NORMAL);
            $newIndex->addAlias($indexConfig->getName());
            $this->consoleOutput->writeln('added alias to ' . $newIndex->getName(), ConsoleOutputInterface::VERBOSITY_NORMAL);
            $oldIndex->flush();
        } finally {
            $this->consoleOutput->writeln('Releasing lock', ConsoleOutputInterface::VERBOSITY_VERBOSE);
            $key = $message->key;

            $lock = $this->lockFactory->createLockFromKey($key);
            $lock->release();

            \Pimcore::collectGarbage();
        }
    }
}
