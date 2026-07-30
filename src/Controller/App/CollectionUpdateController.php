<?php

namespace App\Controller\App;

use App\Entity\ApiCollection;
use App\Entity\ApiRequest;
use App\Entity\CatalogApiVersion;
use App\Entity\Folder;
use App\Entity\Workspace;
use App\Service\CollectionUpdatePlan;
use App\Service\CollectionUpdatePlanner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Reviews and applies a catalog version update onto an imported collection.
 * Defaults are safe: additions and clean upstream changes apply, conflicts
 * keep the local version, removed endpoints are deprecated — never deleted
 * (flow steps reference requests by FK).
 */
#[Route('/app/workspaces/{workspace}/collections/{collection}/update')]
#[IsGranted('ROLE_USER')]
class CollectionUpdateController extends AbstractAppController
{
    #[Route('', name: 'app_collection_update', methods: ['GET'])]
    public function review(
        Workspace $workspace,
        #[MapEntity(mapping: ['collection' => 'id'])] ApiCollection $collection,
        CollectionUpdatePlanner $planner,
        TranslatorInterface $translator,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertCollection($workspace, $collection);

        $plan = $this->planFor($collection, $planner);
        if (null === $plan) {
            $this->addFlash('success', $translator->trans('This collection has no source to pull from.'));

            return $this->redirectToRoute('app_collection_show', ['workspace' => $workspace->getId(), 'collection' => $collection->getId()]);
        }

        $catalogTarget = $this->newerCatalogVersion($collection);
        if (null === $catalogTarget && null === $collection->getSourceCollection()) {
            $this->addFlash('success', $translator->trans('Collection is already at the latest catalog version.'));

            return $this->redirectToRoute('app_collection_show', ['workspace' => $workspace->getId(), 'collection' => $collection->getId()]);
        }

        return $this->render('app/collection/update.html.twig', [
            'workspace' => $workspace,
            'collection' => $collection,
            'current' => $collection->getSourceVersion(),
            'target' => $catalogTarget,
            'sourceCollection' => $collection->getSourceCollection(),
            'plan' => $plan,
        ]);
    }

