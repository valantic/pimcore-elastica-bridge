<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Exception\DocumentType;

use Valantic\ElasticaBridgeBundle\Exception\BaseException;

class PimcoreListingClassNotFoundException extends BaseException
{
    public function __construct(string $name)
    {
        parent::__construct(sprintf('Pimcore listing class for %s not found', $name));
    }
}
