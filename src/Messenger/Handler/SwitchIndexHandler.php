<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Messenger\Handler;

use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\MissingParameterException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Valantic\ElasticaBridgeBundle\Messenger\Message\ReleaseIndexLock;
use Valantic\ElasticaBridgeBundle\Messenger\Message\SwitchIndex;
use Valantic\ElasticaBridgeBundle\Service\LockService;
use Valantic\ElasticaBridgeBundle\Service\PopulateIndexService;

#[AsMessageHandler]
class SwitchIndexHandler
{
    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly LockService $lockService,
        private readonly ConsoleOutputInterface $consoleOutput,
        private readonly MessageBusInterface $messengerBusElasticaBridge,
        private readonly PopulateIndexService $populateIndexService,
    ) {}

    /**
     * @throws ServerResponseException
     * @throws ClientResponseException
     * @throws MissingParameterException
     */
    public function __invoke(ReleaseIndexLock|SwitchIndex $message): void
    {
        $releaseLock = true;
        $maxAttempts = 5; // Set the maximum number of attempts
        $attempt = 0;

        try {
            if (
                (!$message instanceof SwitchIndex)
                || $this->lockService->isExecutionLocked($message->indexName)
            ) {
                return;
            }

            // try to switch index. If not all messages are processed this will be rescheduled. this should be among the last messages in the queue
            // so it can be processed again after all other messages are processed
            $count = $this->lockService->getCurrentCount($message->indexName);

            while (!$this->lockService->allMessagesProcessed($message->indexName, $attempt) && $attempt < $maxAttempts) {
                $seconds = 3 * $attempt;
                $this->consoleOutput->writeln(
                    sprintf(
                        '%s: not all messages processed (~%s remaining; ), attempt %d, trying again in %s seconds',
                        $message->indexName,
                        $count,
                        $attempt + 1,
                        $seconds,
                    ),
                    ConsoleOutputInterface::VERBOSITY_VERBOSE,
                );
                sleep($seconds);
                $attempt++;
            }

            if ($attempt >= $maxAttempts) {
                $delayStamp = new DelayStamp(60 * 1000);
                $this->consoleOutput->writeln(sprintf('Max attempts reached, rescheduling in %s seconds', $delayStamp->getDelay() / 1000), ConsoleOutputInterface::VERBOSITY_VERBOSE);
                $this->messengerBusElasticaBridge->dispatch($message->clone(), [$delayStamp]);
                $releaseLock = false;

                return;
            }

            // @phpstan-ignore-next-line
            if ($this->lockService->isExecutionLocked($message->indexName)) {
                return;
            }

            $this->populateIndexService->switchBlueGreenIndex($message->indexName);
            $this->lockService->initiateCooldown($message->indexName);
        } finally {
            if ($message->key instanceof Key && $releaseLock) {
                $this->consoleOutput->writeln(sprintf('releasing lock %s (%s)', $message->key, hash('sha256', (string) $message->key)), ConsoleOutputInterface::VERBOSITY_VERBOSE);
                $key = $message->key;

                $lock = $this->lockFactory->createLockFromKey($key);
                $lock->release();
            }

            if ($this->lockService->isExecutionLocked($message->indexName)) {
                $this->consoleOutput->writeln(sprintf('Execution is locked for %s. Not switching index.', $message->indexName), ConsoleOutputInterface::VERBOSITY_VERBOSE);
            }

            \Pimcore::collectGarbage();
        }
    }
}
