<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Document;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Concrete;
use Valantic\ElasticaBridgeBundle\Document\DataObjectNormalizerTrait;
use Valantic\ElasticaBridgeBundle\Document\DocumentInterface;

class DataObjectNormalizerTraitTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testChildrenRecursiveReturnsDescendantIdsAsIntegers(): void
    {
        $connection = \Mockery::mock(Connection::class);
        $connection
            ->shouldReceive('fetchFirstColumn')
            ->once()
            ->with(
                \Mockery::on(static fn (string $query): bool => str_contains($query, 'WHERE parentId = ?')
                    && substr_count($query, 'published = 1') === 2
                    && substr_count($query, 'IN (?)') === 2),
                [42, [AbstractObject::OBJECT_TYPE_OBJECT, AbstractObject::OBJECT_TYPE_FOLDER], [AbstractObject::OBJECT_TYPE_OBJECT, AbstractObject::OBJECT_TYPE_FOLDER]],
                [ParameterType::INTEGER, ArrayParameterType::STRING, ArrayParameterType::STRING],
            )
            ->andReturn(['43', '44', 45])
        ;

        $result = $this->createNormalizer($connection)->callChildrenRecursive($this->createElement(42));

        $this->assertSame([DocumentInterface::ATTRIBUTE_CHILDREN_RECURSIVE => [43, 44, 45]], $result);
    }

    public function testChildrenRecursivePassesCustomObjectTypes(): void
    {
        $connection = \Mockery::mock(Connection::class);
        $connection
            ->shouldReceive('fetchFirstColumn')
            ->once()
            ->with(
                \Mockery::type('string'),
                [7, [AbstractObject::OBJECT_TYPE_VARIANT], [AbstractObject::OBJECT_TYPE_VARIANT]],
                \Mockery::type('array'),
            )
            ->andReturn([])
        ;

        $result = $this->createNormalizer($connection)->callChildrenRecursive($this->createElement(7), [AbstractObject::OBJECT_TYPE_VARIANT]);

        $this->assertSame([DocumentInterface::ATTRIBUTE_CHILDREN_RECURSIVE => []], $result);
    }

    public function testChildrenRecursiveWithoutObjectTypesSkipsQuery(): void
    {
        $connection = \Mockery::mock(Connection::class);
        $connection->shouldNotReceive('fetchFirstColumn');

        $result = $this->createNormalizer($connection)->callChildrenRecursive($this->createElement(1), []);

        $this->assertSame([DocumentInterface::ATTRIBUTE_CHILDREN_RECURSIVE => []], $result);
    }

    private function createElement(int $id): Concrete
    {
        $element = \Mockery::mock(Concrete::class);
        $element->shouldReceive('getId')->andReturn($id);

        return $element;
    }

    private function createNormalizer(Connection $connection): object
    {
        $normalizer = new class {
            use DataObjectNormalizerTrait;

            /**
             * @param string[] $objectTypes
             *
             * @return array{childrenRecursive: array<int, int>}
             */
            public function callChildrenRecursive(Concrete $element, ?array $objectTypes = null): array
            {
                return $objectTypes === null
                    ? $this->childrenRecursive($element)
                    : $this->childrenRecursive($element, $objectTypes);
            }
        };
        $normalizer->setDatabaseConnection($connection);

        return $normalizer;
    }
}
