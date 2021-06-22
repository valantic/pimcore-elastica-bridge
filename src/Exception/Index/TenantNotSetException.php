<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Exception\Index;

use Valantic\ElasticaBridgeBundle\Exception\BaseException;

class TenantNotSetException extends BaseException
{
    public function __construct()
    {
        parent::__construct('Tenant has not been set yet');
    }
}
