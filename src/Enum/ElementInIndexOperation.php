<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Enum;

enum ElementInIndexOperation
{
    case INSERT;
    case UPDATE;
    case DELETE;
    case NOTHING;
}
