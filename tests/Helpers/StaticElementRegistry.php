<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Helpers;

use Pimcore\Model\Element\AbstractElement;

/**
 * Stands in for a Pimcore element class where code loads elements via $elementType::getById().
 */
class StaticElementRegistry
{
    /**
     * @var array<int, AbstractElement>
     */
    public static array $elements = [];

    public static function getById(int $id): ?AbstractElement
    {
        return self::$elements[$id] ?? null;
    }
}
