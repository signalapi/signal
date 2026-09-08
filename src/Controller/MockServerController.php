<?php

namespace App\Controller;

use App\Repository\MockRouteRepository;
use App\Repository\WorkspaceRepository;
use App\Service\DynamicVariableGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the workspace mock server: /mock/{token}/{any path}. Exact routes win
 * over wildcards, {{$generator}} tokens in the body are resolved per hit, and a
 * miss answers 404 with what WAS tried — the debugging answer, not a mystery.
 *
 * Publicly reachable by design (the system under test needs to call it); the
 * token scopes it, and it can only ever answer with what the owner typed in.
 */
class MockServerController extends AbstractController
{
    #[Route('/mock/{token}/{path}', name: 'mock_serve', requirements: ['path' => '.*'], defaults: ['path' => ''], methods: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'])]
    public function serve(
        string $token,
        string $path,
        Request $request,
        WorkspaceRepository $workspaces,
        MockRouteRepository $routes,
        DynamicVariableGenerator $dynamic,
    ): Response {
        if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) {
            throw $this->createNotFoundException();
        }
        $workspace = $workspaces->findOneBy(['mockToken' => $token]);
        if (null === $workspace) {
            throw $this->createNotFoundException();
        }

        $path = '/' . ltrim($path, '/');
        foreach ($routes->findActiveByWorkspace($workspace) as $route) {
            if (!$route->matches($request->getMethod(), $path)) {
                continue;
            }

            if ($route->getDelayMs() > 0) {
                usleep(min(30000, $route->getDelayMs()) * 1000);
            }
            $route->recordHit();
            $routes->save($route);

            $body = (string) $route->getResponseBody();
            // {{$guid}}, {{$randomEmail}}… — fresh values on every hit.
            $body = preg_replace_callback('/\{\{\s*(\$[\w-]+)\s*\}\}/', static function (array $m) use ($dynamic): string {
                return $dynamic->generate($m[1]) ?? $m[0];
            }, $body) ?? $body;

            return new Response($body, $route->getResponseStatus(), [
                'Content-Type' => $route->getContentType(),
                'X-Signal-Mock' => 'true',
            ]);
        }

        return new JsonResponse([
            'error' => 'No mock route matched.',
            'method' => $request->getMethod(),
            'path' => $path,
            'hint' => 'Define it under Mock server in the Signal workspace.',
        ], 404, ['X-Signal-Mock' => 'true']);
    }
}
