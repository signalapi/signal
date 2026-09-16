<?php

namespace App\Controller\App;

use App\Entity\Evaluation;
use App\Entity\EvaluationRun;
use App\Entity\Workspace;
use App\Message\RunEvaluationMessage;
use App\Repository\EnvironmentRepository;
use App\Repository\EvaluationRepository;
use App\Repository\EvaluationRunRepository;
use App\Repository\TestFlowRepository;
use App\Service\EnvironmentResolver;
use App\Service\EvaluationRunner;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Evaluations: saved measurements of a flow that does not answer the same way
 * twice. The list shows the latest rate, the detail page shows the rate over
 * time — which is the only view that answers whether a change helped.
 */
#[Route('/app/workspaces/{workspace}/evaluations')]
#[IsGranted('ROLE_USER')]
class EvaluationController extends AbstractAppController
{
    public function __construct(
        private readonly EvaluationRepository $evaluations,
        private readonly EvaluationRunRepository $runs,
    ) {
    }

    #[Route('', name: 'app_evaluation_index', methods: ['GET'])]
    public function index(Workspace $workspace, TestFlowRepository $flows): Response
    {
        $this->assertWorkspace($workspace);

        $rows = [];
        foreach ($this->evaluations->findByWorkspace($workspace) as $evaluation) {
            $rows[] = ['evaluation' => $evaluation, 'latest' => $this->runs->latestFor($evaluation)];
        }

        return $this->render('app/evaluation/index.html.twig', [
            'workspace' => $workspace,
            'rows' => $rows,
            'flows' => $flows->findByWorkspace($workspace),
        ]);
    }

