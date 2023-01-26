<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Exception\DocumentType\Index;

use Valantic\ElasticaBridgeBundle\Exception\BaseException;

class UnknownEditableException extends BaseException
{
    public function __construct(string $editableName)
    {
        parent::__construct(sprintf('No method for editable of type %s found', $editableName));
    }
}
