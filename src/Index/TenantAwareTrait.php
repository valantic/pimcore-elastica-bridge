<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Index;

use Valantic\ElasticaBridgeBundle\Exception\Index\TenantNotSetException;

trait TenantAwareTrait
{
    protected string $activeTenant;

    public function getTenant(): string
    {
        if (!isset($this->activeTenant) && $this->hasDefaultTenant()) {
            return $this->getDefaultTenant();
        }

        if (!isset($this->activeTenant)) {
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
}
