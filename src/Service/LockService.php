<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Service;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Repository\ConfigurationRepository;

class LockService
{
    private const string LOCK_PREFIX = 'pimcore-elastica-bridge';

    /**
     * @var Key[]
     */
    private array $indexingKey = [];

    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly ConfigurationRepository $configurationRepository,
        private readonly ConsoleOutputInterface $consoleOutput,
    ) {
    }

    public function getIndexingLock(IndexInterface $indexConfig, bool $autorelease = false): LockInterface
    {
        return $this->lockFactory
            ->createLockFromKey(
                $this->getIndexingKey($indexConfig),
                ttl: $this->configurationRepository->getIndexingLockTimeout(),
                autoRelease: $autorelease,
            )
        ;
    }

    /**
     * Whether any process, including this one, currently holds the indexing lock of $indexConfig.
     */
    public function isIndexingLocked(IndexInterface $indexConfig): bool
    {
        // a fresh key has its own token, so a lock held via getIndexingKey() in this process counts as well
        $lock = $this->lockFactory->createLockFromKey($this->getKey($indexConfig->getName(), 'indexing'), autoRelease: false);

        if (!$lock->acquire()) {
            return true;
        }

        $lock->release();

        return false;
    }

    public function getIndexingKey(IndexInterface $indexConfig): Key
    {
        return $this->indexingKey[$indexConfig->getName()] ??= $this->getKey($indexConfig->getName(), 'indexing');
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
            autoRelease: $autorelease ?? false,
        );
    }

    public function initiateCooldown(string $indexName): void
    {
        $cooldown = $this->configurationRepository->getCooldown();
        $this->lockFactory->createLockFromKey($this->getKey($indexName, 'cooldown'), $cooldown, false)->acquire();
        $this->consoleOutput->writeln(sprintf('Cooldown initiated for %s', $indexName), ConsoleOutputInterface::VERBOSITY_VERBOSE);
    }
}
