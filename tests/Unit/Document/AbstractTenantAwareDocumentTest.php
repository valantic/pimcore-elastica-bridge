<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Document;

use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\Concrete;
use Valantic\ElasticaBridgeBundle\Document\AbstractTenantAwareDocument;
use Valantic\ElasticaBridgeBundle\Enum\DocumentType;
use Valantic\ElasticaBridgeBundle\Exception\Index\TenantNotSetException;

class AbstractTenantAwareDocumentTest extends TestCase
{
    public function testHasTenantReturnsFalseWhenNotSet(): void
    {
        $document = $this->createTestDocument();

        $this->assertFalse($document->hasTenant());
    }

    public function testHasTenantReturnsTrueAfterSetting(): void
    {
        $document = $this->createTestDocument();
        $document->setTenant('tenant1');

        $this->assertTrue($document->hasTenant());
    }

    public function testSetTenantSetsActiveTenant(): void
    {
        $document = $this->createTestDocument();
        $document->setTenant('my_tenant');

        $this->assertSame('my_tenant', $document->getTenant());
    }

    public function testGetTenantThrowsExceptionWhenNotSet(): void
    {
        $document = $this->createTestDocument();

        $this->expectException(TenantNotSetException::class);

        $document->getTenant();
    }

    public function testGetTenantReturnsSetTenant(): void
    {
        $document = $this->createTestDocument();
        $document->setTenant('test_tenant');

        $this->assertSame('test_tenant', $document->getTenant());
    }

    public function testResetTenantDoesNotThrow(): void
    {
        $document = $this->createTestDocument();
        $document->setTenant('tenant1');

        $document->resetTenant();

        // Should not throw an exception
        $this->assertTrue(true);
    }

    public function testMultipleTenantChanges(): void
    {
        $document = $this->createTestDocument();

        $document->setTenant('tenant1');
        $this->assertSame('tenant1', $document->getTenant());

        $document->setTenant('tenant2');
        $this->assertSame('tenant2', $document->getTenant());

        $document->setTenant('tenant3');
        $this->assertSame('tenant3', $document->getTenant());
    }

    private function createTestDocument(): AbstractTenantAwareDocument
    {
        return new class extends AbstractTenantAwareDocument {
            public function getType(): DocumentType
            {
                return DocumentType::DATA_OBJECT;
            }

            public function getSubType(): ?string
            {
                return Concrete::class;
            }

            public function shouldIndex(\Pimcore\Model\Element\AbstractElement $element): bool
            {
                return true;
            }

            public function getNormalized(\Pimcore\Model\Element\AbstractElement $element): array
            {
                return [];
            }

            public function getIndexListingCondition(): ?string
            {
                return null;
            }

            public function includeUnpublishedElementsInListing(): bool
            {
                return false;
            }

            public function getListingClass(): string
            {
                return Concrete\Listing::class;
            }
        };
    }
}
