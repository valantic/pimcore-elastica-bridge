<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Helpers;

use Pimcore\Model\DataObject\Concrete;

/**
 * Data object whose getById() works without a database: only IDs in $existingIds are found.
 */
class StubDataObject extends Concrete
{
    /**
     * @var int[]
     */
    public static array $existingIds = [];

    public static function getById(int $id, array $params = []): ?static
    {
        if (!in_array($id, static::$existingIds, true)) {
            return null;
        }

        $object = new static();
        $object->setId($id);

        return $object;
    }
}
