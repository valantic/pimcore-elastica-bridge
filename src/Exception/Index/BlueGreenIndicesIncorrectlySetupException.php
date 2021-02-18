<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Exception\Index;

use Valantic\ElasticaBridgeBundle\Exception\BaseException;

class BlueGreenIndicesIncorrectlySetupException extends BaseException
{
    public function __construct()
    {
        parent::__construct('Blue-green indices are not set up correctly');
    }
}
