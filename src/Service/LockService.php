<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Valantic\ElasticaBridgeBundle\Command\Index;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Repository\ConfigurationRepository;

class LockService
{
    private const LOCK_PREFIX = 'pimcore-elastica-bridge';

    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly ConfigurationRepository $configurationRepository,
        #[Autowire(service: 'cache.default_redis_provider')]
        private readonly \Redis $redis,
        private readonly Connection $connection,
        private readonly ConsoleOutputInterface $consoleOutput,
    ) {}

    public function getIndexingLock(IndexInterface $indexConfig, bool $autorelease = false): LockInterface
    {
        return $this->lockFactory
            ->createLockFromKey(
                $this->getIndexingKey($indexConfig),
                ttl: $this->configurationRepository->getIndexingLockTimeout(),
                autoRelease: $autorelease
            );
    }

    public function getIndexingKey(IndexInterface $indexConfig): Key
    {
        return $this->getKey($indexConfig->getName(), 'indexing');
    }

    public function getKey(string $name, string $task): Key
    {
        return new Key(sprintf('%s:%s:%s', self::LOCK_PREFIX, $task, $name));
    }

    public function createLockFromKey(Key $key, ?int $ttl = null, ?bool $autorelease = null): LockInterface
    {
        return $this->lockFactory->createLockFromKey(
            $key,
            ttl: $ttl ?? $this->configurationRepository->getIndexingLockTimeout() + $this->configurationRepository->getCooldown(),
            autoRelease: $autorelease ?? false
        );
    }

    public function lockExecution(string $document): Key
    {
        $key = $this->getKey($document, 'failure');
        $this->redis->set((string) $key, 1, ['NX', 'EX' => 1200]);
        $this->consoleOutput->writeln(sprintf('Locking execution for %s (%s)', $document, hash('sha256', (string) $key)), ConsoleOutputInterface::VERBOSITY_VERBOSE);

        return $key;
    }

    public function isExecutionLocked(string $document): bool
    {
        $key = $this->getKey($document, 'failure');
        $exists = $this->redis->exists((string) $key);

        if (is_int($exists)) {
            return $exists > 0;
        }

        return false;
    }

    public function allMessagesProcessed(string $indexName, int $attempt = 0): bool
    {
        // the count is eventually consistent.
        $currentCount = $this->getCurrentCount($indexName);

        if (
            Index::$isAsync === false
            || (Index::$isAsync === null && $this->configurationRepository->shouldPopulateAsync() === false)
        ) {
            return $currentCount === 0;
        }

        if ($currentCount > 0 && $attempt < 3) {
            return false;
        }

        $actualMessageCount = $this->getActualMessageCount($indexName);

        if ($actualMessageCount > 0 && $currentCount !== $actualMessageCount) {
            $this->consoleOutput->writeln(sprintf('%s: %d messages remaining in db. %d in cache.', $indexName, $actualMessageCount, $currentCount), ConsoleOutputInterface::VERBOSITY_VERBOSE);
            $this->initializeProcessCount($indexName, $actualMessageCount);
        }

        if ($attempt > 2) {
            $this->consoleOutput->writeln(sprintf('%s: %d attempts reached. Getting data from db. (%d => %d)', $indexName, $attempt, $currentCount, $actualMessageCount), ConsoleOutputInterface::VERBOSITY_VERBOSE);
        }

        if ($actualMessageCount > 0) {
            return false;
        }

        $cacheKey = self::LOCK_PREFIX . $indexName;
        $this->redis->del($cacheKey); // clean up the cache key.

        return true;
    }

    public function initializeProcessCount(string $name, int $count = 0): void
    {
        $cacheKey = self::LOCK_PREFIX . $name;
        $this->redis->set($cacheKey, $count);
    }

    public function messageProcessed(string $esIndex): void
    {
        $cacheKey = self::LOCK_PREFIX . $esIndex;
        $this->redis->decr($cacheKey);
    }

    public function getCurrentCount(string $indexName): int
    {
        $cacheKey = self::LOCK_PREFIX . $indexName;
        $count = $this->redis->get($cacheKey);

        if ($count === false) {
            $count = 0;
        }

        return (int) $count;
    }

    public function messageDispatched(string $getName): void
    {
        $cacheKey = self::LOCK_PREFIX . $getName;
        $this->redis->incr($cacheKey);
    }

    public function getActualMessageCount(string $indexName): int
    {
        $query = "SELECT
        COUNT(mm.id) AS remaining_messages
        FROM messenger_messages mm
        WHERE mm.queue_name = \"elastica_bridge_populate\"
          AND mm.body LIKE CONCAT('%\\\\\\\\\"', :indexName, '\\\\\\\\\"%')
          AND mm.delivered_at IS NULL
          AND mm.body LIKE \"%CreateDocument%\"";

        $count = $this->connection->executeQuery($query, ['indexName' => $indexName, 'indexNameLength' => strlen($indexName)])->fetchOne();

        return (int) $count;
    }
}
