<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Helpers;

use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\Document;

class PimcoreElementFactory
{
    public static function createDataObject(
        int $id = 1,
        bool $published = true,
        string $type = AbstractObject::OBJECT_TYPE_OBJECT,
    ): Concrete {
        $mock = \Mockery::mock(Concrete::class);
        $mock->shouldReceive('getId')->andReturn($id);
        $mock->shouldReceive('getType')->andReturn('object');
        $mock->shouldReceive('isPublished')->andReturn($published);
        $mock->shouldReceive('getPublished')->andReturn($published);
        $mock->shouldReceive('getCreationDate')->andReturn(time());
        $mock->shouldReceive('getModificationDate')->andReturn(time());

        return $mock;
    }

    public static function createAsset(int $id = 1): Asset
    {
        $mock = \Mockery::mock(Asset::class);
        $mock->shouldReceive('getId')->andReturn($id);
        $mock->shouldReceive('getType')->andReturn('asset');
        $mock->shouldReceive('getCreationDate')->andReturn(time());
        $mock->shouldReceive('getModificationDate')->andReturn(time());

        return $mock;
    }

    public static function createDocument(int $id = 1, bool $published = true): Document
    {
        $mock = \Mockery::mock(Document::class);
        $mock->shouldReceive('getId')->andReturn($id);
        $mock->shouldReceive('getType')->andReturn('document');
        $mock->shouldReceive('isPublished')->andReturn($published);
        $mock->shouldReceive('getPublished')->andReturn($published);
        $mock->shouldReceive('getCreationDate')->andReturn(time());
        $mock->shouldReceive('getModificationDate')->andReturn(time());

        return $mock;
    }

    public static function createVariant(int $id = 1, bool $published = true): Concrete
    {
        $mock = \Mockery::mock(Concrete::class);
        $mock->shouldReceive('getId')->andReturn($id);
        $mock->shouldReceive('getType')->andReturn('variant');
        $mock->shouldReceive('isPublished')->andReturn($published);
        $mock->shouldReceive('getPublished')->andReturn($published);
        $mock->shouldReceive('getCreationDate')->andReturn(time());
        $mock->shouldReceive('getModificationDate')->andReturn(time());

        return $mock;
    }
}
