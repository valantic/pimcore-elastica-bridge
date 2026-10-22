<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Service;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Valantic\ElasticaBridgeBundle\Document\DocumentInterface;
use Valantic\ElasticaBridgeBundle\Document\ParamsAwareInterface;
use Valantic\ElasticaBridgeBundle\Document\TenantAwareInterface as DocumentTenantAwareInterface;
use Valantic\ElasticaBridgeBundle\Enum\DocumentType;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Index\TenantAwareInterface as IndexTenantAwareInterface;
use Valantic\ElasticaBridgeBundle\Service\DocumentHelper;
use Valantic\ElasticaBridgeBundle\Tests\Helpers\PimcoreElementFactory;

class DocumentHelperTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private DocumentHelper $helper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->helper = new DocumentHelper();
    }

    public function testElementToDocumentCreatesElasticaDocument(): void
    {
        $element = PimcoreElementFactory::createDataObject(123);

        $document = \Mockery::mock(DocumentInterface::class);
        $document->shouldReceive('getType')->andReturn(DocumentType::DATA_OBJECT);
        $document->shouldReceive('getSubType')->andReturn('TestClass');
        $document->shouldReceive('getNormalized')->with($element)->andReturn(['field' => 'value']);
        $document->shouldReceive('getElasticsearchId')->with($element)->andReturn('object123');

        $result = $this->helper->elementToDocument($document, $element);

        $this->assertSame('object123', $result->getId());
        $this->assertArrayHasKey('field', $result->getData());
        $this->assertSame('value', $result->getData()['field']);
        $this->assertArrayHasKey(DocumentInterface::META_TYPE, $result->getData());
        $this->assertArrayHasKey(DocumentInterface::META_SUB_TYPE, $result->getData());
        $this->assertArrayHasKey(DocumentInterface::META_ID, $result->getData());
        $this->assertSame(123, $result->getData()[DocumentInterface::META_ID]);
    }

    public function testSetTenantIfNeededSetsTenantWhenBothAreTenantAware(): void
    {
        $document = \Mockery::mock(DocumentInterface::class, DocumentTenantAwareInterface::class);
        $document->shouldReceive('setTenant')->with('tenant1')->once();

        $index = \Mockery::mock(IndexInterface::class, IndexTenantAwareInterface::class);
        $index->shouldReceive('getTenant')->andReturn('tenant1');

        $this->helper->setTenantIfNeeded($document, $index);
    }

    public function testSetTenantIfNeededDoesNothingWhenIndexNotTenantAware(): void
    {
        $document = \Mockery::mock(DocumentInterface::class, DocumentTenantAwareInterface::class);
        $document->shouldNotReceive('setTenant');

        $index = \Mockery::mock(IndexInterface::class);

        $this->helper->setTenantIfNeeded($document, $index);
    }

    public function testSetTenantIfNeededDoesNothingWhenDocumentNotTenantAware(): void
    {
        $document = \Mockery::mock(DocumentInterface::class);

        $index = \Mockery::mock(IndexInterface::class, IndexTenantAwareInterface::class);
        $index->shouldReceive('getTenant')->andReturn('tenant1');

        $this->helper->setTenantIfNeeded($document, $index);

        // Should not throw an error
        $this->assertTrue(true);
    }

    public function testResetTenantIfNeededResetsTenantWhenBothAreTenantAware(): void
    {
        $document = \Mockery::mock(DocumentInterface::class, DocumentTenantAwareInterface::class);
        $document->shouldReceive('resetTenant')->once();

        $index = \Mockery::mock(IndexInterface::class, IndexTenantAwareInterface::class);

        $this->helper->resetTenantIfNeeded($document, $index);
    }

    public function testResetTenantIfNeededDoesNothingWhenIndexNotTenantAware(): void
    {
        $document = \Mockery::mock(DocumentInterface::class, DocumentTenantAwareInterface::class);
        $document->shouldNotReceive('resetTenant');

        $index = \Mockery::mock(IndexInterface::class);

        $this->helper->resetTenantIfNeeded($document, $index);
    }

    public function testResetTenantIfNeededDoesNothingWhenDocumentNotTenantAware(): void
    {
        $document = \Mockery::mock(DocumentInterface::class);

        $index = \Mockery::mock(IndexInterface::class, IndexTenantAwareInterface::class);

        $this->helper->resetTenantIfNeeded($document, $index);

        // Should not throw an error
        $this->assertTrue(true);
    }

    public static function paramsProvider(): array
    {
        return [
            'routing is kept' => [['routing' => 'object1'], ['routing' => 'object1']],
            'null is dropped' => [['routing' => null], []],
            'empty string is dropped' => [['routing' => ''], []],
            'other params are kept' => [['routing' => 'object1', 'pipeline' => 'enrich'], ['routing' => 'object1', 'pipeline' => 'enrich']],
            'no params' => [[], []],
        ];
    }

    public static function lookupParamsProvider(): array
    {
        return [
            'only routing is valid for lookups' => [['routing' => 'object1', 'pipeline' => 'enrich'], ['routing' => 'object1']],
            'no routing' => [['pipeline' => 'enrich'], []],
            'no params' => [[], []],
        ];
    }

    #[DataProvider('paramsProvider')]
    public function testGetParamsDropsEmptyValues(array $params, array $expected): void
    {
        $element = PimcoreElementFactory::createDataObject(1);

        $document = \Mockery::mock(DocumentInterface::class, ParamsAwareInterface::class);
        $document->shouldReceive('getParams')->with($element)->andReturn($params);

        $this->assertSame($expected, $this->helper->getParams($document, $element));
    }

    public function testGetParamsIsEmptyWhenDocumentIsNotParamsAware(): void
    {
        $this->assertSame(
            [],
            $this->helper->getParams(\Mockery::mock(DocumentInterface::class), PimcoreElementFactory::createDataObject(1)),
        );
    }

    #[DataProvider('lookupParamsProvider')]
    public function testGetLookupParamsKeepsOnlyRouting(array $params, array $expected): void
    {
        $this->assertSame($expected, $this->helper->getLookupParams($params));
    }

    public function testElementToDocumentAppliesParamsOfParamsAwareDocument(): void
    {
        $element = PimcoreElementFactory::createDataObject(123);

        $document = $this->createParamsAwareDocument($element);
        $document->shouldReceive('getParams')->with($element)->andReturn(['routing' => 'object99', 'pipeline' => 'enrich']);

        $result = $this->helper->elementToDocument($document, $element);

        $this->assertSame('object99', $result->getParam('routing'));
        $this->assertSame('enrich', $result->getParam('pipeline'));
    }

    public function testElementToDocumentUsesGivenParamsInsteadOfResolvingThem(): void
    {
        $element = PimcoreElementFactory::createDataObject(123);

        $document = $this->createParamsAwareDocument($element);
        $document->shouldNotReceive('getParams');

        $result = $this->helper->elementToDocument($document, $element, ['routing' => 'object42']);

        $this->assertSame('object42', $result->getParam('routing'));
        $this->assertFalse($result->hasParam('pipeline'));
    }

    public function testElementToDocumentHasNoParamsWhenDocumentIsNotParamsAware(): void
    {
        $element = PimcoreElementFactory::createDataObject(123);

        $document = \Mockery::mock(DocumentInterface::class);
        $document->shouldReceive('getType')->andReturn(DocumentType::DATA_OBJECT);
        $document->shouldReceive('getSubType')->andReturn('TestClass');
        $document->shouldReceive('getNormalized')->with($element)->andReturn([]);
        $document->shouldReceive('getElasticsearchId')->with($element)->andReturn('object123');

        $this->assertFalse($this->helper->elementToDocument($document, $element)->hasParam('routing'));
    }

    private function createParamsAwareDocument(object $element): \Mockery\MockInterface
    {
        $document = \Mockery::mock(DocumentInterface::class, ParamsAwareInterface::class);
        $document->shouldReceive('getType')->andReturn(DocumentType::DATA_OBJECT);
        $document->shouldReceive('getSubType')->andReturn('TestClass');
        $document->shouldReceive('getNormalized')->with($element)->andReturn([]);
        $document->shouldReceive('getElasticsearchId')->with($element)->andReturn('object123');

        return $document;
    }
}
