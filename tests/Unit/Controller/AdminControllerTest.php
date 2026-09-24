<?php

declare(strict_types=1);

namespace Valantic\ElasticaBridgeBundle\Tests\Unit\Controller;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Valantic\ElasticaBridgeBundle\Controller\AdminController;
use Valantic\ElasticaBridgeBundle\Exception\Index\PopulationNotStartedException;
use Valantic\ElasticaBridgeBundle\Exception\Repository\ItemNotFoundInRepositoryException;
use Valantic\ElasticaBridgeBundle\Index\IndexInterface;
use Valantic\ElasticaBridgeBundle\Repository\IndexRepository;
use Valantic\ElasticaBridgeBundle\Service\PopulateIndexService;

class AdminControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private IndexRepository&MockInterface $indexRepository;

    private PopulateIndexService&MockInterface $populateIndexService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->indexRepository = \Mockery::mock(IndexRepository::class);
        $this->populateIndexService = \Mockery::mock(PopulateIndexService::class);
        $this->populateIndexService->shouldReceive('getLog')->andReturn([]);
    }

    public function testDeniesNonAdmins(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->createController(isAdmin: false)->index($this->createRequest(), $this->indexRepository, $this->populateIndexService);
    }

    public function testRejectsInvalidCsrfToken(): void
    {
        $this->populateIndexService->shouldNotReceive('processApi');

        $response = $this->createController(validToken: 'expected')
            ->index($this->createRequest(token: 'other'), $this->indexRepository, $this->populateIndexService)
        ;

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testAcceptsCsrfTokenFromHeader(): void
    {
        $index = $this->expectIndex('products');
        $this->populateIndexService->shouldReceive('processApi')->once()->with($index, true, false, true);

        $request = $this->createRequest(token: null);
        $request->headers->set(AdminController::CSRF_TOKEN_HEADER, 'valid');

        $response = $this->createController()->index($request, $this->indexRepository, $this->populateIndexService);

        $this->assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
    }

    public function testQueuesPopulation(): void
    {
        $index = $this->expectIndex('products');
        $this->populateIndexService->shouldReceive('processApi')->once()->with($index, true, false, true);

        $response = $this->createController()->index($this->createRequest(), $this->indexRepository, $this->populateIndexService);

        $this->assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
        $this->assertTrue($this->decode($response)['success']);
    }

    public function testReturnsNotFoundForUnknownIndex(): void
    {
        $this->indexRepository->shouldReceive('flattenedGet')->with('products')->andThrow(new ItemNotFoundInRepositoryException('products'));

        $response = $this->createController()->index($this->createRequest(), $this->indexRepository, $this->populateIndexService);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testReturnsConflictWhenPopulationIsNotStartedInSyncMode(): void
    {
        $this->expectIndex('products');
        $this->populateIndexService->shouldReceive('processApi')->andThrow(new HandlerFailedException(
            new Envelope(new \stdClass()),
            [new PopulationNotStartedException(PopulationNotStartedException::TYPE_NOT_AVAILABLE_IN_SYNC)],
        ));

        $response = $this->createController()->index($this->createRequest(), $this->indexRepository, $this->populateIndexService);

        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        $this->assertSame('Process not started (not available in sync mode)', $this->decode($response)['error']);
    }

    public function testDoesNotExposeStackTraceOnError(): void
    {
        $this->expectIndex('products');
        $this->populateIndexService->shouldReceive('processApi')->andThrow(new \RuntimeException('boom'));

        $response = $this->createController()->index($this->createRequest(), $this->indexRepository, $this->populateIndexService);

        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $this->assertSame(['success', 'log', 'error'], array_keys($this->decode($response)));
    }

    private function createController(bool $isAdmin = true, string $validToken = 'valid'): AdminController
    {
        $authorizationChecker = \Mockery::mock(AuthorizationCheckerInterface::class);
        $authorizationChecker
            ->shouldReceive('isGranted')
            ->andReturnUsing(static fn (mixed $attribute): bool => $attribute === 'ROLE_PIMCORE_ADMIN' && $isAdmin)
        ;

        $csrfTokenManager = \Mockery::mock(CsrfTokenManagerInterface::class);
        $csrfTokenManager
            ->shouldReceive('isTokenValid')
            ->andReturnUsing(static fn (CsrfToken $token): bool => $token->getId() === AdminController::CSRF_TOKEN_ID && $token->getValue() === $validToken)
        ;

        $container = new Container();
        $container->set('security.authorization_checker', $authorizationChecker);
        $container->set('security.csrf.token_manager', $csrfTokenManager);

        $controller = new AdminController();
        $controller->setContainer($container);

        return $controller;
    }

    private function createRequest(string $indexName = 'products', ?string $token = 'valid'): Request
    {
        return new Request(request: array_filter(['indexName' => $indexName, '_token' => $token]), server: ['REQUEST_METHOD' => Request::METHOD_POST]);
    }

    private function expectIndex(string $name): IndexInterface
    {
        $index = \Mockery::mock(IndexInterface::class);
        $this->indexRepository->shouldReceive('flattenedGet')->with($name)->andReturn($index);

        return $index;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
