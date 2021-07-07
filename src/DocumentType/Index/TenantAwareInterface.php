<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\DocumentType\Index;

interface TenantAwareInterface
{
    /**
     * Indicates whether a tenant is currently active.
     *
     * @return bool
     */
    public function hasTenant(): bool;

    /**
     * Returns the active tenant.
     *
     * @return string
     */
    public function getTenant(): string;

    /**
     * Set the active tenant.
     *
     * @param string $tenant
     */
    public function setTenant(string $tenant): void;
}
