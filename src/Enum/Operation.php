<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Enum;

enum Operation: int
{
    case NOTHING = 0;
    case DELETE = 1;
    case INSERT = 2;
    case UPDATE = 3;
}
