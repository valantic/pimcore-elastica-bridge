<?php

declare(strict_types=1);

namespace AppBundle\Elasticsearch\Index\Search\InlineDataObjectNormalizer;

use Pimcore\Localization\LocaleService;
use Pimcore\Model\DataObject\Seminar;
use Pimcore\Model\Document;
use Valantic\ElasticaBridgeBundle\Service\DeepImplodeTrait;

class SeminarNormalizer implements InlineDataObjectNormalizerInterface
{
    use DeepImplodeTrait;
    use LocaleAwareNormalizerTrait;

    public function __construct(LocaleService $localeService)
    {
        $this->localeService = $localeService;
    }

    public function getDataObjectClass(): string
    {
        return Seminar::class;
    }

    /**
     * @param Seminar[] $objs
     * @param Document\Page $document
     *
     * @return string|null
     */
    protected function doNormalizeObjectsInDocument(array $objs, Document\Page $document): ?string
    {
        $content = [];
        foreach ($objs as $obj) {
            $content[] = $obj->getDescription();
        }

        return $this->deepImplode($content);
    }
}
