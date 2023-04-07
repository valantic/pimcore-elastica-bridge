<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Enum;

enum DocumentType: string
{
    case ASSET = 'asset';
    case DOCUMENT = 'document';
    case DATA_OBJECT = 'object';
    case VARIANT = 'variant';

    /** @return self[] */
    public static function casesDataObjects()
    {
        return [self::DATA_OBJECT, self::VARIANT];
    }

    /** @return self[] */
    public static function casesPublishedState()
    {
        return [self::DATA_OBJECT, self::DOCUMENT];
    }

    /** @return self[] */
    public static function casesSubTypeListing()
    {
        return [self::ASSET, self::DOCUMENT];
    }
}
