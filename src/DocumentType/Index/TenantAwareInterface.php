<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\DocumentType\Index;

interface TenantAwareInterface
{
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
}
