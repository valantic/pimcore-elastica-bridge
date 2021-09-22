<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Index;

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
        if (!$this->hasTenant() && $this->hasDefaultTenant()) {
            return $this->getDefaultTenant();
        }

        if (!$this->hasTenant()) {
            throw new TenantNotSetException();
        }

        return $this->activeTenant;
    }

    public function setTenant(string $tenant): void
    {
        $this->activeTenant = $tenant;
    }

    public function getName(): string
    {
        return sprintf('%s_%s', $this->getTenantUnawareName(), $this->getTenant());
    }

    public function resetTenant(): void
    {
        // intentionally left blank
    }
}
