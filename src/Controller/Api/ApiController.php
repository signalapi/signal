<?php

namespace App\Controller\Api;

use App\Entity\ApiToken;
use App\Entity\Environment;
use App\Entity\TestFlow;
use App\Entity\Workspace;
use App\Repository\EnvironmentRepository;
use App\Repository\FlowRunRepository;
use App\Repository\TestFlowRepository;
use App\Security\ApiTokenAuthenticator;
use App\Service\FlowRunner;
use App\Service\FlowRunReporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1')]
class ApiController extends AbstractController
{
    public function __construct(
        private readonly \App\Repository\ScheduleRepository $schedules,
        private readonly \App\Service\ScheduleCompiler $scheduleCompiler,
    ) {
    }

    /** The user the bearer token belongs to — the actor behind API-triggered runs. */
    private function tokenOwner(): ?\App\Entity\User
    {
        $user = $this->getUser();

        return $user instanceof \App\Entity\User ? $user : null;
    }

    #[Route('/flows', name: 'api_flow_list', methods: ['GET'])]
    public function listFlows(Request $request, TestFlowRepository $flows): JsonResponse
    {
        $workspace = $this->workspace($request);

        // Scheduling moved off TestFlow into its own entity; a flow can be on
        // several schedules, or on none, or reached through a suite.
        $data = array_map(fn (TestFlow $f) => [
            'id' => (string) $f->getId(),
            'name' => $f->getName(),
            'steps' => $f->getSteps()->count(),
            'schedules' => array_map(
                fn (\App\Entity\Schedule $s) => [
                    'name' => $s->getName(),
                    'enabled' => $s->isEnabled(),
                    'timezone' => $s->getTimezone(),
                    'rules' => $this->scheduleCompiler->describe($s),
                ],
                $this->schedules->findForFlow($f),
            ),
        ], $flows->findByWorkspace($workspace));

        return $this->json(['ok' => true, 'workspace' => $workspace->getName(), 'flows' => $data]);
    }

    #[Route('/flows/{id}/run', name: 'api_flow_run', methods: ['POST'])]
    public function runFlow(
        string $id,
        Request $request,
        TestFlowRepository $flows,
        EnvironmentRepository $environments,
        FlowRunner $runner,
        FlowRunReporter $reporter,
    ): Response {
        $workspace = $this->workspace($request);
        $flow = $flows->find($id);
        if (null === $flow || $flow->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            return $this->json(['ok' => false, 'error' => 'Flow not found.'], 404);
        }
        if ($flow->getSteps()->isEmpty()) {
            return $this->json(['ok' => false, 'error' => 'Flow has no steps.'], 422);
        }

        $body = json_decode($request->getContent() ?: '', true);
        $bodyEnvRef = \is_array($body) ? ($body['environment'] ?? null) : null;
        $environment = $this->resolveEnvironment($request, $workspace, $flow, $environments, \is_string($bodyEnvRef) ? $bodyEnvRef : null);

        // Data-driven run: {"data": [ {..vars..}, ... ]}
        $dataset = (\is_array($body) && isset($body['data']) && \is_array($body['data'])) ? $body['data'] : null;
        if (null !== $dataset) {
            if ([] === $dataset) {
                return $this->json(['ok' => false, 'error' => 'data is empty.'], 422);
            }
            $runs = $runner->runDataset($flow, $environment, $dataset, 'api', [], $this->tokenOwner());
            $passed = \count(array_filter($runs, static fn ($r) => \App\Entity\FlowRun::STATUS_PASSED === $r->getStatus()));
            $allPassed = $passed === \count($runs);
            $iterations = array_map(static fn ($r) => [
                'iteration' => $r->getIteration(),
                'runId' => (string) $r->getId(),
                'status' => $r->getStatus(),
                'passedSteps' => $r->getPassedSteps(),
                'totalSteps' => $r->getTotalSteps(),
                'data' => $r->getIterationData(),
            ], $runs);

            return $this->json([
                'ok' => $allPassed,
                'batchId' => $runs[0]->getBatchId(),
                'total' => \count($runs),
                'passed' => $passed,
                'iterations' => $iterations,
            ], $allPassed ? 200 : 422);
        }

        // One-off variable injection: {"variables": {"userId": "42"}}
        $vars = [];
        if (\is_array($body) && isset($body['variables']) && \is_array($body['variables'])) {
            foreach ($body['variables'] as $k => $v) {
                $vars[(string) $k] = \is_scalar($v) ? (string) $v : (string) json_encode($v);
            }
        }

        $run = $runner->run($flow, $environment, 'api', $vars, $this->tokenOwner());

        $passed = \App\Entity\FlowRun::STATUS_PASSED === $run->getStatus();
        $httpStatus = $passed ? 200 : 422;

        if ('junit' === $request->query->get('format')) {
            return new Response($reporter->toJUnit($run), $httpStatus, ['Content-Type' => 'application/xml']);
        }

        return $this->json(['ok' => $passed, 'run' => $reporter->toArray($run)], $httpStatus);
    }

