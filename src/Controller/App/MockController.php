<?php

namespace App\Controller\App;

use App\Entity\MockRoute;
use App\Entity\Workspace;
use App\Repository\MockRouteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Manages the workspace mock server: stub routes in, deterministic tests out.
 * The mock token is minted on first visit; regenerating it invalidates every
 * URL that pointed at the old one.
 */
#[Route('/app/workspaces/{workspace}/mocks')]
#[IsGranted('ROLE_USER')]
class MockController extends AbstractAppController
{
    #[Route('', name: 'app_mock_index', methods: ['GET'])]
    public function index(Workspace $workspace, MockRouteRepository $routes, EntityManagerInterface $em): Response
    {
        $this->assertWorkspace($workspace);

        if (null === $workspace->getMockToken()) {
            $workspace->setMockToken(bin2hex(random_bytes(20)));
            $em->flush();
        }

        return $this->render('app/mock/index.html.twig', [
            'workspace' => $workspace,
            'routes' => $routes->findByWorkspace($workspace),
        ]);
    }

    #[Route('', name: 'app_mock_create', methods: ['POST'])]
    public function create(Workspace $workspace, Request $request, MockRouteRepository $routes, TranslatorInterface $translator): Response
    {
        $this->assertWorkspace($workspace, 'edit');
        if (!$this->isCsrfTokenValid('mock-create', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $path = trim((string) $request->request->get('path'));
        if ('' === $path) {
            $this->addFlash('error', $translator->trans('Path is required.'));

            return $this->redirectToRoute('app_mock_index', ['workspace' => $workspace->getId()]);
        }

        $route = new MockRoute();
        $route->setWorkspace($workspace);
        $route->setMethod((string) $request->request->get('method', 'GET'));
        $route->setPath($path);
        $route->setResponseStatus((int) $request->request->get('status', 200));
        $route->setContentType(trim((string) $request->request->get('content_type')) ?: 'application/json');
        $route->setResponseBody(trim((string) $request->request->get('body')) ?: null);
        $route->setDelayMs((int) $request->request->get('delay_ms', 0));
        $routes->save($route);

        $this->addFlash('success', $translator->trans('Mock route added: %method% %path%', [
            '%method%' => $route->getMethod(), '%path%' => $route->getPath(),
        ]));

        return $this->redirectToRoute('app_mock_index', ['workspace' => $workspace->getId()]);
    }

    #[Route('/{route}/toggle', name: 'app_mock_toggle', methods: ['POST'])]
    public function toggle(
        Workspace $workspace,
        #[MapEntity(mapping: ['route' => 'id'])] MockRoute $route,
        Request $request,
        MockRouteRepository $routes,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertRoute($workspace, $route);
        if (!$this->isCsrfTokenValid('mock-toggle' . $route->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $route->setActive(!$route->isActive());
        $routes->save($route);

        return $this->redirectToRoute('app_mock_index', ['workspace' => $workspace->getId()]);
    }

    #[Route('/{route}/delete', name: 'app_mock_delete', methods: ['POST'])]
    public function delete(
        Workspace $workspace,
        #[MapEntity(mapping: ['route' => 'id'])] MockRoute $route,
        Request $request,
        MockRouteRepository $routes,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertRoute($workspace, $route);
        if (!$this->isCsrfTokenValid('mock-delete' . $route->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $routes->remove($route);

        return $this->redirectToRoute('app_mock_index', ['workspace' => $workspace->getId()]);
    }

    #[Route('/regenerate-token', name: 'app_mock_regenerate', methods: ['POST'])]
    public function regenerateToken(Workspace $workspace, Request $request, EntityManagerInterface $em, TranslatorInterface $translator): Response
    {
        $this->assertWorkspace($workspace, 'edit');
        if (!$this->isCsrfTokenValid('mock-regenerate', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $workspace->setMockToken(bin2hex(random_bytes(20)));
        $em->flush();
        $this->addFlash('success', $translator->trans('Mock base URL regenerated — every old URL is now dead. Update your flows.'));

        return $this->redirectToRoute('app_mock_index', ['workspace' => $workspace->getId()]);
    }

    private function assertRoute(Workspace $workspace, MockRoute $route): void
    {
        if ($route->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }
    }
}
