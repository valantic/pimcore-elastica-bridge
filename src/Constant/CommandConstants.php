<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Constant;

interface CommandConstants
{
    public const COMMAND_NAMESPACE = 'valantic:elastica-bridge:';

    public const COMMAND_INDEX = self::COMMAND_NAMESPACE . 'index';
    public const COMMAND_CLEANUP = self::COMMAND_NAMESPACE . 'cleanup';
    public const COMMAND_REFRESH = self::COMMAND_NAMESPACE . 'refresh';
    public const COMMAND_STATUS = self::COMMAND_NAMESPACE . 'status';
}
