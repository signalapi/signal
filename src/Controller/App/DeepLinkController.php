<?php

namespace App\Controller\App;

use App\Repository\CatalogApiRepository;
use App\Service\WorkspaceContext;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Add to Signal" landing for links embedded in API doc sites. Only catalog
 * slugs are accepted — never arbitrary spec URLs (SSRF). Anonymous visitors
 * bounce through login and land back here; the actual import is a confirmed
 * POST to the marketplace endpoint.
 */
#[Route('/add')]
#[IsGranted('ROLE_USER')]
class DeepLinkController extends AbstractAppController
{
    #[Route('/{slug}', name: 'app_deeplink', methods: ['GET'])]
    public function landing(string $slug, CatalogApiRepository $catalog, WorkspaceContext $context): Response
    {
        $api = $catalog->findOneBy(['slug' => $slug, 'active' => true]);
        if (null === $api || null === $api->getLatestVersion()) {
            throw $this->createNotFoundException();
        }

        return $this->render('app/marketplace/deeplink.html.twig', [
            'api' => $api,
            'version' => $api->getLatestVersion(),
            'workspaces' => array_values(array_filter(
                $context->list(),
                fn ($ws) => $this->isGranted('WORKSPACE_EDIT', $ws)
            )),
        ]);
    }
}
