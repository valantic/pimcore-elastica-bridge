<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Helpers;

use Elastica\Index;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\Document\DocumentInterface;
use Valantic\ElasticaBridgeBundle\Enum\IndexBlueGreenSuffix;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;

class IndexFactory
{
    public static function createTestIndex(
        string $name = 'test_index',
        array $allowedDocuments = [],
        array $mapping = [],
        array $settings = [],
    ): IndexInterface {
        return new class($name, $allowedDocuments, $mapping, $settings) implements IndexInterface {
            public function __construct(
                private readonly string $name,
                private readonly array $allowedDocuments,
                private readonly array $mapping,
                private readonly array $settings,
            ) {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getAllowedDocuments(): array
            {
                return $this->allowedDocuments;
            }

            public function subscribedDocuments(): array
            {
                return $this->allowedDocuments;
            }

            public function getMapping(): array
            {
                return $this->mapping;
            }

            public function getSettings(): array
            {
                return $this->settings;
            }

            public function hasMapping(): bool
            {
                return count($this->mapping) > 0;
            }

            public function getCreateArguments(): array
            {
                return array_filter([
                    'mappings' => $this->mapping,
                    'settings' => $this->settings,
                ]);
            }

            public function getBatchSize(): int
            {
                return 100;
            }

            public function shouldPopulateInSubprocesses(): bool
            {
                return false;
            }

            public function getElasticaIndex(): Index
            {
                throw new \RuntimeException('Not implemented in test double');
            }

            public function isElementAllowedInIndex(AbstractElement $element): bool
            {
                return true;
            }

            public function findDocumentInstanceByPimcore(AbstractElement $element): ?DocumentInterface
            {
                return null;
            }

            public function refreshIndexAfterEveryDocumentWhenPopulating(): bool
            {
                return false;
            }

            public function usesBlueGreenIndices(): bool
            {
                return false;
            }

            public function hasBlueGreenIndices(): bool
            {
                return false;
            }

            public function getBlueGreenActiveSuffix(): IndexBlueGreenSuffix
            {
                throw new \RuntimeException('Not implemented in test double');
            }

            public function getBlueGreenInactiveSuffix(): IndexBlueGreenSuffix
            {
                throw new \RuntimeException('Not implemented in test double');
            }

            public function getBlueGreenActiveElasticaIndex(): Index
            {
                throw new \RuntimeException('Not implemented in test double');
            }

            public function getBlueGreenInactiveElasticaIndex(): Index
            {
                throw new \RuntimeException('Not implemented in test double');
            }
        };
    }
}
