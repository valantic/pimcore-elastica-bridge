<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Controller;

use Pimcore\Controller\UserAwareController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;
use Valantic\ElasticaBridgeBundle\Service\PopulateIndexService;

#[Route(path: '/admin/elastica-bridge')]

class AdminController extends UserAwareController
{
    #[Route(
        path: '/refresh-index',
        name: 'admin_elastica_bridge_refresh_index',
        options: ['expose' => true],
        methods: [Request::METHOD_GET]
    )]
    public function index(
        IndexRepository $indexRepository,
        PopulateIndexService $populateIndexService,
        #[MapQueryParameter('indexName')]
        string $indexName,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_PIMCORE_ADMIN');

        try {
            $populateIndexService->processApi($indexRepository->flattenedGet($indexName), true, ignoreCooldown: true);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'log' => $populateIndexService->getLog(),
                'error' => $e->getMessage(),
                'stackTrace' => $e->getTrace(),
            ]);
        }

        return new JsonResponse([
            'success' => true,
            'log' => $populateIndexService->getLog(),
        ]);
    }
}
