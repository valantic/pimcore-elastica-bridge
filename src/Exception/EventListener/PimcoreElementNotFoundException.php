<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Exception\EventListener;

use Valantic\ElasticaBridgeBundle\Exception\BaseException;

class PimcoreElementNotFoundException extends BaseException
{
    public function __construct(?int $id, string $type)
    {
        parent::__construct(sprintf('Pimcore element ID %d of type %s not found', $id, $type));
    }
}
