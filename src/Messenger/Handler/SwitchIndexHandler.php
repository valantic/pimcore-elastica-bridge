<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Handler;

use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\MissingParameterException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
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
        private readonly MessageBusInterface $messageBus,
    ) {}

    /**
     * @throws ServerResponseException
     * @throws ClientResponseException
     * @throws MissingParameterException
     */
    public function __invoke(ReleaseIndexLock $message): void
    {
        $releaseLock = true;
        $startTime = microtime(true);
        $maxAttempts = 5; // Set the maximum number of attempts
        $attempt = 0;

        try {
            if ($message->switchIndex === false || $this->lockService->isExecutionLocked($message->indexName)) {
                return;
            }

            // try to switch index. If not all messages are processed this will be rescheduled.
            $key = $this->lockService->getKey($message->indexName, 'switch-blue-green');
            $count = $this->lockService->getCurrentCount($message->indexName);
            $this->consoleOutput->writeln(sprintf('waiting for lock release (%s) for %s (%s)', $count, $message->indexName, hash('sha256', (string) $key)), ConsoleOutputInterface::VERBOSITY_VERBOSE);

            while (!$this->lockService->allMessagesProcessed($message->indexName) && $attempt < $maxAttempts) {
                $this->consoleOutput->writeln(sprintf('not all messages processed (~%s remaining), attempt %d', $count, $attempt + 1), ConsoleOutputInterface::VERBOSITY_VERBOSE);
                sleep(($count * $attempt) + 15);
                $attempt++;
            }

            if ($attempt >= $maxAttempts) {
                $this->consoleOutput->writeln('Max attempts reached, rescheduling', ConsoleOutputInterface::VERBOSITY_VERBOSE);
                $this->messageBus->dispatch($message->clone(), [new DelayStamp($count * 1000 * 2)]);
                $releaseLock = false;

                return;
            }

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
            if ($releaseLock) {
                $this->consoleOutput->writeln(sprintf('releasing lock %s (%s)', $message->key, hash('sha256', (string) $message->key)), ConsoleOutputInterface::VERBOSITY_VERBOSE);
                $key = $message->key;

                $lock = $this->lockFactory->createLockFromKey($key);
                $lock->release();
            }

            \Pimcore::collectGarbage();
        }
    }
}
