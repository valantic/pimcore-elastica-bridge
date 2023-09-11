<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Document;

use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\Exception\Repository\TenantNotSetException;

/**
 * @template TElement of AbstractElement
 *
 * @extends  AbstractDocument<TElement>
 *
 * @implements TenantAwareInterface<TElement>
 */
abstract class AbstractTenantAwareDocument extends AbstractDocument implements TenantAwareInterface
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
