<?php

namespace App\Controller\App;

use App\Entity\FlowRun;
use App\Entity\StepResult;
use App\Entity\TestFlow;
use App\Entity\Workspace;
use App\Message\RunFlowMessage;
use App\Repository\EnvironmentRepository;
use App\Repository\FlowRunRepository;
use App\Service\FlowRunner;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/app/workspaces/{workspace}/flows/{flow}/runs')]
#[IsGranted('ROLE_MERCHANT')]
class FlowRunController extends AbstractAppController
{
    #[Route('/run', name: 'app_flow_run', methods: ['POST'])]
    public function run(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        Request $httpRequest,
        EnvironmentRepository $environments,
        FlowRunner $runner,
    ): Response {
        $this->assertWorkspace($workspace);
        $this->assertFlow($workspace, $flow);

        if (!$this->isCsrfTokenValid('run-flow' . $flow->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if ($flow->getSteps()->isEmpty()) {
            $this->addFlash('error', 'Akışta hiç adım yok.');

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

        $run = $runner->run($flow, $environment, 'manual', $vars);

        $this->addFlash(
            FlowRun::STATUS_PASSED === $run->getStatus() ? 'success' : 'error',
            sprintf('Koşum tamamlandı: %s (%d/%d adım geçti).', strtoupper($run->getStatus()), $run->getPassedSteps(), $run->getTotalSteps()),
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
    ): Response {
        $this->assertWorkspace($workspace);
        $this->assertFlow($workspace, $flow);

        if (!$this->isCsrfTokenValid('run-flow' . $flow->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if ($flow->getSteps()->isEmpty()) {
            $this->addFlash('error', 'Akışta hiç adım yok.');

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

        $run = $runner->createRun($flow, $environment, 'async', null, 0, []);
        $bus->dispatch(new RunFlowMessage(
            (string) $run->getId(),
            (string) $flow->getId(),
            $environment ? (string) $environment->getId() : null,
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

        $this->addFlash('success', 'Akış arka planda başlatıldı — ilerleme aşağıda canlı güncellenir.');

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
        $this->assertWorkspace($workspace);
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
            return new JsonResponse(['configured' => true, 'analysis' => $ai->diagnose($run)]);
        } catch (\Throwable $e) {
            return new JsonResponse(['configured' => true, 'error' => $e->getMessage()], 502);
        }
    }

    #[Route('/{run}/cancel', name: 'app_flow_run_cancel', methods: ['POST'])]
    public function cancel(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        #[MapEntity(mapping: ['run' => 'id'])] FlowRun $run,
        Request $httpRequest,
        FlowRunRepository $runs,
    ): Response {
        $this->assertWorkspace($workspace);
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
            $this->addFlash('success', 'İptal istendi — çalışan adım bitince durdurulacak.');
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
    ): Response {
        $this->assertWorkspace($workspace);
        $this->assertFlow($workspace, $flow);

        if (!$this->isCsrfTokenValid('run-flow' . $flow->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if ($flow->getSteps()->isEmpty()) {
            $this->addFlash('error', 'Akışta hiç adım yok.');

            return $this->redirectToRoute('app_flow_show', ['workspace' => $workspace->getId(), 'flow' => $flow->getId()]);
        }

        try {
            $dataset = $this->parseDataset((string) $httpRequest->request->get('dataset'));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Veri kümesi hatası: ' . $e->getMessage());

            return $this->redirectToRoute('app_flow_show', ['workspace' => $workspace->getId(), 'flow' => $flow->getId()]);
        }
        if ([] === $dataset) {
            $this->addFlash('error', 'Veri kümesi boş.');

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

        $runs = $runner->runDataset($flow, $environment, $dataset, 'manual');
        $passed = \count(array_filter($runs, static fn (FlowRun $r) => FlowRun::STATUS_PASSED === $r->getStatus()));
        $this->addFlash($passed === \count($runs) ? 'success' : 'error', sprintf('Data-driven koşum: %d/%d iterasyon geçti.', $passed, \count($runs)));

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
    ): Response {
        $this->assertWorkspace($workspace);
        $this->assertFlow($workspace, $flow);

        if ($run->getFlow()->getId()?->toRfc4122() !== $flow->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }

        return $this->render('app/flow/run_show.html.twig', [
            'workspace' => $workspace,
            'flow' => $flow,
            'run' => $run,
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
                throw new \InvalidArgumentException('Geçerli JSON değil.');
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