    #[Route('/flows/{id}/runs/{runId}', name: 'api_flow_run_show', methods: ['GET'])]
    public function showRun(
        string $id,
        string $runId,
        Request $request,
        TestFlowRepository $flows,
        FlowRunRepository $runs,
        FlowRunReporter $reporter,
    ): JsonResponse {
        $workspace = $this->workspace($request);
        $flow = $flows->find($id);
        if (null === $flow || $flow->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            return $this->json(['ok' => false, 'error' => 'Flow not found.'], 404);
        }

        $run = $runs->find($runId);
        if (null === $run || $run->getFlow()->getId()?->toRfc4122() !== $flow->getId()?->toRfc4122()) {
            return $this->json(['ok' => false, 'error' => 'Run not found.'], 404);
        }

        return $this->json(['ok' => true, 'run' => $reporter->toArray($run)]);
    }

    #[Route('/suites', name: 'api_suite_list', methods: ['GET'])]
    public function listSuites(Request $request, \App\Repository\FlowGroupRepository $groups, \App\Repository\FlowGroupRunRepository $groupRuns): JsonResponse
    {
        $workspace = $this->workspace($request);

        $data = [];
        foreach ($groups->findByWorkspace($workspace) as $group) {
            $recent = $groupRuns->recentForGroup($group, 1);
            $data[] = [
                'id' => (string) $group->getId(),
                'name' => $group->getName(),
                'flows' => $group->getFlows()->count(),
                'lastStatus' => [] !== $recent ? $recent[0]->getStatus() : null,
            ];
        }

        return $this->json(['ok' => true, 'workspace' => $workspace->getName(), 'suites' => $data]);
    }

    /**
     * Starts a suite batch IN THE BACKGROUND and returns its batchId — a suite
     * can run for many minutes, far past any sane HTTP timeout. CI polls the
     * status endpoint below until it stops saying "running".
     */
    #[Route('/suites/{id}/run', name: 'api_suite_run', methods: ['POST'])]
    public function runSuite(
        string $id,
        Request $request,
        \App\Repository\FlowGroupRepository $groups,
        \App\Repository\FlowGroupRunRepository $groupRuns,
        EnvironmentRepository $environments,
        \Symfony\Component\Messenger\MessageBusInterface $bus,
    ): JsonResponse {
        $workspace = $this->workspace($request);
        $group = $groups->find($id);
        if (null === $group || $group->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            return $this->json(['ok' => false, 'error' => 'Suite not found.'], 404);
        }
        if ($group->getFlows()->isEmpty()) {
            return $this->json(['ok' => false, 'error' => 'Suite has no flows.'], 422);
        }

        $envId = null;
        $body = json_decode($request->getContent() ?: '', true);
        $ref = \is_array($body) ? (string) ($body['environment'] ?? '') : '';
        if ('' !== $ref) {
            $env = \Symfony\Component\Uid\Uuid::isValid($ref) ? $environments->find($ref) : null;
            if (null === $env) {
                foreach ($environments->findByWorkspace($workspace) as $candidate) {
                    if ($candidate->getName() === $ref) {
                        $env = $candidate;
                        break;
                    }
                }
            }
            if (null === $env || $env->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
                return $this->json(['ok' => false, 'error' => 'Environment not found.'], 404);
            }
            $envId = (string) $env->getId();
        }

        $batchId = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
        $groupRun = new \App\Entity\FlowGroupRun();
        $groupRun->setFlowGroup($group);
        $groupRun->setBatchId($batchId);
        $groupRun->setTotal($group->getFlows()->count());
        $groupRun->setTrigger('api');
        $groupRuns->save($groupRun);

        $owner = $this->tokenOwner();
        $bus->dispatch(new \App\Message\RunFlowGroupMessage((string) $group->getId(), $batchId, $envId, null !== $owner ? (string) $owner->getId() : null));

        return $this->json([
            'ok' => true,
            'batchId' => $batchId,
            'statusUrl' => $this->generateUrl('api_suite_run_show', ['id' => $id, 'batchId' => $batchId], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL),
        ], 202);
    }

