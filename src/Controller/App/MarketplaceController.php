<?php

namespace App\Controller\App;

use App\Entity\CatalogApi;
use App\Entity\CatalogApiVersion;
use App\Entity\Workspace;
use App\Repository\CatalogApiRepository;
use App\Repository\WorkspaceRepository;
use App\Security\MerchantVoter;
use App\Service\CatalogVisibility;
use App\Service\OpenApiImporter;
use App\Service\WorkspaceContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The API marketplace: a platform-level catalog that sits above companies.
 * Viewers see every public entry plus the private ones belonging to their own
 * companies and workspaces; companies publish their own specs here and choose
 * how far each one reaches.
 *
 * Imports are snapshots (forks) — the source version is recorded so
 * "update available" can be offered later.
 */
#[Route('/app/marketplace')]
#[IsGranted('ROLE_USER')]
class MarketplaceController extends AbstractAppController
{
    #[Route('', name: 'app_marketplace', methods: ['GET'])]
    public function index(
        CatalogApiRepository $catalog,
        WorkspaceContext $context,
        CatalogVisibility $visibility,
    ): Response {
        $visibleWorkspaces = $context->list();
        $merchants = $visibility->merchantsOf($this->currentUser());

        $merchant = $this->merchantContext->current();

        return $this->render('app/marketplace/index.html.twig', [
            'apis' => $catalog->findVisible($merchants, $visibleWorkspaces),
            // Only workspaces the user can actually import into.
            'workspaces' => array_values(array_filter(
                $visibleWorkspaces,
                fn ($ws) => $this->isGranted('WORKSPACE_EDIT', $ws)
            )),
            'can_publish' => null !== $merchant,
            'my_published' => null !== $merchant && $this->isGranted(MerchantVoter::MANAGE_WORKSPACES, $merchant)
                ? $catalog->findByOwnerMerchant($merchant)
                : [],
        ]);
    }

    /**
     * Publish a company's own OpenAPI spec into the marketplace. Workspace-only
     * reach needs admin rights on that workspace; company-wide or public reach
     * is a company-admin decision.
     */
    #[Route('/publish', name: 'app_marketplace_publish', methods: ['GET', 'POST'])]
    public function publish(
        Request $request,
        CatalogApiRepository $catalog,
        WorkspaceRepository $workspaces,
        WorkspaceContext $context,
        SluggerInterface $slugger,
        EntityManagerInterface $em,
        TranslatorInterface $translator,
    ): Response {
        $merchant = $this->currentMerchant();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('marketplace-publish', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $name = trim((string) $request->request->get('name'));
            $visibility = (string) $request->request->get('visibility', CatalogApi::VISIBILITY_WORKSPACE);
            if ('' === $name || !\in_array($visibility, CatalogApi::VISIBILITIES, true)) {
                $this->addFlash('error', $translator->trans('Enter a name and pick a visibility.'));

                return $this->redirectToRoute('app_marketplace_publish');
            }

            // Reach determines who may publish it.
            $workspace = null;
            if (CatalogApi::VISIBILITY_WORKSPACE === $visibility) {
                $wsId = (string) $request->request->get('workspace_id');
                $workspace = Uuid::isValid($wsId) ? $workspaces->find(Uuid::fromString($wsId)) : null;
                if (null === $workspace) {
                    throw $this->createNotFoundException();
                }
                $this->assertWorkspace($workspace, 'admin');
            } else {
                $this->denyAccessUnlessGranted(MerchantVoter::MANAGE_WORKSPACES, $merchant);
            }

            /** @var UploadedFile|null $file */
            $file = $request->files->get('spec');
            if (null === $file) {
                $this->addFlash('error', $translator->trans('Select a spec file.'));

                return $this->redirectToRoute('app_marketplace_publish');
            }

            try {
                $data = $this->decodeSpec($file);
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());

                return $this->redirectToRoute('app_marketplace_publish');
            }
            if (!OpenApiImporter::supports($data) || !isset($data['paths'])) {
                $this->addFlash('error', $translator->trans('The file is not an OpenAPI/Swagger spec.'));

                return $this->redirectToRoute('app_marketplace_publish');
            }

            $slug = $slugger->slug($name)->lower()->toString();
            if (null !== $catalog->findOneBy(['slug' => $slug])) {
                $slug .= '-' . substr(uniqid(), -5);
            }

            $api = new CatalogApi();
            $api->setName($name);
            $api->setSlug($slug);
            $api->setPublisher(trim((string) $request->request->get('publisher')) ?: $merchant->getName());
            $api->setCategory(trim((string) $request->request->get('category')) ?: null);
            $api->setDescription(trim((string) $request->request->get('description')) ?: null);
            $api->setLogo(trim((string) $request->request->get('logo')) ?: null);
            $api->setVisibility($visibility);
            $api->setOwnerMerchant($merchant);
            $api->setOwnerWorkspace($workspace);
            $em->persist($api);

            $version = new CatalogApiVersion();
            $version->setCatalogApi($api);
            $version->setSpec($data);
            $version->setLabel(trim((string) $request->request->get('label')) ?: date('Y-m-d'));
            $version->setChangelog(trim((string) $request->request->get('changelog')) ?: null);
            $em->persist($version);
            $em->flush();

            $this->addFlash('success', CatalogApi::VISIBILITY_PUBLIC === $visibility
                ? $translator->trans('"%name%" was published. Public entries are listed as unverified until the platform reviews them.', ['%name%' => $api->getName()])
                : $translator->trans('"%name%" was published to the marketplace.', ['%name%' => $api->getName()]));

            return $this->redirectToRoute('app_marketplace');
        }