    #[Route('/new', name: 'app_evaluation_create', methods: ['POST'])]
    public function create(
        Workspace $workspace,
        Request $request,
        TestFlowRepository $flows,
        EnvironmentRepository $environments,
        TranslatorInterface $translator,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        if (!$this->isCsrfTokenValid('new-evaluation' . $workspace->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $flow = $flows->find((string) $request->request->get('flow'));
        if (null === $flow || $flow->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            $this->addFlash('error', $translator->trans('Pick a test to measure.'));

            return $this->redirectToRoute('app_evaluation_index', ['workspace' => $workspace->getId()]);
        }

        $evaluation = new Evaluation();
        $evaluation->setWorkspace($workspace);
        $evaluation->setFlow($flow);
        $evaluation->setName(mb_substr(trim((string) $request->request->get('name')) ?: $flow->getName(), 0, 150));
        $evaluation->setRepeats(max(1, min(20, (int) $request->request->get('repeats', 5))));
        $this->applyEnvironment($evaluation, $workspace, $request, $environments);
        $this->applyDataset($evaluation, $request);
        $this->evaluations->save($evaluation);

        $this->addFlash('success', $translator->trans('Evaluation created.'));

        return $this->redirectToRoute('app_evaluation_show', [
            'workspace' => $workspace->getId(),
            'evaluation' => $evaluation->getId(),
        ]);
    }

    #[Route('/{evaluation}', name: 'app_evaluation_show', methods: ['GET'])]
    public function show(
        Workspace $workspace,
        #[MapEntity(mapping: ['evaluation' => 'id'])] Evaluation $evaluation,
        EnvironmentRepository $environments,
    ): Response {
        $this->assertWorkspace($workspace);
        $this->assertEvaluation($workspace, $evaluation);

        return $this->render('app/evaluation/show.html.twig', [
            'workspace' => $workspace,
            'evaluation' => $evaluation,
            'runs' => $this->runs->recentFor($evaluation, 30),
            'trend' => $this->runs->trendFor($evaluation, 60),
            'environments' => $environments->findByWorkspace($workspace),
        ]);
    }

    #[Route('/{evaluation}/update', name: 'app_evaluation_update', methods: ['POST'])]
    public function update(
        Workspace $workspace,
        #[MapEntity(mapping: ['evaluation' => 'id'])] Evaluation $evaluation,
        Request $request,
        EnvironmentRepository $environments,
        TranslatorInterface $translator,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertEvaluation($workspace, $evaluation);
        if (!$this->isCsrfTokenValid('edit-evaluation' . $evaluation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $name = trim((string) $request->request->get('name'));
        if ('' !== $name) {
            $evaluation->setName(mb_substr($name, 0, 150));
        }
        $evaluation->setRepeats(max(1, min(20, (int) $request->request->get('repeats', $evaluation->getRepeats()))));
        $this->applyEnvironment($evaluation, $workspace, $request, $environments);
        $this->applyDataset($evaluation, $request);
        $this->evaluations->save($evaluation);

        $this->addFlash('success', $translator->trans('Evaluation updated.'));

        return $this->redirectToRoute('app_evaluation_show', [
            'workspace' => $workspace->getId(),
            'evaluation' => $evaluation->getId(),
        ]);
    }

    #[Route('/{evaluation}/run', name: 'app_evaluation_run', methods: ['POST'])]
    public function run(
        Workspace $workspace,
        #[MapEntity(mapping: ['evaluation' => 'id'])] Evaluation $evaluation,
        Request $request,
        EvaluationRunner $runner,
        MessageBusInterface $bus,
        EnvironmentResolver $envResolver,
        TranslatorInterface $translator,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertEvaluation($workspace, $evaluation);
        if (!$this->isCsrfTokenValid('run-evaluation' . $evaluation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if ([] === $evaluation->getDataset()) {
            $this->addFlash('error', $translator->trans('The evaluation has no rows.'));

            return $this->redirectToRoute('app_evaluation_show', ['workspace' => $workspace->getId(), 'evaluation' => $evaluation->getId()]);
        }

        $run = $runner->createRun($evaluation, 'manual');
        // Always on the worker: an evaluation is rows × repeats, and the point
        // of it is to be big enough that waiting for it is not an option.
        $actorId = $this->currentUser()?->getId();
        $bus->dispatch(new RunEvaluationMessage(
            (string) $run->getId(),
            null !== $actorId ? (string) $actorId : null,
            $envResolver->overridesFor($evaluation->getEnvironment(), $this->currentUser()),
        ));

        $this->addFlash('success', $translator->trans('Evaluation queued: %count% runs.', ['%count%' => $evaluation->plannedRuns()]));

        return $this->redirectToRoute('app_evaluation_show', ['workspace' => $workspace->getId(), 'evaluation' => $evaluation->getId()]);
    }

    #[Route('/{evaluation}/runs/{run}', name: 'app_evaluation_run_show', methods: ['GET'])]
    public function runShow(
        Workspace $workspace,
        #[MapEntity(mapping: ['evaluation' => 'id'])] Evaluation $evaluation,
        #[MapEntity(mapping: ['run' => 'id'])] EvaluationRun $run,
    ): Response {
        $this->assertWorkspace($workspace);
        $this->assertEvaluation($workspace, $evaluation);
        if ($run->getEvaluation()->getId()?->toRfc4122() !== $evaluation->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }

        return $this->render('app/evaluation/run_show.html.twig', [
            'workspace' => $workspace,
            'evaluation' => $evaluation,
            'run' => $run,
            'report' => $run->getReport() ?? [],
        ]);
    }

    #[Route('/{evaluation}/delete', name: 'app_evaluation_delete', methods: ['POST'])]
    public function delete(
        Workspace $workspace,
        #[MapEntity(mapping: ['evaluation' => 'id'])] Evaluation $evaluation,
        Request $request,
        TranslatorInterface $translator,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertEvaluation($workspace, $evaluation);
        if (!$this->isCsrfTokenValid('delete-evaluation' . $evaluation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->evaluations->remove($evaluation);
        $this->addFlash('success', $translator->trans('Evaluation deleted.'));

        return $this->redirectToRoute('app_evaluation_index', ['workspace' => $workspace->getId()]);
    }

    /**
     * The dataset arrives as the same JSON the data-driven grid posts, so the
     * two editors stay interchangeable.
     */
    private function applyDataset(Evaluation $evaluation, Request $request): void
    {
        $raw = trim((string) $request->request->get('dataset'));
        if ('' === $raw) {
            return;
        }
        $decoded = json_decode($raw, true);
        if (!\is_array($decoded)) {
            return;
        }
        $rows = [];
        foreach ($decoded as $row) {
            if (\is_array($row)) {
                $rows[] = $row;
            }
        }
        $evaluation->setDataset($rows);
    }

    private function applyEnvironment(Evaluation $evaluation, Workspace $workspace, Request $request, EnvironmentRepository $environments): void
    {
        $envId = (string) $request->request->get('environment');
        if ('' === $envId) {
            $evaluation->setEnvironment(null);

            return;
        }
        $environment = $environments->find($envId);
        if (null !== $environment && $environment->getWorkspace()->getId()?->toRfc4122() === $workspace->getId()?->toRfc4122()) {
            $evaluation->setEnvironment($environment);
        }
    }

    private function assertEvaluation(Workspace $workspace, Evaluation $evaluation): void
    {
        if ($evaluation->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }
    }
}
