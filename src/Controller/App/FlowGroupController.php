<?php

namespace App\Controller\App;

use App\Entity\FlowGroup;
use App\Entity\TestFlow;
use App\Entity\Workspace;
use App\Message\RunFlowGroupMessage;
use App\Repository\EnvironmentRepository;
use App\Repository\FlowGroupRepository;
use App\Repository\FlowRunRepository;
use App\Repository\TestFlowRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Flow groups (suites): bundle flows and run them all sequentially in the background.
 */
#[Route('/app/workspaces/{workspace}/flow-groups')]
#[IsGranted('ROLE_USER')]
class FlowGroupController extends AbstractAppController
{
    /**
     * Suites merged into the Tests page; kept as a permanent redirect so old
     * bookmarks and links keep working.
     */
    #[Route('', name: 'app_flow_suite_index', methods: ['GET'])]
    public function index(Workspace $workspace): Response
    {
        $this->assertWorkspace($workspace);

        return $this->redirectToRoute('app_flow_index', ['workspace' => $workspace->getId()], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/{group}/update', name: 'app_flow_group_update', methods: ['POST'])]
    public function update(
        Workspace $workspace,
        #[MapEntity(mapping: ['group' => 'id'])] FlowGroup $group,
        Request $httpRequest,
        FlowGroupRepository $groups,
        TranslatorInterface $translator,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertGroup($workspace, $group);
        if (!$this->isCsrfTokenValid('flow-group-update' . $group->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $name = trim((string) $httpRequest->request->get('name'));
        if ('' !== $name) {
            $group->setName($name);
        }
        $group->setDescription(trim((string) $httpRequest->request->get('description')) ?: null);
        $groups->save($group);
        $this->addFlash('success', $translator->trans('Suite updated.'));

        return $this->redirectToRoute('app_flow_suite_index', ['workspace' => $workspace->getId()]);
    }

    #[Route('', name: 'app_flow_group_create', methods: ['POST'])]
    public function create(
        Workspace $workspace,
        Request $httpRequest,
        FlowGroupRepository $groups,
        TranslatorInterface $translator,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        if (!$this->isCsrfTokenValid('flow-group', (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $name = trim((string) $httpRequest->request->get('name'));
        if ('' !== $name) {
            $group = new FlowGroup();
            $group->setWorkspace($workspace);
            $group->setName($name);
            $groups->save($group);
            $this->addFlash('success', $translator->trans('Group created: %name%', ['%name%' => $name]));
        }

        return $this->redirectToRoute('app_flow_index', ['workspace' => $workspace->getId()]);
    }

    #[Route('/{group}/delete', name: 'app_flow_group_delete', methods: ['POST'])]
    public function delete(
        Workspace $workspace,
        #[MapEntity(mapping: ['group' => 'id'])] FlowGroup $group,
        Request $httpRequest,
        FlowGroupRepository $groups,
        TranslatorInterface $translator,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertGroup($workspace, $group);
        if (!$this->isCsrfTokenValid('flow-group-delete' . $group->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $groups->remove($group);
        $this->addFlash('success', $translator->trans('Group deleted (flows kept).'));

        return $this->redirectToRoute('app_flow_index', ['workspace' => $workspace->getId()]);
    }

    #[Route('/assign', name: 'app_flow_group_assign', methods: ['POST'])]
    public function assign(
        Workspace $workspace,
        Request $httpRequest,
        TestFlowRepository $flows,
        FlowGroupRepository $groups,
        EntityManagerInterface $em,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        if (!$this->isCsrfTokenValid('flow-group', (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $flow = $flows->find((string) $httpRequest->request->get('flow'));
        if (!$flow instanceof TestFlow || $flow->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }

        $group = $groups->find((string) $httpRequest->request->get('group'));
        if ($group instanceof FlowGroup && $group->getWorkspace()->getId()?->toRfc4122() === $workspace->getId()?->toRfc4122()) {
            // A flow may belong to many suites; adding here doesn't remove others.
            $group->addFlow($flow);
            $em->flush();
        }

        return $this->redirectToRoute('app_flow_index', ['workspace' => $workspace->getId()]);
    }

    #[Route('/unassign', name: 'app_flow_group_unassign', methods: ['POST'])]
    public function unassign(
        Workspace $workspace,
        Request $httpRequest,
        TestFlowRepository $flows,
        FlowGroupRepository $groups,
        EntityManagerInterface $em,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        if (!$this->isCsrfTokenValid('flow-group', (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $flow = $flows->find((string) $httpRequest->request->get('flow'));
        $group = $groups->find((string) $httpRequest->request->get('group'));
        if ($flow instanceof TestFlow && $group instanceof FlowGroup
            && $flow->getWorkspace()->getId()?->toRfc4122() === $workspace->getId()?->toRfc4122()
            && $group->getWorkspace()->getId()?->toRfc4122() === $workspace->getId()?->toRfc4122()) {
            $group->removeFlow($flow);
            $em->flush();
        }

        return $this->redirectToRoute('app_flow_index', ['workspace' => $workspace->getId()]);
    }

    #[Route('/{group}/run', name: 'app_flow_group_run', methods: ['POST'])]
    public function run(
        Workspace $workspace,
        #[MapEntity(mapping: ['group' => 'id'])] FlowGroup $group,
        Request $httpRequest,
        EnvironmentRepository $environments,
        \App\Repository\FlowGroupRunRepository $groupRuns,
        \App\Repository\NotificationDestinationRepository $destinations,
        MessageBusInterface $bus,
        TranslatorInterface $translator,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertGroup($workspace, $group);
        if (!$this->isCsrfTokenValid('flow-group-run' . $group->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if ($group->getFlows()->isEmpty()) {
            $this->addFlash('error', $translator->trans('The group has no flows.'));

            return $this->redirectToRoute('app_flow_suite_index', ['workspace' => $workspace->getId()]);
        }

        $envId = null;
        $rawEnv = (string) $httpRequest->request->get('environment');
        if ('' !== $rawEnv) {
            $env = $environments->find($rawEnv);
            if ($env && $env->getWorkspace()->getId()?->toRfc4122() === $workspace->getId()?->toRfc4122()) {
                $envId = (string) $env->getId();
            }
        }

        // Record the suite run immediately (status=running) so it shows up right away.
        $batchId = Uuid::v4()->toRfc4122();
        $groupRun = new \App\Entity\FlowGroupRun();
        $groupRun->setFlowGroup($group);
        $groupRun->setBatchId($batchId);
        $groupRun->setTotal($group->getFlows()->count());
        $groupRun->setTrigger('manual');
        // "notify" carries the choice from the run bar: a destination id sends
        // the result there whatever the rules say, "none" mutes this one run.
        $groupRun->setNotifyOverride($this->notifyOverride($workspace, $destinations, (string) $httpRequest->request->get('notify')));
        $groupRuns->save($groupRun);

        $bus->dispatch(new RunFlowGroupMessage((string) $group->getId(), $batchId, $envId, (string) $this->currentUser()->getId()));

        return $this->redirectToRoute('app_flow_group_run_show', [
            'workspace' => $workspace->getId(),
            'group' => $group->getId(),
            'batchId' => $batchId,
        ]);
    }

    #[Route('/{group}/runs/{batchId}', name: 'app_flow_group_run_show', methods: ['GET'])]
    public function runShow(
        Workspace $workspace,
        #[MapEntity(mapping: ['group' => 'id'])] FlowGroup $group,
        string $batchId,
        FlowRunRepository $runs,
        \App\Repository\FlowGroupRunRepository $groupRuns,
    ): Response {
        $this->assertWorkspace($workspace);
        $this->assertGroup($workspace, $group);

        // Pre-render the rows server-side (no "starting…" flash); only poll if still running.
        $byIteration = [];
        $done = 0;
        foreach ($runs->findByBatch($batchId) as $r) {
            $finishedRun = 'running' !== $r->getStatus();
            if ($finishedRun) {
                ++$done;
            }
            $byIteration[$r->getIteration()] = [
                'status' => $r->getStatus(),
                'passed' => $r->getPassedSteps(),
                'total' => $r->getTotalSteps(),
                'durationMs' => $r->getDurationMs(),
                'detailUrl' => $this->generateUrl('app_flow_run_show', [
                    'workspace' => $workspace->getId(), 'flow' => $r->getFlow()->getId(), 'run' => $r->getId(),
                ]),
            ];
        }
        $groupRun = $groupRuns->findOneByBatch($batchId);
        $total = $group->getFlows()->count();
        $finished = null !== $groupRun ? 'running' !== $groupRun->getStatus() : ($done >= $total && $total > 0);

        return $this->render('app/flow/group_run.html.twig', [
            'workspace' => $workspace,
            'group' => $group,
            'batchId' => $batchId,
            'total' => $total,
            'byIteration' => $byIteration,
            'groupRun' => $groupRun,
            'finished' => $finished,
            'done' => $done,
        ]);
    }

    #[Route('/{group}/runs/{batchId}/status', name: 'app_flow_group_run_status', methods: ['GET'])]
    public function runStatus(
        Workspace $workspace,
        #[MapEntity(mapping: ['group' => 'id'])] FlowGroup $group,
        string $batchId,
        FlowRunRepository $runs,
    ): JsonResponse {
        $this->assertWorkspace($workspace);
        $this->assertGroup($workspace, $group);

        $expected = $group->getFlows()->count();
        $rows = [];
        $done = 0;
        foreach ($runs->findByBatch($batchId) as $r) {
            $finished = 'running' !== $r->getStatus();
            if ($finished) {
                ++$done;
            }
            $rows[] = [
                'runId' => (string) $r->getId(),
                'iteration' => $r->getIteration(),
                'flow' => $r->getFlow()->getName(),
                'status' => $r->getStatus(),
                'passed' => $r->getPassedSteps(),
                'total' => $r->getTotalSteps(),
                'durationMs' => $r->getDurationMs(),
                'detailUrl' => $this->generateUrl('app_flow_run_show', [
                    'workspace' => $workspace->getId(),
                    'flow' => $r->getFlow()->getId(),
                    'run' => $r->getId(),
                ]),
            ];
        }

        return new JsonResponse([
            'expected' => $expected,
            'started' => \count($rows),
            'finished' => $done >= $expected && $expected > 0,
            'runs' => $rows,
        ]);
    }

    private function assertGroup(Workspace $workspace, FlowGroup $group): void
    {
        if ($group->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }
    }

    /**
     * Turns the run bar's "notify" value into a run-level override:
     * "" keeps the standing rules, "none" mutes them, an id adds that
     * destination (validated against this workspace).
     *
     * @return array<string, mixed>|null
     */
    private function notifyOverride(
        Workspace $workspace,
        \App\Repository\NotificationDestinationRepository $destinations,
        string $notify,
    ): ?array {
        if ('' === $notify) {
            return null;
        }
        if ('none' === $notify) {
            return ['mute' => true];
        }
        if (!\Symfony\Component\Uid\Uuid::isValid($notify)
            || [] === $destinations->findActiveByWorkspaceAndIds($workspace, [$notify])) {
            return null;
        }

        return ['destinations' => [$notify], 'condition' => \App\Entity\NotificationSubscription::WHEN_ALWAYS];
    }
}
