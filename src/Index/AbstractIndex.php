<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Index;

use Elastica\Index;
use Elastica\Request;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\Document\DocumentInterface;
use Valantic\ElasticaBridgeBundle\Elastica\Client\ElasticsearchClient;
use Valantic\ElasticaBridgeBundle\Enum\IndexBlueGreenSuffix;
use Valantic\ElasticaBridgeBundle\Exception\Index\BlueGreenIndicesIncorrectlySetupException;
use Valantic\ElasticaBridgeBundle\Repository\DocumentRepository;

abstract class AbstractIndex implements IndexInterface
{
    public function __construct(
        private readonly ElasticsearchClient $client,
        private readonly DocumentRepository $documentRepository,
    ) {}

    public function getMapping(): array
    {
        return [];
    }

    public function getSettings(): array
    {
        return [];
    }

    final public function hasMapping(): bool
    {
        return count($this->getMapping()) > 0;
    }

    final public function getCreateArguments(): array
    {
        return array_filter([
            'mappings' => $this->getMapping(),
            'settings' => $this->getSettings(),
        ]);
    }

    public function getBatchSize(): int
    {
        return 5000;
    }

    public function getElasticaIndex(): Index
    {
        return $this->client->getIndex($this->getName());
    }

    public function isElementAllowedInIndex(AbstractElement $element): bool
    {
        return $this->findDocumentInstanceByPimcore($element) instanceof DocumentInterface;
    }

    /**
     * @return DocumentInterface<AbstractElement>
     */
    public function findDocumentInstanceByPimcore(AbstractElement $element): ?DocumentInterface
    {
        foreach ($this->getAllowedDocuments() as $allowedDocument) {
            $documentInstance = $this->documentRepository->get($allowedDocument);

            if (
                $documentInstance->getSubType() === $element::class
                || (
                    $documentInstance->getSubType() === null
                    && is_subclass_of($element, $documentInstance->getType()->baseClass())
                )
            ) {
                return $documentInstance;
            }
        }

        return null;
    }

    public function subscribedDocuments(): array
    {
        return $this->getAllowedDocuments();
    }

    public function refreshIndexAfterEveryDocumentWhenPopulating(): bool
    {
        return false;
    }

    public function usesBlueGreenIndices(): bool
    {
        return true;
    }

    final public function hasBlueGreenIndices(): bool
    {
        return array_reduce(
            array_map(
                fn (IndexBlueGreenSuffix $suffix): bool => $this->client->getIndex($this->getName() . $suffix->value)->exists(),
                IndexBlueGreenSuffix::cases()
            ),
            fn (bool $carry, bool $item): bool => $item && $carry,
            true
        );
    }

    final public function getBlueGreenActiveSuffix(): IndexBlueGreenSuffix
    {
        if (!$this->hasBlueGreenIndices()) {
            throw new BlueGreenIndicesIncorrectlySetupException();
        }

        $aliases = array_filter(
            json_decode((string) $this->client->request(Request::GET, '_aliases')->getBody(), true, flags: \JSON_THROW_ON_ERROR),
            fn (array $datum): bool => array_key_exists($this->getName(), $datum['aliases'])
        );

        if (count($aliases) !== 1) {
            throw new BlueGreenIndicesIncorrectlySetupException();
        }

        $suffix = substr(array_keys($aliases)[0], strlen($this->getName()));

        return IndexBlueGreenSuffix::tryFrom($suffix) ?? throw new BlueGreenIndicesIncorrectlySetupException();
    }

    final public function getBlueGreenInactiveSuffix(): IndexBlueGreenSuffix
    {
        return match ($this->getBlueGreenActiveSuffix()) {
            IndexBlueGreenSuffix::BLUE => IndexBlueGreenSuffix::GREEN,
            IndexBlueGreenSuffix::GREEN => IndexBlueGreenSuffix::BLUE,
        };
    }

    final public function getBlueGreenActiveElasticaIndex(): Index
    {
        return $this->client->getIndex($this->getName() . $this->getBlueGreenActiveSuffix()->value);
    }

    final public function getBlueGreenInactiveElasticaIndex(): Index
    {
        return $this->client->getIndex($this->getName() . $this->getBlueGreenInactiveSuffix()->value);
    }
}
