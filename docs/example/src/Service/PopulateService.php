<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class PopulateService
{
    public const KEY_NAME_FAILURE = 'failure';
    private const KEY_PREFIX = 'elasticsearch_populate';
    private const REMAINING_MESSAGES = 'remaining_messages';

    public function __construct(
        #[Autowire(service: 'cache.default_redis_provider')]
        private readonly \Redis $redis,
        private readonly Connection $connection,
        private readonly ConsoleOutputInterface $consoleOutput,
    ) {}

    public function decrementRemainingMessages(string $indexName): void
    {
        $this->redis->decr($this->getKeyName($indexName, self::REMAINING_MESSAGES));
    }

    public function incrementRemainingMessages(string $indexName): void
    {
        $this->redis->incr($this->getKeyName($indexName, self::REMAINING_MESSAGES));
    }

    public function getRemainingMessages(string $indexName): int
    {
        return (int) $this->redis->get($this->getKeyName($indexName, self::REMAINING_MESSAGES));
    }

    public function setExpectedMessages(string $indexName, int $expectedMessages): void
    {
        $this->redis->set($this->getKeyName($indexName, self::REMAINING_MESSAGES), $expectedMessages);
    }

    public function getActualMessageCount(string $indexName): int
    {
        $query = "SELECT
        COUNT(mm.id) AS remaining_messages
        FROM messenger_messages mm
        WHERE mm.queue_name = 'elastica_bridge_populate'
          AND mm.body LIKE CONCAT('%\\\\\\\\\"', :indexName, '\\\\\\\\\"%')
          AND mm.delivered_at IS NULL
          AND mm.body LIKE '%CreateDocument%'";

        $count = $this->connection->executeQuery($query, ['indexName' => $indexName, 'indexNameLength' => strlen($indexName)])->fetchOne();

        return (int) $count;
    }

    public function lockExecution(string $document): string
    {
        $key = $this->getKeyName($document, self::KEY_NAME_FAILURE);

        if ($this->isExecutionLocked($document)) {
            return $key;
        }

        $this->redis->set($key, 1, ['NX', 'EX' => 1200]);
        $this->consoleOutput->writeln(sprintf('Locking execution for %s (%s)', $document, $key), ConsoleOutputInterface::VERBOSITY_VERBOSE);

        return $key;
    }

    public function unlockExecution(string $document): void
    {
        $key = $this->getKeyName($document, self::KEY_NAME_FAILURE);

        if ($this->redis->exists($key) === 0) {
            return;
        }

        $this->redis->del($key);
        $this->consoleOutput->writeln(sprintf('Unlocking execution for %s (%s)', $document, hash('sha256', $key)), ConsoleOutputInterface::VERBOSITY_VERBOSE);
    }

    public function isExecutionLocked(string $document): bool
    {
        $key = $this->getKeyName($document, self::KEY_NAME_FAILURE);
        $exists = $this->redis->exists($key);

        if (is_int($exists)) {
            return $exists > 0;
        }

        return false;
    }

    public function getKeyName(string $document, string $type): string
    {
        return sprintf('%s_%s_%s', self::KEY_PREFIX, $document, $type);
    }
}
