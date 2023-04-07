<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Enum;

enum IndexBlueGreenSuffix: string
{
    case BLUE = '--blue';
    case GREEN = '--green';
}
