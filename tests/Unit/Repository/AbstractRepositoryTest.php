<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Repository;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Valantic\ElasticaBridgeBundle\Exception\Repository\ItemNotFoundInRepositoryException;
use Valantic\ElasticaBridgeBundle\Repository\AbstractRepository;

class AbstractRepositoryTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testGetReturnsItemByKey(): void
    {
        $item = new \stdClass();
        $item->name = 'test';

        $repository = $this->createRepository([$item]);

        $result = $repository->get($item::class);

        $this->assertSame($item, $result);
    }

    public function testGetThrowsExceptionWhenItemNotFound(): void
    {
        $repository = $this->createRepository([]);

        $this->expectException(ItemNotFoundInRepositoryException::class);

        $repository->get('NonExistent');
    }

    public function testAllReturnsAllItems(): void
    {
        $item1 = new class {
            public string $name = 'first';
        };
        $item2 = new class {
            public string $name = 'second';
        };

        $repository = $this->createRepository([$item1, $item2]);

        $reflection = new \ReflectionClass($repository);
        $method = $reflection->getMethod('all');
        $method->setAccessible(true);

        $result = $method->invoke($repository);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $values = array_values($result);
        $this->assertSame($item1, $values[0]);
        $this->assertSame($item2, $values[1]);
    }

    public function testItemsAreLazilyInitialized(): void
    {
        $item = new \stdClass();

        $repository = $this->createRepository([$item]);

        $reflection = new \ReflectionClass($repository);
        $property = $reflection->getProperty('items');
        $property->setAccessible(true);

        // Items should not be initialized yet
        $this->assertFalse($property->isInitialized($repository));

        // Access items
        $repository->get($item::class);

        // Items should now be initialized
        $this->assertTrue($property->isInitialized($repository));
    }

    private function createRepository(array $items): AbstractRepository
    {
        return new class(new \ArrayIterator($items)) extends AbstractRepository {
            public function get(string $key): mixed
            {
                return parent::get($key);
            }

            public function all(): array
            {
                return parent::all();
            }
        };
    }
}
