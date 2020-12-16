<?php

namespace Valantic\ElasticaBridgeBundle\Command;

use Elastica\Document;
use Pimcore\Console\AbstractCommand;
use Pimcore\Model\Element\AbstractElement;
use Valantic\ElasticaBridgeBundle\DocumentType\Index\IndexDocumentInterface;

abstract class BaseCommand extends AbstractCommand
{
    protected const COMMAND_NAMESPACE = 'valantic:elastica-bridge:';

    public function __construct(string $name = null)
    {
        parent::__construct($name);
    }

    /**
     * @param iterable<object> $iterables
     * @return array<string,object>
     */
    protected function iterableToArray(iterable $iterables): array
    {
        $arr = [];
        foreach ($iterables as $iterable) {
            $arr[get_class($iterable)] = $iterable;
        }

        return $arr;
    }

    protected function getDocumentForIndex(IndexDocumentInterface $indexDocumentInstance, AbstractElement $dataObject): Document
    {
        return new Document(
            $indexDocumentInstance->getElasticsearchId($dataObject),
            array_merge($indexDocumentInstance->getNormalized($dataObject), [
                IndexDocumentInterface::META_TYPE => $indexDocumentInstance->getType(),
                IndexDocumentInterface::META_SUB_TYPE => $indexDocumentInstance->getSubType(),
            ])
        );
    }
}
