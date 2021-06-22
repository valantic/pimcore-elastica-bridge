<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Index;

interface TenantAwareInterface
{
    /**
     * @return string[]
     */
    public function getTenants(): array;

    public function hasTenant(): bool;

    public function getTenant(): string;

    public function setTenant(string $tenant): void;

    public function getTenantUnawareName(): string;

    public function hasDefaultTenant(): bool;

    public function getDefaultTenant(): string;
}
