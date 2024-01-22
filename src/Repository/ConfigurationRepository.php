<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Repository;

use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;

/**
 * @internal
 */
class ConfigurationRepository
{
    public function __construct(
        private readonly ContainerBagInterface $containerBag,
    ) {}

    public function getClientDsn(): string
    {
        return $this->containerBag->get('valantic_elastica_bridge')['client']['dsn'];
    }

    public function shouldAddSentryBreadcrumbs(): bool
    {
        return $this->containerBag->get('valantic_elastica_bridge')['client']['should_add_sentry_breadcrumbs'];
    }

    public function getIndexingLockTimeout(): int
    {
        return $this->containerBag->get('valantic_elastica_bridge')['indexing']['lock_timeout'];
    }
}
