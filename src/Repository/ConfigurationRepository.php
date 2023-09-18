<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Repository;

use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;

class ConfigurationRepository
{
    public function __construct(
        private readonly ContainerBagInterface $containerBag,
    ) {}

    /**
     * @return array{host:string,port:int}|string
     */
    public function getClient(): array|string
    {
        $config = $this->containerBag->get('valantic_elastica_bridge')['client'];

        return $config['dsn'] !== null && $config['dsn'] !== ''
              ? $config['dsn']
              : [
                  'host' => $config['host'],
                  'port' => $config['port'],
              ];
    }

    public function getAddSentryBreadcrumbs(): bool
    {
        return $this->containerBag->get('valantic_elastica_bridge')['client']['addSentryBreadcrumbs'];
    }

    public function getIndexingLockTimeout(): int
    {
        return $this->containerBag->get('valantic_elastica_bridge')['indexing']['lock_timeout'];
    }
}
