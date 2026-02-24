<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Constant;

interface CommandConstants
{
    public const OPTION_CONFIG = 'config';

    public const OPTION_INDEX = 'index';

    public const OPTION_BATCH_NUMBER = 'batch-number';

    public const OPTION_LISTING_COUNT = 'listing-count';

    public const OPTION_DOCUMENT = 'document';

    public const COMMAND_NAMESPACE = 'valantic:elastica-bridge:';

    public const COMMAND_INDEX = self::COMMAND_NAMESPACE . 'index';

    public const COMMAND_CLEANUP = self::COMMAND_NAMESPACE . 'cleanup';

    public const COMMAND_REFRESH = self::COMMAND_NAMESPACE . 'refresh';

    public const COMMAND_STATUS = self::COMMAND_NAMESPACE . 'status';

    public const COMMAND_POPULATE_INDEX = self::COMMAND_NAMESPACE . 'populate-index';

    public const COMMAND_DO_POPULATE_INDEX = self::COMMAND_NAMESPACE . 'do-populate-index';
}
