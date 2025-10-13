<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Service;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Repository\ConfigurationRepository;

class LockService
{
    private const LOCK_PREFIX = 'pimcore-elastica-bridge';

    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly ConfigurationRepository $configurationRepository,
    ) {
    }

    public function getIndexingLock(IndexInterface $indexConfig): LockInterface
    {
        return $this->lockFactory
            ->createLock(
                sprintf('%s:indexing:%s', self::LOCK_PREFIX, $indexConfig->getName()),
                ttl: $this->configurationRepository->getIndexingLockTimeout(),
            )
        ;
    }
}
