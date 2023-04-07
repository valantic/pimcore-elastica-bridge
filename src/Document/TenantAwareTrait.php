<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Document;

use Valantic\ElasticaBridgeBundle\Exception\Index\TenantNotSetException;

trait TenantAwareTrait
{
    protected string $activeTenant;

    public function hasTenant(): bool
    {
        return isset($this->activeTenant);
    }

    public function getTenant(): string
    {
        if (!$this->hasTenant()) {
            throw new TenantNotSetException();
        }

        return $this->activeTenant;
    }

    public function setTenant(string $tenant): void
    {
        $this->activeTenant = $tenant;
    }

    public function resetTenant(): void
    {
        // intentionally left blank
    }
}
