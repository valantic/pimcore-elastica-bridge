<?php

namespace Valantic\ElasticaBridgeBundle\Exception\DocumentType\Index;

use Valantic\ElasticaBridgeBundle\Exception\BaseException;

class EditablePartiallyImplementedException extends BaseException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 0, null);
    }
}
