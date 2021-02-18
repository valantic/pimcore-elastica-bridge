<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Exception\DocumentType;

use Valantic\ElasticaBridgeBundle\Exception\BaseException;

class UnknownPimcoreElementType extends BaseException
{
    public function __construct(?string $name)
    {
        parent::__construct(sprintf('Unknown Pimcore element of type %s', $name), 0, null);
    }
}
