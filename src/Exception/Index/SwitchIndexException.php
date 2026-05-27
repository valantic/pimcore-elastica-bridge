<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Exception\Index;

use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;
use Valantic\ElasticaBridgeBundle\Exception\BaseException;

class SwitchIndexException extends BaseException implements UnrecoverableExceptionInterface {}
