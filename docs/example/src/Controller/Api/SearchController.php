<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Elasticsearch\Index\Product\ProductIndex;
use Elastica\Query;
use Pimcore\Localization\LocaleService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class SearchController extends AbstractController
{
    public function productAction(
        Request $request,
        ProductIndex $productIndex,
        LocaleService $localeService,
    ): JsonResponse {
        /** @var string $query */
        $query = $request->query->get('query');

        $results = $productIndex->getElasticaIndex()
            ->search(new Query($productIndex->filterByLocaleAndQuery($localeService->getLocale() ?? 'en', $query)))
            ->getDocuments();

        return new JsonResponse($results);
    }
}
