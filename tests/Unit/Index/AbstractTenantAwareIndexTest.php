<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Index;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;
use Valantic\ElasticaBridgeBundle\Exception\Index\TenantNotSetException;
use Valantic\ElasticaBridgeBundle\Index\AbstractTenantAwareIndex;
use Valantic\ElasticaBridgeBundle\Repository\DocumentRepository;

class AbstractTenantAwareIndexTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private ElasticsearchClient $client;
    private DocumentRepository $documentRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = \Mockery::mock(ElasticsearchClient::class);
        $this->documentRepository = \Mockery::mock(DocumentRepository::class);
    }

    public function testHasTenantReturnsFalseWhenNotSet(): void
    {
        $index = $this->createTestIndex();

        $this->assertFalse($index->hasTenant());
    }

    public function testHasTenantReturnsTrueAfterSetting(): void
    {
        $index = $this->createTestIndex();
        $index->setTenant('tenant1');

        $this->assertTrue($index->hasTenant());
    }

    public function testSetTenantSetsActiveTenant(): void
    {
        $index = $this->createTestIndex();
        $index->setTenant('my_tenant');

        $this->assertSame('my_tenant', $index->getTenant());
    }

    public function testGetTenantThrowsExceptionWhenNotSet(): void
    {
        $index = $this->createTestIndex(['tenant1', 'tenant2']);

        $this->expectException(TenantNotSetException::class);

        $index->getTenant();
    }

    public function testGetTenantReturnsDefaultWhenAvailable(): void
    {
        $index = $this->createTestIndex(['tenant1', 'default'], 'default');

        $this->assertSame('default', $index->getTenant());
    }

    public function testGetNameIncludesTenant(): void
    {
        $index = $this->createTestIndex();
        $index->setTenant('test_tenant');

        $this->assertSame('base_index_test_tenant', $index->getName());
    }

    public function testHasDefaultTenantReturnsTrueWhenDefaultInTenants(): void
    {
        $index = $this->createTestIndex(['tenant1', 'default'], 'default');

        $this->assertTrue($index->hasDefaultTenant());
    }

    public function testHasDefaultTenantReturnsFalseWhenDefaultNotInTenants(): void
    {
        $index = $this->createTestIndex(['tenant1', 'tenant2'], 'default');

        $this->assertFalse($index->hasDefaultTenant());
    }

    public function testResetTenantDoesNotThrow(): void
    {
        $index = $this->createTestIndex();
        $index->setTenant('tenant1');

        $index->resetTenant();

        // Should not throw an exception
        $this->assertTrue(true);
    }

    private function createTestIndex(array $tenants = ['tenant1'], ?string $defaultTenant = null): AbstractTenantAwareIndex
    {
        return new class($this->client, $this->documentRepository, $tenants, $defaultTenant) extends AbstractTenantAwareIndex {
            public function __construct(
                ElasticsearchClient $client,
                DocumentRepository $documentRepository,
                private readonly array $tenants,
                private readonly ?string $defaultTenant = null,
            ) {
                parent::__construct($client, $documentRepository);
            }

            public function getTenantUnawareName(): string
            {
                return 'base_index';
            }

            public function getTenants(): array
            {
                return $this->tenants;
            }

            public function getDefaultTenant(): string
            {
                return $this->defaultTenant ?? 'default';
            }

            public function getAllowedDocuments(): array
            {
                return [];
            }
        };
    }
}