    #[Route('/apply', name: 'app_collection_update_apply', methods: ['POST'])]
    public function apply(
        Workspace $workspace,
        #[MapEntity(mapping: ['collection' => 'id'])] ApiCollection $collection,
        Request $request,
        CollectionUpdatePlanner $planner,
        EntityManagerInterface $em,
        TranslatorInterface $translator,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        if (!$this->isCsrfTokenValid('collection-update' . $collection->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->assertCollection($workspace, $collection);

        // The plan is recomputed server-side; the form only carries decisions.
        $plan = $this->planFor($collection, $planner);
        if (null === $plan) {
            return $this->redirectToRoute('app_collection_show', ['workspace' => $workspace->getId(), 'collection' => $collection->getId()]);
        }
        $target = $this->newerCatalogVersion($collection);
        $addKeys = array_map('strval', (array) $request->request->all('add'));
        $changeDecisions = array_map('strval', (array) $request->request->all('change'));
        $deprecateKeys = array_map('strval', (array) $request->request->all('deprecate'));

        $stats = ['added' => 0, 'applied' => 0, 'kept' => 0, 'deprecated' => 0];

        $position = \count($collection->getRequests());
        foreach ($plan->added as $item) {
            $key = (string) $item['request']->getOriginKey();
            if (!\in_array($key, $addKeys, true)) {
                continue;
            }
            $incoming = $item['request'];
            $incoming->setCollection($collection);
            $incoming->setFolder('' === $item['tag'] ? null : $this->folderFor($collection, $item['tag'], $em));
            $incoming->setPosition($position++);
            $em->persist($incoming);
            ++$stats['added'];
        }

        foreach ([['pairs' => $plan->autoApply, 'default' => 'apply'], ['pairs' => $plan->conflicts, 'default' => 'keep']] as $group) {
            foreach ($group['pairs'] as $pair) {
                $existing = $pair['existing'];
                $incoming = $pair['incoming'];
                $decision = $changeDecisions[(string) $existing->getOriginKey()] ?? $group['default'];

                if ('apply' === $decision) {
                    $this->copyInto($existing, $incoming);
                    ++$stats['applied'];
                } else {
                    ++$stats['kept'];
                }
                // Either way the upstream state is acknowledged: stop flagging
                // this operation until the spec changes again.
                $existing->setOriginHash($incoming->getOriginHash());
            }
        }

        foreach ($plan->removed as $existing) {
            if (\in_array((string) $existing->getOriginKey(), $deprecateKeys, true)) {
                $existing->setDeprecated(true);
                ++$stats['deprecated'];
            }
        }

        if (null !== $target) {
            $collection->setSourceVersion($target);
        }
        $em->flush();

        $counts = [
            '%added%' => $stats['added'],
            '%applied%' => $stats['applied'],
            '%kept%' => $stats['kept'],
            '%deprecated%' => $stats['deprecated'],
        ];
        $this->addFlash('success', null !== $target
            ? $translator->trans(
                'Updated to version "%version%": %added% added, %applied% applied, %kept% kept local, %deprecated% deprecated.',
                $counts + ['%version%' => $target->getLabel()],
            )
            : $translator->trans(
                'Pulled from the source collection: %added% added, %applied% applied, %kept% kept local, %deprecated% deprecated.',
                $counts,
            ));

        return $this->redirectToRoute('app_collection_show', ['workspace' => $workspace->getId(), 'collection' => $collection->getId()]);
    }

    private function assertCollection(Workspace $workspace, ApiCollection $collection): void
    {
        if ($collection->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }
    }

    /** The newer catalog version to move to, or null when current / not from a catalog. */
    private function newerCatalogVersion(ApiCollection $collection): ?CatalogApiVersion
    {
        $current = $collection->getSourceVersion();
        if (null === $current) {
            return null;
        }

        $latest = $current->getCatalogApi()->getLatestVersion();
        if (null === $latest || $latest->getId()?->toRfc4122() === $current->getId()?->toRfc4122()) {
            return null;
        }

        return $latest;
    }

    /**
     * The diff against whichever source this collection has — a newer catalog
     * version, or the collection it was forked from. Null when it has neither.
     */
    private function planFor(ApiCollection $collection, CollectionUpdatePlanner $planner): ?CollectionUpdatePlan
    {
        $catalogTarget = $this->newerCatalogVersion($collection);
        if (null !== $catalogTarget) {
            return $planner->planFromCatalog($collection, $catalogTarget);
        }

        $source = $collection->getSourceCollection();
        if (null !== $source) {
            return $planner->planFromCollection($collection, $source);
        }

        return null;
    }

    private function folderFor(ApiCollection $collection, string $name, EntityManagerInterface $em): Folder
    {
        foreach ($collection->getFolders() as $folder) {
            if (null === $folder->getParent() && $folder->getName() === $name) {
                return $folder;
            }
        }

        $folder = new Folder();
        $folder->setCollection($collection);
        $folder->setName($name);
        $folder->setPosition(\count($collection->getFolders()));
        $em->persist($folder);

        return $folder;
    }

    /** Overwrites the spec-defined fields; position/folder/examples stay local. */
    private function copyInto(ApiRequest $existing, ApiRequest $incoming): void
    {
        $existing->setName($incoming->getName());
        $existing->setMethod($incoming->getMethod());
        $existing->setUrl($incoming->getUrl());
        $existing->setHeaders($incoming->getHeaders());
        $existing->setQueryParams($incoming->getQueryParams());
        $existing->setBodyMode($incoming->getBodyMode());
        $existing->setBody($incoming->getBody());
        $existing->setAuth($incoming->getAuth());
        $existing->setDeprecated(false);
    }
}
