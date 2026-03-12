<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Repository;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Valantic\ElasticaBridgeBundle\Exception\Repository\ItemNotFoundInRepositoryException;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Index\TenantAwareInterface;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;

class IndexRepositoryTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testFlattenedAllYieldsSimpleIndices(): void
    {
        $index1 = \Mockery::mock(IndexInterface::class);
        $index1->shouldReceive('getName')->andReturn('index1');

        $repository = new IndexRepository(new \ArrayIterator([$index1]));

        $result = iterator_to_array($repository->flattenedAll());

        $this->assertIsArray($result);
        $this->assertArrayHasKey('index1', $result);
    }

    public function testFlattenedGetReturnsMatchingIndex(): void
    {
        $index = \Mockery::mock(IndexInterface::class);
        $index->shouldReceive('getName')->andReturn('test_index');

        $repository = new IndexRepository(new \ArrayIterator([$index]));

        $result = $repository->flattenedGet('test_index');

        $this->assertInstanceOf(IndexInterface::class, $result);
    }

    public function testFlattenedGetThrowsExceptionForNonExistentIndex(): void
    {
        $repository = new IndexRepository(new \ArrayIterator([]));

        $this->expectException(ItemNotFoundInRepositoryException::class);

        $repository->flattenedGet('non_existent');
    }

    public function testFlattenedGetWorksWithTenantAwareIndices(): void
    {
        $tenantAwareIndex = \Mockery::mock(IndexInterface::class, TenantAwareInterface::class);
        $tenantAwareIndex->shouldReceive('getTenants')->andReturn(['tenant1']);
        $tenantAwareIndex->shouldReceive('getName')->andReturn('tenant_index');
        $tenantAwareIndex->shouldReceive('setTenant')->with('tenant1');

        $repository = new IndexRepository(new \ArrayIterator([$tenantAwareIndex]));

        $result = $repository->flattenedGet('tenant_index');

        $this->assertInstanceOf(IndexInterface::class, $result);
    }
}
