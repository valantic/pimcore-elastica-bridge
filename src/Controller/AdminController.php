<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Controller;

use Pimcore\Controller\UserAwareController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Routing\Attribute\Route;
use Valantic\ElasticaBridgeBundle\Exception\Index\PopulationNotStartedException;
use Valantic\ElasticaBridgeBundle\Exception\Repository\ItemNotFoundInRepositoryException;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;
use Valantic\ElasticaBridgeBundle\Service\PopulateIndexService;

#[Route(path: '/admin/elastica-bridge')]
class AdminController extends UserAwareController
{
    public const string CSRF_TOKEN_ID = 'elastica_bridge_refresh_index';

    public const string CSRF_TOKEN_HEADER = 'X-CSRF-Token';

    /**
     * Queues the population of an index. Requires an async `elastica_bridge_populate` transport.
     *
     * Expects `indexName` and a CSRF token for {@see self::CSRF_TOKEN_ID}, either as `_token` in the payload
     * or in the {@see self::CSRF_TOKEN_HEADER} header.
     */
    #[Route(
        path: '/refresh-index',
        name: 'admin_elastica_bridge_refresh_index',
        options: ['expose' => true],
        methods: [Request::METHOD_POST],
    )]
    public function index(
        Request $request,
        IndexRepository $indexRepository,
        PopulateIndexService $populateIndexService,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_PIMCORE_ADMIN');

        $payload = $request->getPayload();
        $token = $request->headers->get(self::CSRF_TOKEN_HEADER) ?? $payload->getString('_token');

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $token)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        try {
            $indexConfig = $indexRepository->flattenedGet($payload->getString('indexName'));
        } catch (ItemNotFoundInRepositoryException) {
            return new JsonResponse(['success' => false, 'error' => 'Unknown index'], Response::HTTP_NOT_FOUND);
        }

        try {
            $populateIndexService->processApi($indexConfig, populate: true, ignoreCooldown: true);
        } catch (\Throwable $throwable) {
            $notStarted = $this->findPopulationNotStartedException($throwable);

            return new JsonResponse([
                'success' => false,
                'log' => $populateIndexService->getLog(),
                'error' => ($notStarted ?? $throwable)->getMessage(),
            ], $notStarted instanceof PopulationNotStartedException ? Response::HTTP_CONFLICT : Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'success' => true,
            'log' => $populateIndexService->getLog(),
        ], Response::HTTP_ACCEPTED);
    }

    private function findPopulationNotStartedException(\Throwable $throwable): ?PopulationNotStartedException
    {
        if ($throwable instanceof PopulationNotStartedException) {
            return $throwable;
        }

        if ($throwable instanceof HandlerFailedException) {
            foreach ($throwable->getWrappedExceptions(PopulationNotStartedException::class, true) as $wrapped) {
                return $wrapped;
            }
        }

        return null;
    }
}
