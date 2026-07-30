<?php

namespace App\Controller\App;

use App\Entity\FlowRun;
use App\Entity\StepResult;
use App\Entity\TestFlow;
use App\Entity\Workspace;
use App\Message\RunFlowMessage;
use App\Repository\EnvironmentRepository;
use App\Repository\FlowRunRepository;
use App\Service\EnvironmentResolver;
use App\Service\FlowRunner;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/app/workspaces/{workspace}/flows/{flow}/runs')]
#[IsGranted('ROLE_USER')]
class FlowRunController extends AbstractAppController
{
    #[Route('/run', name: 'app_flow_run', methods: ['POST'])]
    public function run(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        Request $httpRequest,
        EnvironmentRepository $environments,
        FlowRunner $runner,
        TranslatorInterface $translator,
        EnvironmentResolver $envResolver,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertFlow($workspace, $flow);

        if (!$this->isCsrfTokenValid('run-flow' . $flow->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if ($flow->getSteps()->isEmpty()) {
            $this->addFlash('error', $translator->trans('The flow has no steps.'));

            return $this->redirectToRoute('app_flow_show', ['workspace' => $workspace->getId(), 'flow' => $flow->getId()]);
        }

        // Environment: explicit selection > flow default.
        $environment = $flow->getDefaultEnvironment();
        $envId = (string) $httpRequest->request->get('environment');
        if ('' !== $envId) {
            $selected = $environments->find($envId);
            if ($selected && $selected->getWorkspace()->getId()?->toRfc4122() === $workspace->getId()?->toRfc4122()) {
                $environment = $selected;
            }
        }

        $vars = [];
        $rawVars = trim((string) $httpRequest->request->get('variables'));
        if ('' !== $rawVars) {
            $decoded = json_decode($rawVars, true);
            if (\is_array($decoded)) {
                foreach ($decoded as $k => $v) {
                    $vars[(string) $k] = \is_scalar($v) ? (string) $v : (string) json_encode($v);
                }
            }
        }

        // The acting user's personal environment values ride along; explicit
        // run variables still win over them.
        $vars = array_merge($envResolver->overridesFor($environment, $this->currentUser()), $vars);

        $run = $runner->run($flow, $environment, 'manual', $vars, $this->currentUser());

        $this->addFlash(
            FlowRun::STATUS_PASSED === $run->getStatus() ? 'success' : 'error',
            $translator->trans('Run finished: %status% (%passed%/%total% steps passed).', [
                '%status%' => strtoupper($run->getStatus()),
                '%passed%' => $run->getPassedSteps(),
                '%total%' => $run->getTotalSteps(),
            ]),
        );

        return $this->redirectToRoute('app_flow_run_show', [
            'workspace' => $workspace->getId(),
            'flow' => $flow->getId(),
            'run' => $run->getId(),
        ]);
    }

    #[Route('/run-async', name: 'app_flow_run_async', methods: ['POST'])]
    public function runAsync(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        Request $httpRequest,
        EnvironmentRepository $environments,
        FlowRunner $runner,
        MessageBusInterface $bus,
        TranslatorInterface $translator,
        EnvironmentResolver $envResolver,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertFlow($workspace, $flow);

        if (!$this->isCsrfTokenValid('run-flow' . $flow->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if ($flow->getSteps()->isEmpty()) {
            $this->addFlash('error', $translator->trans('The flow has no steps.'));

            return $this->redirectToRoute('app_flow_show', ['workspace' => $workspace->getId(), 'flow' => $flow->getId()]);
        }

        $environment = $flow->getDefaultEnvironment();
        $envId = (string) $httpRequest->request->get('environment');
        if ('' !== $envId) {
            $selected = $environments->find($envId);
            if ($selected && $selected->getWorkspace()->getId()?->toRfc4122() === $workspace->getId()?->toRfc4122()) {
                $environment = $selected;
            }
        }

        $run = $runner->createRun($flow, $environment, 'async', null, 0, [], $this->currentUser());

        // The worker has no request context, so the acting user's personal
        // environment values travel with the message; explicit run variables
        // still win over them.
        $vars = $envResolver->overridesFor($environment, $this->currentUser());
        $rawVars = trim((string) $httpRequest->request->get('variables'));
        if ('' !== $rawVars) {
            $decoded = json_decode($rawVars, true);
            if (\is_array($decoded)) {
                foreach ($decoded as $k => $v) {
                    $vars[(string) $k] = \is_scalar($v) ? (string) $v : (string) json_encode($v);
                }
            }
        }

        $bus->dispatch(new RunFlowMessage(
            (string) $run->getId(),
            (string) $flow->getId(),
            $environment ? (string) $environment->getId() : null,
            $vars,
            (string) $this->currentUser()->getId(),
        ));

        // Live in-page run: the flow detail page starts the run over fetch and polls /status,
        // so it needs the run id back as JSON rather than a redirect.
        if ($httpRequest->isXmlHttpRequest()) {
            return new JsonResponse([
                'runId' => (string) $run->getId(),
                'totalSteps' => $run->getTotalSteps(),
                'statusUrl' => $this->generateUrl('app_flow_run_status', [
                    'workspace' => $workspace->getId(),
                    'flow' => $flow->getId(),
                    'run' => $run->getId(),
                ]),
                'detailUrl' => $this->generateUrl('app_flow_run_show', [
                    'workspace' => $workspace->getId(),
                    'flow' => $flow->getId(),
                    'run' => $run->getId(),
                ]),
            ]);
        }

        $this->addFlash('success', $translator->trans('Flow started in the background — progress updates live below.'));

        return $this->redirectToRoute('app_flow_run_show', [
            'workspace' => $workspace->getId(),
            'flow' => $flow->getId(),
            'run' => $run->getId(),
        ]);
    }

    #[Route('/{run}/status', name: 'app_flow_run_status', methods: ['GET'])]
    public function runStatus(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        #[MapEntity(mapping: ['run' => 'id'])] FlowRun $run,
    ): JsonResponse {
        $this->assertWorkspace($workspace);
        $this->assertFlow($workspace, $flow);
        if ($run->getFlow()->getId()?->toRfc4122() !== $flow->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }

        $steps = array_map(static fn (StepResult $r) => [
            'position' => $r->getPosition(),
            'label' => $r->getLabel(),
            'status' => $r->getStatus(),
            'method' => $r->getRequestMethod(),
            'responseStatus' => $r->getResponseStatus(),
            'durationMs' => $r->getDurationMs(),
            'attempts' => $r->getAttempts(),
        ], $run->getStepResults()->toArray());

        return new JsonResponse([
            'status' => $run->getStatus(),
            'finished' => FlowRun::STATUS_RUNNING !== $run->getStatus(),
            'passedSteps' => $run->getPassedSteps(),
            'totalSteps' => $run->getTotalSteps(),
            'cancelRequested' => $run->isCancelRequested(),
            'steps' => $steps,
        ]);
    }

    /**
     * Asks Claude to explain the failure and propose a fix. Dormant until an
     * ANTHROPIC_API_KEY is set — returns {configured:false} otherwise.
     */
    #[Route('/{run}/diagnose', name: 'app_flow_run_diagnose', methods: ['POST'])]
    public function diagnose(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        #[MapEntity(mapping: ['run' => 'id'])] FlowRun $run,
        Request $httpRequest,
        \App\Service\AiDiagnoser $ai,
    ): JsonResponse {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertFlow($workspace, $flow);
        if ($run->getFlow()->getId()?->toRfc4122() !== $flow->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('diagnose-run' . $run->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if (!$ai->isConfigured()) {
            return new JsonResponse(['configured' => false]);
        }

        try {
            return new JsonResponse(['configured' => true, 'analysis' => $ai->diagnose($run, $httpRequest->getLocale())]);
        } catch (\Throwable $e) {
            return new JsonResponse(['configured' => true, 'error' => $e->getMessage()], 502);
        }
    }

    /**
     * Creates (or refreshes) a public, login-less report link for this run,
     * valid for 7 days. Returns the absolute URL.
     */
    #[Route('/{run}/share', name: 'app_flow_run_share', methods: ['POST'])]
    public function share(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        #[MapEntity(mapping: ['run' => 'id'])] FlowRun $run,
        Request $httpRequest,
        FlowRunRepository $runs,
    ): JsonResponse {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertFlow($workspace, $flow);
        if ($run->getFlow()->getId()?->toRfc4122() !== $flow->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('share-run' . $run->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if (null === $run->getShareToken()) {
            $run->setShareToken(bin2hex(random_bytes(16)));
        }
        $run->setShareExpiresAt(new \DateTimeImmutable('+7 days'));
        $runs->save($run);

        return new JsonResponse([
            'url' => $this->generateUrl('public_report', ['token' => $run->getShareToken()], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL),
            'expiresAt' => $run->getShareExpiresAt()?->format('d.m.Y H:i'),
        ]);
    }

    #[Route('/{run}/unshare', name: 'app_flow_run_unshare', methods: ['POST'])]
    public function unshare(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        #[MapEntity(mapping: ['run' => 'id'])] FlowRun $run,
        Request $httpRequest,
        FlowRunRepository $runs,
    ): JsonResponse {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertFlow($workspace, $flow);
        if ($run->getFlow()->getId()?->toRfc4122() !== $flow->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('share-run' . $run->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $run->setShareToken(null);
        $run->setShareExpiresAt(null);
        $runs->save($run);

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/{run}/cancel', name: 'app_flow_run_cancel', methods: ['POST'])]
    public function cancel(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        #[MapEntity(mapping: ['run' => 'id'])] FlowRun $run,
        Request $httpRequest,
        FlowRunRepository $runs,
        TranslatorInterface $translator,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertFlow($workspace, $flow);
        if ($run->getFlow()->getId()?->toRfc4122() !== $flow->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('cancel-run' . $run->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if (FlowRun::STATUS_RUNNING === $run->getStatus()) {
            $run->setCancelRequested(true);
            $runs->save($run);
            $this->addFlash('success', $translator->trans('Cancellation requested — it will stop once the running step finishes.'));
        }

        return $this->redirectToRoute('app_flow_run_show', [
            'workspace' => $workspace->getId(),
            'flow' => $flow->getId(),
            'run' => $run->getId(),
        ]);
    }

    #[Route('/run-data', name: 'app_flow_run_data', methods: ['POST'])]
    public function runData(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        Request $httpRequest,
        EnvironmentRepository $environments,
        FlowRunner $runner,
        TranslatorInterface $translator,
        EnvironmentResolver $envResolver,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertFlow($workspace, $flow);

        if (!$this->isCsrfTokenValid('run-flow' . $flow->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if ($flow->getSteps()->isEmpty()) {
            $this->addFlash('error', $translator->trans('The flow has no steps.'));

            return $this->redirectToRoute('app_flow_show', ['workspace' => $workspace->getId(), 'flow' => $flow->getId()]);
        }

        try {
            $dataset = $this->parseDataset((string) $httpRequest->request->get('dataset'));
        } catch (\Throwable $e) {
            $this->addFlash('error', $translator->trans('Dataset error: %error%', ['%error%' => $e->getMessage()]));

            return $this->redirectToRoute('app_flow_show', ['workspace' => $workspace->getId(), 'flow' => $flow->getId()]);
        }
        if ([] === $dataset) {
            $this->addFlash('error', $translator->trans('Dataset is empty.'));

            return $this->redirectToRoute('app_flow_show', ['workspace' => $workspace->getId(), 'flow' => $flow->getId()]);
        }

        $environment = $flow->getDefaultEnvironment();
        $envId = (string) $httpRequest->request->get('environment');
        if ('' !== $envId) {
            $selected = $environments->find($envId);
            if ($selected && $selected->getWorkspace()->getId()?->toRfc4122() === $workspace->getId()?->toRfc4122()) {
                $environment = $selected;
            }
        }

        $runs = $runner->runDataset(
            $flow,
            $environment,
            $dataset,
            'manual',
            $envResolver->overridesFor($environment, $this->currentUser()),
            $this->currentUser(),
        );
        $passed = \count(array_filter($runs, static fn (FlowRun $r) => FlowRun::STATUS_PASSED === $r->getStatus()));
        $this->addFlash($passed === \count($runs) ? 'success' : 'error', $translator->trans('Data-driven run: %passed%/%total% iterations passed.', ['%passed%' => $passed, '%total%' => \count($runs)]));

        return $this->redirectToRoute('app_flow_batch_show', [
            'workspace' => $workspace->getId(),
            'flow' => $flow->getId(),
            'batchId' => $runs[0]->getBatchId(),
        ]);
    }

    #[Route('/batch/{batchId}', name: 'app_flow_batch_show', methods: ['GET'])]
    public function batch(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        string $batchId,
        FlowRunRepository $runs,
    ): Response {
        $this->assertWorkspace($workspace);
        $this->assertFlow($workspace, $flow);

        $list = array_filter(
            $runs->findByBatch($batchId),
            static fn (FlowRun $r) => $r->getFlow()->getId()?->toRfc4122() === $flow->getId()?->toRfc4122(),
        );
        $passed = \count(array_filter($list, static fn (FlowRun $r) => FlowRun::STATUS_PASSED === $r->getStatus()));

        return $this->render('app/flow/batch_show.html.twig', [
            'workspace' => $workspace,
            'flow' => $flow,
            'runs' => $list,
            'passed' => $passed,
            'total' => \count($list),
        ]);
    }

    #[Route('/{run}', name: 'app_flow_run_show', methods: ['GET'])]
    public function show(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        #[MapEntity(mapping: ['run' => 'id'])] FlowRun $run,
        \App\Repository\FlowGroupRunRepository $groupRuns,
    ): Response {
        $this->assertWorkspace($workspace);
        $this->assertFlow($workspace, $flow);

        if ($run->getFlow()->getId()?->toRfc4122() !== $flow->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }

        // If this run was part of a suite batch, resolve that suite for the back-link.
        $suiteGroup = null;
        if ('group' === $run->getTrigger() && null !== $run->getBatchId()) {
            $suiteGroup = $groupRuns->findOneByBatch($run->getBatchId())?->getFlowGroup();
        }

        return $this->render('app/flow/run_show.html.twig', [
            'workspace' => $workspace,
            'flow' => $flow,
            'run' => $run,
            'suiteGroup' => $suiteGroup,
        ]);
    }

    /**
     * Parses a dataset from a JSON array (or single object) or CSV (header row + rows).
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseDataset(string $raw): array
    {
        $raw = trim($raw);
        if ('' === $raw) {
            return [];
        }

        if (str_starts_with($raw, '[') || str_starts_with($raw, '{')) {
            $data = json_decode($raw, true);
            if (\JSON_ERROR_NONE !== json_last_error() || !\is_array($data)) {
                throw new \InvalidArgumentException('Not valid JSON.');
            }

            return array_is_list($data) ? $data : [$data];
        }

        // CSV: first line = headers
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $headers = str_getcsv((string) array_shift($lines));
        $rows = [];
        foreach ($lines as $line) {
            if ('' === trim($line)) {
                continue;
            }
            $vals = str_getcsv($line);
            $row = [];
            foreach ($headers as $i => $h) {
                $row[trim((string) $h)] = $vals[$i] ?? '';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function assertFlow(Workspace $workspace, TestFlow $flow): void
    {
        if ($flow->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }
    }
}
