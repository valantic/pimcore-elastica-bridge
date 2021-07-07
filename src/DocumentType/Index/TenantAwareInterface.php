<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\DocumentType\Index;

interface TenantAwareInterface
{
    public function hasTenant(): bool;

    public function getTenant(): string;

    public function setTenant(string $tenant): void;
}
