<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Helpers;

use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\Document\DocumentInterface;
use Valantic\ElasticaBridgeBundle\Enum\DocumentType;

class DocumentFactory
{
    public static function createTestDocument(
        DocumentType $type = DocumentType::DATA_OBJECT,
        ?string $subType = null,
        bool $shouldIndex = true,
    ): DocumentInterface {
        return new class($type, $subType, $shouldIndex) implements DocumentInterface {
            public function __construct(
                private readonly DocumentType $type,
                private readonly ?string $subType,
                private readonly bool $shouldIndex,
            ) {
            }

            public static function getElasticsearchId(AbstractElement $element): string
            {
                return 'test_' . $element->getId();
            }

            public function getType(): DocumentType
            {
                return $this->type;
            }

            public function getSubType(): ?string
            {
                return $this->subType;
            }

            public function shouldIndex(AbstractElement $element): bool
            {
                return $this->shouldIndex;
            }

            public function getNormalized(AbstractElement $element): array
            {
                return [
                    DocumentInterface::META_ID => $element->getId(),
                    DocumentInterface::META_TYPE => $this->type->value,
                    DocumentInterface::META_SUB_TYPE => $this->subType ?? $element::class,
                ];
            }

            public function getListingInstance(\Valantic\ElasticaBridgeBundle\Index\IndexInterface $index): \Pimcore\Model\Listing\AbstractListing
            {
                return new class extends \Pimcore\Model\Listing\AbstractListing {
                    public function getTotalCount(): int
                    {
                        return 0;
                    }

                    public function count(): int
                    {
                        return 0;
                    }

                    public function getItems(): array
                    {
                        return [];
                    }
                };
            }

            public function getIndexListingCondition(): ?string
            {
                return null;
            }

            public function treatObjectVariantsAsDocuments(): bool
            {
                return false;
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