    /**
     * Batch status for CI polling. While running: {"status":"running", ...}.
     * Finished + ?format=junit: JUnit XML with HTTP 200 (passed) / 422 (failed).
     */
    #[Route('/suites/{id}/runs/{batchId}', name: 'api_suite_run_show', methods: ['GET'])]
    public function showSuiteRun(
        string $id,
        string $batchId,
        Request $request,
        \App\Repository\FlowGroupRepository $groups,
        \App\Repository\FlowGroupRunRepository $groupRuns,
        FlowRunRepository $runs,
        FlowRunReporter $reporter,
    ): Response {
        $workspace = $this->workspace($request);
        $group = $groups->find($id);
        if (null === $group || $group->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            return $this->json(['ok' => false, 'error' => 'Suite not found.'], 404);
        }
        $groupRun = $groupRuns->findOneByBatch($batchId);
        if (null === $groupRun || $groupRun->getFlowGroup()->getId()?->toRfc4122() !== $group->getId()?->toRfc4122()) {
            return $this->json(['ok' => false, 'error' => 'Batch not found.'], 404);
        }

        $flowRuns = $runs->findByBatch($batchId);
        $finished = \App\Entity\FlowGroupRun::STATUS_RUNNING !== $groupRun->getStatus();
        $passed = \App\Entity\FlowGroupRun::STATUS_PASSED === $groupRun->getStatus();

        if ('junit' === $request->query->get('format')) {
            if (!$finished) {
                return $this->json(['ok' => false, 'error' => 'Still running — poll without format=junit until it finishes.'], 409);
            }

            return new Response($reporter->toJUnitBatch($group->getName(), $flowRuns), $passed ? 200 : 422, ['Content-Type' => 'application/xml']);
        }

        $rows = array_map(static fn (\App\Entity\FlowRun $r) => [
            'flow' => $r->getFlow()->getName(),
            'status' => $r->getStatus(),
            'passedSteps' => $r->getPassedSteps(),
            'totalSteps' => $r->getTotalSteps(),
            'durationMs' => $r->getDurationMs(),
            'quarantined' => $r->getFlow()->isQuarantined(),
        ], $flowRuns);

        return $this->json([
            'ok' => !$finished || $passed,
            'status' => $groupRun->getStatus(),
            'done' => \count(array_filter($flowRuns, static fn ($r) => \App\Entity\FlowRun::STATUS_RUNNING !== $r->getStatus())),
            'total' => $groupRun->getTotal(),
            'flows' => $rows,
            'finishedAt' => $groupRun->getFinishedAt()?->format(\DATE_ATOM),
        ], !$finished ? 200 : ($passed ? 200 : 422));
    }

    private function workspace(Request $request): Workspace
    {
        $token = $request->attributes->get(ApiTokenAuthenticator::REQUEST_ATTR);
        if (!$token instanceof ApiToken) {
            throw $this->createAccessDeniedException();
        }

        return $token->getWorkspace();
    }

    private function resolveEnvironment(Request $request, Workspace $workspace, TestFlow $flow, EnvironmentRepository $environments, ?string $bodyRef = null): ?Environment
    {
        $ref = (string) ($bodyRef ?? $request->request->get('environment') ?? $request->query->get('environment') ?? '');
        if ('' === $ref) {
            return $flow->getDefaultEnvironment();
        }

        $env = \Symfony\Component\Uid\Uuid::isValid($ref) ? $environments->find($ref) : null;
        if (null === $env) {
            foreach ($environments->findByWorkspace($workspace) as $candidate) {
                if ($candidate->getName() === $ref) {
                    $env = $candidate;
                    break;
                }
            }
        }

        return ($env && $env->getWorkspace()->getId()?->toRfc4122() === $workspace->getId()?->toRfc4122())
            ? $env
            : $flow->getDefaultEnvironment();
    }
}
