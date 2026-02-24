<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Enum;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pimcore\Model\Asset;
use Pimcore\Model\Document;
use Valantic\ElasticaBridgeBundle\Enum\DocumentType;

class DocumentTypeTest extends TestCase
{
    public static function baseClassProvider(): array
    {
        return [
            'asset' => [DocumentType::ASSET, Asset::class],
            'document' => [DocumentType::DOCUMENT, Document::class],
            'data_object' => [DocumentType::DATA_OBJECT, \Pimcore\Model\DataObject::class],
            'variant' => [DocumentType::VARIANT, \Pimcore\Model\DataObject::class],
        ];
    }

    public function testAssetHasCorrectValue(): void
    {
        $this->assertSame('asset', DocumentType::ASSET->value);
    }

    public function testDocumentHasCorrectValue(): void
    {
        $this->assertSame('document', DocumentType::DOCUMENT->value);
    }

    public function testDataObjectHasCorrectValue(): void
    {
        $this->assertSame('object', DocumentType::DATA_OBJECT->value);
    }

    public function testVariantHasCorrectValue(): void
    {
        $this->assertSame('variant', DocumentType::VARIANT->value);
    }

    #[DataProvider('baseClassProvider')]
    public function testBaseClass(DocumentType $type, string $expectedClass): void
    {
        $this->assertSame($expectedClass, $type->baseClass());
    }

    public function testCasesDataObjects(): void
    {
        $cases = DocumentType::casesDataObjects();

        $this->assertContains(DocumentType::DATA_OBJECT, $cases);
        $this->assertContains(DocumentType::VARIANT, $cases);
        $this->assertNotContains(DocumentType::ASSET, $cases);
        $this->assertNotContains(DocumentType::DOCUMENT, $cases);
    }

    public function testCasesPublishedState(): void
    {
        $cases = DocumentType::casesPublishedState();

        $this->assertContains(DocumentType::DOCUMENT, $cases);
        $this->assertContains(DocumentType::DATA_OBJECT, $cases);
        $this->assertNotContains(DocumentType::VARIANT, $cases);
        $this->assertNotContains(DocumentType::ASSET, $cases);
    }

    public function testCasesSubTypeListing(): void
    {
        $cases = DocumentType::casesSubTypeListing();

        $this->assertContains(DocumentType::ASSET, $cases);
        $this->assertContains(DocumentType::DOCUMENT, $cases);
    }
}
