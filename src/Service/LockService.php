<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Service;

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
    ) {}

    public function getIndexingLock(IndexInterface $indexConfig): LockInterface
    {
        return $this->lockFactory
            ->createLockFromKey(
                $this->getIndexingKey($indexConfig),
                ttl: $this->configurationRepository->getIndexingLockTimeout(),
                autoRelease: false
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
        $lock = $this->lockFactory->createLockFromKey($key, ttl: $this->configurationRepository->getIndexingLockTimeout(), autoRelease: false);
        $lock->acquire();

        return $key;
    }

    public function isExecutionLocked(string $document): bool
    {
        $key = $this->getKey($document, 'failure');

        return !$this->lockFactory->createLockFromKey($key)->acquire();
    }

    public function lockSwitchBlueGreen(IndexInterface $indexConfig): Key
    {
        $key = $this->getKey($indexConfig->getName(), 'switch-blue-green');
        $lock = $this->lockFactory->createLockFromKey($key, ttl: 2 * $this->configurationRepository->getIndexingLockTimeout(), autoRelease: false);
        $lock->acquire();

        return $key;
    }

    public function allMessagesProcessed(string $indexName): bool
    {
        // the count is eventually consistent.
        $currentCount = $this->getCurrentCount($indexName);

        if (
            Index::$isAsync === false
            || (Index::$isAsync === null && $this->configurationRepository->shouldPopulateAsync() === false)
        ) {
            return $currentCount === 0;
        }

        $key = $this->getKey($indexName, 'switch-blue-green');
        $lock = $this->createLockFromKey($key, ttl: 0, autorelease: true);

        if ($currentCount > 0) {
            return false;
        }

        if (!$lock->acquire()) {
            return false;
        }

        $lock->release(); // release the lock instantly as we just checked
        $cacheKey = self::LOCK_PREFIX . $indexName;
        $this->redis->del($cacheKey); // clean up the cache key.

        return true;
    }

    public function initializeProcessCount(string $name): void
    {
        $cacheKey = self::LOCK_PREFIX . $name;
        $this->redis->set($cacheKey, 0);
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
}
