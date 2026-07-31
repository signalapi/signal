<?php

namespace App\Controller\App;

use App\Entity\TestFlow;
use App\Entity\Workspace;
use App\Repository\EnvironmentRepository;
use App\Repository\TestFlowRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/app/workspaces/{workspace}/flows')]
#[IsGranted('ROLE_USER')]
class FlowController extends AbstractAppController
{
    /**
     * The Tests page renders suites and flows together — it replaced the separate
     * suites page, so it needs that page's data too.
     */
    #[Route('', name: 'app_flow_index', methods: ['GET'])]
    public function index(
        Workspace $workspace,
        TestFlowRepository $flows,
        \App\Repository\FlowGroupRepository $groups,
        \App\Repository\FlowGroupRunRepository $groupRunRepo,
        \App\Repository\FlowRunRepository $runs,
        \App\Repository\EnvironmentRepository $environments,
    ): Response {
        $this->assertWorkspace($workspace);

        $groupList = $groups->findByWorkspace($workspace);
        $groupRuns = [];
        foreach ($groupList as $group) {
            $rows = [];
            foreach ($groupRunRepo->recentForGroup($group, 6) as $groupRun) {
                $passed = \count(array_filter(
                    $runs->findByBatch($groupRun->getBatchId()),
                    static fn ($r) => 'passed' === $r->getStatus(),
                ));
                $rows[] = ['run' => $groupRun, 'passed' => $passed];
            }
            $groupRuns[(string) $group->getId()] = $rows;
        }

        return $this->render('app/flow/index.html.twig', [
            'workspace' => $workspace,
            'flows' => $flows->findByWorkspace($workspace),
            'groups' => $groupList,
            'groupRuns' => $groupRuns,
            'environments' => $environments->findByWorkspace($workspace),
        ]);
    }

    #[Route('/new', name: 'app_flow_new', methods: ['GET', 'POST'])]
    public function new(
        Workspace $workspace,
        Request $httpRequest,
        TestFlowRepository $flows,
        EnvironmentRepository $environments,
        TranslatorInterface $translator,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');

        if ($httpRequest->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('new-flow', (string) $httpRequest->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $flow = new TestFlow();
            $flow->setWorkspace($workspace);
            $flow->setName(trim((string) $httpRequest->request->get('name')) ?: $translator->trans('New flow'));
            $flow->setDescription($this->nullable((string) $httpRequest->request->get('description')));
            $flow->setStopOnFailure((bool) $httpRequest->request->get('stopOnFailure'));

            $envId = (string) $httpRequest->request->get('environment');
            if ('' !== $envId) {
                $env = $environments->find($envId);
                if ($env && $env->getWorkspace()->getId()?->toRfc4122() === $workspace->getId()?->toRfc4122()) {
                    $flow->setDefaultEnvironment($env);
                }
            }

            $flows->save($flow);
            $this->addFlash('success', $translator->trans('Test flow created.'));

            return $this->redirectToRoute('app_flow_show', ['workspace' => $workspace->getId(), 'flow' => $flow->getId()]);
        }

        return $this->render('app/flow/new.html.twig', [
            'workspace' => $workspace,
            'environments' => $environments->findByWorkspace($workspace),
        ]);
    }

    #[Route('/{flow}', name: 'app_flow_show', methods: ['GET'])]
    public function show(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        \App\Repository\ApiRequestRepository $requests,
        EnvironmentRepository $environments,
        \App\Repository\DbConnectionRepository $connections,
        \App\Service\FlowVariableScanner $scanner,
        \App\Repository\ScheduleRepository $schedules,
    ): Response {
        $this->assertWorkspace($workspace);
        $this->assertFlow($workspace, $flow);

        return $this->render('app/flow/show.html.twig', [
            'workspace' => $workspace,
            'flow' => $flow,
            'scheduled_count' => count($schedules->findForFlow($flow)),
            'available_requests' => $requests->findByWorkspace($workspace),
            'environments' => $environments->findByWorkspace($workspace),
            'db_connections' => $connections->findByWorkspace($workspace),
            'flow_variables' => $scanner->externalVariables($flow),
        ]);
    }


    #[Route('/{flow}/delete', name: 'app_flow_delete', methods: ['POST'])]
    public function delete(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        Request $httpRequest,
        TestFlowRepository $flows,
        TranslatorInterface $translator,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertFlow($workspace, $flow);

        if (!$this->isCsrfTokenValid('delete' . $flow->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $flows->remove($flow);
        $this->addFlash('success', $translator->trans('Test flow deleted.'));

        return $this->redirectToRoute('app_flow_index', ['workspace' => $workspace->getId()]);
    }

    private function assertFlow(Workspace $workspace, TestFlow $flow): void
    {
        if ($flow->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
