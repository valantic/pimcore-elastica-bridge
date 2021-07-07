<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Index;

interface TenantAwareInterface
{
    /**
     * Returns a list of tenants supported by this index.
     * A tenant is represented by a (unique) string.
     *
     * @return string[]
     */
    public function getTenants(): array;

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
     * Useful for overwriting to automatically set the tenant based on context.
     *
     * @param string $tenant
     */
    public function setTenant(string $tenant): void;

    /**
     * Used instead of getName(). In a tenant context, getName() includes the tenant name and corresponds to the
     * name of the tenant in Elasticsearch. This method creates the base name for the tenant-specific name.
     *
     * @return string
     *
     * @see IndexInterface::getName()
     */
    public function getTenantUnawareName(): string;

    /**
     * Indicates whether this index has a default tenant.
     *
     * @return bool
     */
    public function hasDefaultTenant(): bool;

    /**
     * Returns the default tenant for this index.
     * Useful for overwriting to automatically set the tenant based on context.
     *
     * @return string
     */
    public function getDefaultTenant(): string;
}
