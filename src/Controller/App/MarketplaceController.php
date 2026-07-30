<?php

namespace App\Controller\App;

use App\Entity\CatalogApi;
use App\Repository\CatalogApiRepository;
use App\Service\OpenApiImporter;
use App\Service\WorkspaceContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * The public API marketplace: one-click import of a curated catalog API into
 * a workspace the user can edit. Imports are snapshots (forks) — the source
 * version is recorded so "update available" can be offered later.
 */
#[Route('/app/marketplace')]
#[IsGranted('ROLE_USER')]
class MarketplaceController extends AbstractAppController
{
    #[Route('', name: 'app_marketplace', methods: ['GET'])]
    public function index(CatalogApiRepository $catalog, WorkspaceContext $context): Response
    {
        return $this->render('app/marketplace/index.html.twig', [
            'apis' => $catalog->findPublished(),
            // Only workspaces the user can actually import into.
            'workspaces' => array_values(array_filter(
                $context->list(),
                fn ($ws) => $this->isGranted('WORKSPACE_EDIT', $ws)
            )),
        ]);
    }

    #[Route('/{slug}/add', name: 'app_marketplace_add', methods: ['POST'])]
    public function add(
        string $slug,
        Request $request,
        CatalogApiRepository $catalog,
        \App\Repository\WorkspaceRepository $workspaces,
        OpenApiImporter $importer,
        EntityManagerInterface $em,
    ): Response {
        $api = $catalog->findOneBy(['slug' => $slug, 'active' => true]);
        $version = $api?->getLatestVersion();
        if (null === $api || null === $version) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('marketplace-add', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $wsId = (string) $request->request->get('workspace_id');
        $workspace = Uuid::isValid($wsId) ? $workspaces->find(Uuid::fromString($wsId)) : null;
        if (null === $workspace) {
            throw $this->createNotFoundException();
        }
        $this->assertWorkspace($workspace, 'edit');

        try {
            $collection = $importer->importCollection($version->getSpec(), $workspace);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', 'İçe aktarma hatası: ' . $e->getMessage());

            return $this->redirectToRoute('app_marketplace');
        }
        $collection->setSourceType('catalog');
        $collection->setSourceVersion($version);

        $env = $importer->importEnvironment($version->getSpec(), $workspace);
        $em->flush();

        $this->addFlash('success', sprintf(
            '"%s" (%s) "%s" workspace\'ine eklendi.%s',
            $api->getName(),
            $version->getLabel(),
            $workspace->getName(),
            null !== $env ? sprintf(' "%s" environment\'ındaki secret değerleri doldurmayı unutmayın.', $env->getName()) : '',
        ));

        return $this->redirectToRoute('app_collection_index', ['workspace' => $workspace->getId()]);
    }
}
