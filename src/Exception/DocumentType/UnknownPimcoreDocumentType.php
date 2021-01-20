<?php

namespace Valantic\ElasticaBridgeBundle\Exception\DocumentType;

use Valantic\ElasticaBridgeBundle\Exception\BaseException;

class UnknownPimcoreDocumentType extends BaseException
{
    public function __construct(?string $name)
    {
        parent::__construct(sprintf('Unknown Pimcore document type %s', $name), 0, null);
    }
}
