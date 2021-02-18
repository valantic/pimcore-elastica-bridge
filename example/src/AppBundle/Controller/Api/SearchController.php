<?php

namespace AppBundle\Controller\Api;

use AppBundle\Elasticsearch\Index\Product\ProductIndex;
use Pimcore\Localization\LocaleService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class SearchController extends AbstractController
{
    public function productAction(Request $request, ProductIndex $productIndex, LocaleService $localeService): JsonResponse
    {
        $query = $request->query->get('query');

        $results = $productIndex->getElasticaIndex()
            ->search($productIndex->filterByLocaleAndQuery($localeService->getLocale(), $query))
            ->getDocuments();

        return new JsonResponse($results);
    }
}
