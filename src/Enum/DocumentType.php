<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Enum;

use Pimcore\Model\Asset;
use Pimcore\Model\DataObject;
use Pimcore\Model\Document;

enum DocumentType: string
{
    case ASSET = 'asset';

    case DOCUMENT = 'document';

    case DATA_OBJECT = 'object';

    case VARIANT = 'variant';

    /** @return self[] */
    public static function casesDataObjects(): array
    {
        return [self::DATA_OBJECT, self::VARIANT];
    }

    /** @return self[] */
    public static function casesPublishedState(): array
    {
        return [self::DATA_OBJECT, self::DOCUMENT];
    }

    /** @return self[] */
    public static function casesSubTypeListing(): array
    {
        return [self::ASSET, self::DOCUMENT];
    }

    /**
     * @return class-string
     */
    public function baseClass(): string
    {
        return match ($this) {
            self::ASSET => Asset::class,
            self::DOCUMENT => Document::class,
            self::DATA_OBJECT, self::VARIANT => DataObject::class,
        };
    }
}
