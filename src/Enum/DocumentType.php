<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Enum;

use Pimcore\Model;

enum DocumentType: string
{
    case ASSET = 'asset';

    case DOCUMENT = 'document';

    case DATA_OBJECT = 'object';

    case VARIANT = 'variant';

    /**
     * @return class-string
     */
    public function baseClass(): string
    {
        return match ($this) {
            self::ASSET => Model\Asset::class,
            self::DOCUMENT => Model\Document::class,
            self::DATA_OBJECT, self::VARIANT => Model\DataObject::class,
        };
    }

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
}
