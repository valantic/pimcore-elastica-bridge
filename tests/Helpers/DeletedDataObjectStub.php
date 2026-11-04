<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Helpers;

use Pimcore\Model\DataObject\Concrete;

/**
 * A data object that can no longer be loaded, as it was deleted.
 */
class DeletedDataObjectStub extends Concrete
{
    public static function getById(int|string $id, array $params = []): ?static
    {
        return null;
    }
}
