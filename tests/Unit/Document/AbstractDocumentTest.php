<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Document;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Folder;
use Valantic\ElasticaBridgeBundle\Document\AbstractDocument;
use Valantic\ElasticaBridgeBundle\Enum\DocumentType;
use Valantic\ElasticaBridgeBundle\Tests\Helpers\PimcoreElementFactory;

class AbstractDocumentTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testGetElasticsearchIdForAsset(): void
    {
        $asset = PimcoreElementFactory::createAsset(123);

        $id = AbstractDocument::getElasticsearchId($asset);

        $this->assertSame('asset123', $id);
    }

    public function testGetElasticsearchIdForDocument(): void
    {
        $document = PimcoreElementFactory::createDocument(456);

        $id = AbstractDocument::getElasticsearchId($document);

        $this->assertSame('document456', $id);
    }

    public function testGetElasticsearchIdForDataObject(): void
    {
        $dataObject = PimcoreElementFactory::createDataObject(789);

        $id = AbstractDocument::getElasticsearchId($dataObject);

        $this->assertSame('object789', $id);
    }

    public function testGetElasticsearchIdForVariant(): void
    {
        $variant = PimcoreElementFactory::createVariant(101);

        $id = AbstractDocument::getElasticsearchId($variant);

        $this->assertSame('object101', $id);
    }

    public function testGetElasticsearchIdForFolder(): void
    {
        $folder = \Mockery::mock(Folder::class);
        $folder->shouldReceive('getId')->andReturn(999);
        $folder->shouldReceive('getType')->andReturn('folder');

        $id = AbstractDocument::getElasticsearchId($folder);

        $this->assertSame('object999', $id);
    }

    public function testTreatObjectVariantsAsDocumentsDefaultsFalse(): void
    {
        $document = $this->createTestDocument();

        $this->assertFalse($document->treatObjectVariantsAsDocuments());
    }

    private function createTestDocument(): AbstractDocument
    {
        return new class extends AbstractDocument {
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