        return $this->render('app/marketplace/publish.html.twig', [
            'merchant' => $merchant,
            // Workspace-scoped publishing needs admin on that workspace.
            'admin_workspaces' => array_values(array_filter(
                $context->list(),
                fn ($ws) => $this->isGranted('WORKSPACE_ADMIN', $ws)
            )),
            'can_publish_wide' => $this->isGranted(MerchantVoter::MANAGE_WORKSPACES, $merchant),
        ]);
    }

    #[Route('/{slug}/unpublish', name: 'app_marketplace_unpublish', methods: ['POST'])]
    public function unpublish(string $slug, Request $request, CatalogApiRepository $catalog, EntityManagerInterface $em, TranslatorInterface $translator): Response
    {
        $api = $catalog->findOneBy(['slug' => $slug]);
        if (null === $api || null === $api->getOwnerMerchant()) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(MerchantVoter::MANAGE_WORKSPACES, $api->getOwnerMerchant());
        if (!$this->isCsrfTokenValid('marketplace-unpublish' . $api->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $em->remove($api);
        $em->flush();
        $this->addFlash('success', $translator->trans('The marketplace entry has been removed.'));

        return $this->redirectToRoute('app_marketplace');
    }

    #[Route('/{slug}/add', name: 'app_marketplace_add', methods: ['POST'])]
    public function add(
        string $slug,
        Request $request,
        CatalogApiRepository $catalog,
        WorkspaceRepository $workspaces,
        WorkspaceContext $context,
        CatalogVisibility $visibility,
        OpenApiImporter $importer,
        EntityManagerInterface $em,
        TranslatorInterface $translator,
    ): Response {
        $api = $catalog->findOneBy(['slug' => $slug, 'active' => true]);
        $version = $api?->getLatestVersion();
        if (null === $api || null === $version || !$visibility->isVisibleTo($api, $this->currentUser())) {
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
            $this->addFlash('error', $translator->trans('Import error: %error%', ['%error%' => $e->getMessage()]));

            return $this->redirectToRoute('app_marketplace');
        }
        $collection->setSourceType('catalog');
        $collection->setSourceVersion($version);

        $env = $importer->importEnvironment($version->getSpec(), $workspace);
        $em->flush();

        $message = $translator->trans('"%api%" (%version%) was added to the "%workspace%" workspace.', [
            '%api%' => $api->getName(),
            '%version%' => $version->getLabel(),
            '%workspace%' => $workspace->getName(),
        ]);
        if (null !== $env) {
            $message .= ' ' . $translator->trans('Don\'t forget to fill in the secret values in the "%environment%" environment.', ['%environment%' => $env->getName()]);
        }
        $this->addFlash('success', $message);

        return $this->redirectToRoute('app_collection_index', ['workspace' => $workspace->getId()]);
    }

    /** @return array<string, mixed> */
    private function decodeSpec(UploadedFile $file): array
    {
        $content = (string) file_get_contents($file->getPathname());
        $data = json_decode($content, true);
        if (\JSON_ERROR_NONE === json_last_error() && \is_array($data)) {
            return $data;
        }

        try {
            $data = Yaml::parse($content);
        } catch (ParseException) {
            $data = null;
        }
        if (!\is_array($data)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not valid JSON or YAML.', $file->getClientOriginalName()));
        }

        return $data;
    }
}
