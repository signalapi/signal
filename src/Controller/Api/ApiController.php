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
    #[Route('/flows', name: 'api_flow_list', methods: ['GET'])]
    public function listFlows(Request $request, TestFlowRepository $flows): JsonResponse
    {
        $workspace = $this->workspace($request);

        $data = array_map(static fn (TestFlow $f) => [
            'id' => (string) $f->getId(),
            'name' => $f->getName(),
            'steps' => $f->getSteps()->count(),
            'scheduleEnabled' => $f->isScheduleEnabled(),
            'cron' => $f->getCronExpression(),
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
            $runs = $runner->runDataset($flow, $environment, $dataset, 'api');
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

        $run = $runner->run($flow, $environment, 'api', $vars);

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
