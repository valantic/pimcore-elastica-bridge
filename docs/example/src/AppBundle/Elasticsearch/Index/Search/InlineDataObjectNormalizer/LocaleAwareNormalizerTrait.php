<?php

declare(strict_types=1);

namespace AppBundle\Elasticsearch\Index\Search\InlineDataObjectNormalizer;

use AppBundle\Constant\DocumentPropertyConstants;
use Pimcore\Localization\LocaleService;
use Pimcore\Model\DataObject\Localizedfield;
use Pimcore\Model\Document;

trait LocaleAwareNormalizerTrait
{
    protected LocaleService $localeService;

    public function normalizeObjectsInDocument(array $objs, Document\Page $document): ?string
    {
        $origLocale = $this->localeService->getLocale();
        $getFallbackValuesOrig = Localizedfield::getGetFallbackValues();
        Localizedfield::setGetFallbackValues(true);

        $this->localeService->setLocale($document->getProperty(DocumentPropertyConstants::LANGUAGE));

        $result = $this->doNormalizeObjectsInDocument($objs, $document);

        $this->localeService->setLocale($origLocale);
        Localizedfield::setGetFallbackValues($getFallbackValuesOrig);

        return $result;
    }

    abstract protected function doNormalizeObjectsInDocument(array $objs, Document\Page $document): ?string;
}
