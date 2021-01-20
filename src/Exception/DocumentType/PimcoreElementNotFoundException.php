<?php

namespace Valantic\ElasticaBridgeBundle\Exception\DocumentType;

use Valantic\ElasticaBridgeBundle\Exception\BaseException;

class PimcoreElementNotFoundException extends BaseException
{
    public function __construct(int $id)
    {
        parent::__construct(sprintf('Pimcore element with ID %d could not be found', $id), 0, null);
    }
}
