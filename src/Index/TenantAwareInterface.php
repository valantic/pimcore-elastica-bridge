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
     */
    public function hasTenant(): bool;

    /**
     * Returns the active tenant.
     */
    public function getTenant(): string;

    /**
     * Set the active tenant.
     * Useful for overwriting to automatically set the tenant based on context.
     */
    public function setTenant(string $tenant): void;

    /**
     * Reset the active tenant.
     * Useful for resetting the tenant after processing the index, especially in the context
     * of the event listener in combination with Pimcore Sites.
     *
     * The implementation (together with setTenant())
     * is responsible for keeping track of the "old" tenant (which may not be $this->activeTenant but e.g.
     * the currently active Site).
     */
    public function resetTenant(): void;

    /**
     * Used instead of getName(). In a tenant context, getName() includes the tenant name and corresponds to the
     * name of the tenant in Elasticsearch. This method creates the base name for the tenant-specific name.
     *
     * @see IndexInterface::getName()
     */
    public function getTenantUnawareName(): string;

    /**
     * Indicates whether this index has a default tenant.
     */
    public function hasDefaultTenant(): bool;

    /**
     * Returns the default tenant for this index.
     * Useful for overwriting to automatically set the tenant based on context.
     */
    public function getDefaultTenant(): string;
}
