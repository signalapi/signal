<?php

namespace App\Controller\App;

use App\Entity\ApiRequest;
use App\Entity\DbConnection;
use App\Entity\FlowStep;
use App\Entity\TestFlow;
use App\Entity\Workspace;
use App\Repository\ApiRequestRepository;
use App\Repository\DbConnectionRepository;
use App\Repository\EnvironmentRepository;
use App\Repository\FlowStepRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The visual node-canvas flow builder: place request/db/utility steps as nodes,
 * connect them, and the execution order (FlowStep.position) is derived from the
 * connections on save. JSON endpoints (CSRF via header) power the canvas.
 */
#[Route('/app/workspaces/{workspace}/flows/{flow}/builder')]
#[IsGranted('ROLE_MERCHANT')]
class FlowBuilderController extends AbstractAppController
{
    #[Route('', name: 'app_flow_builder', methods: ['GET'])]
    public function index(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        ApiRequestRepository $requests,
        DbConnectionRepository $connections,
        EnvironmentRepository $environments,
    ): Response {
        $this->assertWorkspace($workspace);
        $this->assertFlow($workspace, $flow);

        $palette = [];
        foreach ($requests->findByWorkspace($workspace) as $r) {
            $palette[] = [
                'id' => (string) $r->getId(),
                'name' => $r->getName(),
                'method' => $r->getMethod(),
                'collection' => $r->getCollection()->getName(),
            ];
        }
        $dbs = [];
        foreach ($connections->findByWorkspace($workspace) as $c) {
            $dbs[] = ['id' => (string) $c->getId(), 'name' => $c->getName(), 'type' => $c->getType()];
        }

        return $this->render('app/flow/builder.html.twig', [
            'workspace' => $workspace,
            'flow' => $flow,
            'nodes' => array_map($this->nodeData(...), $flow->getSteps()->toArray()),
            'edges' => $flow->getCanvasEdges(),
            'palette' => $palette,
            'dbConnections' => $dbs,
            'environments' => $environments->findByWorkspace($workspace),
        ]);
    }

    #[Route('/nodes', name: 'app_flow_builder_add', methods: ['POST'])]
    public function addNode(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        Request $httpRequest,
        ApiRequestRepository $requests,
        DbConnectionRepository $connections,
        FlowStepRepository $steps,
    ): JsonResponse {
        $this->assertWorkspace($workspace);
        $this->assertFlow($workspace, $flow);
        $this->assertCsrf($httpRequest, $flow);

        $body = $this->jsonBody($httpRequest);
        $kind = (string) ($body['kind'] ?? '');
        $x = (int) ($body['x'] ?? 60);
        $y = (int) ($body['y'] ?? 60);

        $step = new FlowStep();
        $step->setFlow($flow);
        $step->setCanvasX($x);
        $step->setCanvasY($y);
        $step->setPosition($this->nextPosition($flow));

        switch ($kind) {
            case 'http':
                $req = $requests->find((string) ($body['requestId'] ?? ''));
                if (!$req instanceof ApiRequest || $req->getCollection()->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
                    return new JsonResponse(['error' => 'İstek bulunamadı.'], 404);
                }
                $step->setType(FlowStep::TYPE_HTTP);
                $step->setApiRequest($req);
                $step->copyRequestFrom($req); // flow-owned copy; independent of the collection
                $step->setName($req->getName());
                break;
            case 'db':
                $conn = $connections->find((string) ($body['connectionId'] ?? ''));
                if (!$conn instanceof DbConnection || $conn->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
                    return new JsonResponse(['error' => 'DB bağlantısı bulunamadı.'], 404);
                }
                $step->setType(FlowStep::TYPE_DB);
                $step->setDbConnection($conn);
                $step->setName('DB: ' . $conn->getName());
                break;
            case 'setvar':
                $step->setType(FlowStep::TYPE_SETVAR);
                $step->setName('Değişken set');
                break;
            case 'delay':
                $step->setType(FlowStep::TYPE_DELAY);
                $step->setName('Bekle');
                $step->setQuery('500');
                break;
            default:
                return new JsonResponse(['error' => 'Geçersiz node tipi.'], 400);
        }

        $steps->save($step);

        return new JsonResponse(['ok' => true, 'node' => $this->nodeData($step)]);
    }

    #[Route('/layout', name: 'app_flow_builder_layout', methods: ['POST'])]
    public function saveLayout(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        Request $httpRequest,
        \App\Repository\TestFlowRepository $flows,
        EntityManagerInterface $em,
    ): JsonResponse {
        $this->assertWorkspace($workspace);
        $this->assertFlow($workspace, $flow);
        $this->assertCsrf($httpRequest, $flow);

        $body = $this->jsonBody($httpRequest);

        // index this flow's steps by id
        $byId = [];
        foreach ($flow->getSteps() as $s) {
            $byId[(string) $s->getId()] = $s;
        }

        // positions
        foreach ((array) ($body['positions'] ?? []) as $id => $xy) {
            $s = $byId[(string) $id] ?? null;
            if ($s && \is_array($xy)) {
                $s->setCanvasX((int) ($xy['x'] ?? $xy[0] ?? 0));
                $s->setCanvasY((int) ($xy['y'] ?? $xy[1] ?? 0));
            }
        }

        // edges: keep only those whose endpoints both exist in this flow
        $edges = [];
        foreach ((array) ($body['edges'] ?? []) as $e) {
            $from = (string) ($e['from'] ?? $e[0] ?? '');
            $to = (string) ($e['to'] ?? $e[1] ?? '');
            if (isset($byId[$from], $byId[$to]) && $from !== $to) {
                $edges[] = [$from, $to];
            }
        }
        $flow->setCanvasEdges($edges);

        $this->applyChainOrder($flow, $byId, $edges);
        $em->flush();

        $order = [];
        foreach ($byId as $id => $s) {
            $order[$id] = $s->getPosition();
        }

        return new JsonResponse(['ok' => true, 'order' => $order]);
    }

    #[Route('/nodes/{step}/delete', name: 'app_flow_builder_delete', methods: ['POST'])]
    public function deleteNode(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        #[MapEntity(mapping: ['step' => 'id'])] FlowStep $step,
        Request $httpRequest,
        FlowStepRepository $steps,
        \App\Repository\TestFlowRepository $flows,
    ): JsonResponse {
        $this->assertWorkspace($workspace);
        $this->assertFlow($workspace, $flow);
        if ($step->getFlow()->getId()?->toRfc4122() !== $flow->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }
        $this->assertCsrf($httpRequest, $flow);

        $stepId = (string) $step->getId();
        // drop edges touching this node
        $edges = array_values(array_filter(
            $flow->getCanvasEdges(),
            static fn (array $e): bool => $e[0] !== $stepId && $e[1] !== $stepId,
        ));
        $flow->setCanvasEdges($edges);
        $flows->save($flow, false);
        $steps->remove($step);

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/nodes/{step}/mapping', name: 'app_flow_builder_mapping', methods: ['GET'])]
    public function mapping(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        #[MapEntity(mapping: ['step' => 'id'])] FlowStep $step,
        \App\Repository\ResponseExampleRepository $examples,
    ): JsonResponse {
        $this->assertWorkspace($workspace);
        $this->assertFlow($workspace, $flow);
        if ($step->getFlow()->getId()?->toRfc4122() !== $flow->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }

        // Input fields come from the step's own flow-owned request copy.
        $request = null;
        if (!$step->isDb() && !$step->isSetvar() && !$step->isDelay()) {
            $request = [
                'method' => $step->getReqMethod(),
                'url' => $step->getReqUrl(),
                'params' => $step->getReqParams(),
                'headers' => $step->getReqHeaders(),
                'bodyMode' => $step->getReqBodyMode(),
                'body' => $step->getReqBody(),
            ];
        }
        // Example responses still come from the origin request (they document the endpoint).
        $origin = $step->getApiRequest();
        $exampleList = [];
        if (null !== $origin) {
            foreach ($examples->findForRequest($origin) as $e) {
                $exampleList[] = [
                    'id' => (string) $e->getId(),
                    'name' => $e->getName(),
                    'status' => $e->getStatusCode(),
                    'success' => $e->isSuccess(),
                ];
            }
        }

        return new JsonResponse([
            'step' => ['id' => (string) $step->getId(), 'type' => $step->getType(), 'name' => $step->getName(), 'isDb' => $step->isDb()],
            'request' => $request,
            'dbQuery' => $step->isDb() || $step->isSetvar() ? $step->getQuery() : null,
            'examples' => $exampleList,
            'extractions' => $step->getExtractions(),
            'available' => $this->upstreamVars($flow, $step),
            'dynamicVars' => ['$randomUUID', '$randomEmail', '$randomInt', '$randomFullName', '$randomUserName', '$timestamp', '$randomPhoneNumber', '$randomCompanyName', '$randomPrice', '$randomBoolean'],
        ]);
    }

    #[Route('/nodes/{step}/extractions', name: 'app_flow_builder_extractions', methods: ['POST'])]
    public function saveExtractions(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        #[MapEntity(mapping: ['step' => 'id'])] FlowStep $step,
        Request $httpRequest,
        FlowStepRepository $steps,
    ): JsonResponse {
        $this->assertWorkspace($workspace);
        $this->assertFlow($workspace, $flow);
        if ($step->getFlow()->getId()?->toRfc4122() !== $flow->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }
        $this->assertCsrf($httpRequest, $flow);

        $out = [];
        foreach ((array) ($this->jsonBody($httpRequest)['extractions'] ?? []) as $e) {
            if (!\is_array($e)) {
                continue;
            }
            $var = trim((string) ($e['var'] ?? ''));
            $path = trim((string) ($e['path'] ?? ''));
            if ('' !== $var && '' !== $path) {
                $out[] = ['var' => $var, 'path' => $path];
            }
        }
        $step->setExtractions($out);
        $steps->save($step);

        return new JsonResponse(['ok' => true, 'extractions' => $out, 'available' => $this->upstreamVars($flow, $step)]);
    }

    #[Route('/nodes/{step}/request', name: 'app_flow_builder_request', methods: ['POST'])]
    public function saveRequest(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        #[MapEntity(mapping: ['step' => 'id'])] FlowStep $step,
        Request $httpRequest,
        ApiRequestRepository $requests,
        FlowStepRepository $steps,
    ): JsonResponse {
        $this->assertWorkspace($workspace);
        $this->assertFlow($workspace, $flow);
        if ($step->getFlow()->getId()?->toRfc4122() !== $flow->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }
        $this->assertCsrf($httpRequest, $flow);

        $body = $this->jsonBody($httpRequest);

        // DB/setvar steps store their template in the step's query.
        if ($step->isDb() || $step->isSetvar()) {
            $step->setQuery((string) ($body['query'] ?? $step->getQuery()));
            $steps->save($step);

            return new JsonResponse(['ok' => true]);
        }

        // Write to the step's own flow-owned request copy (never the shared collection request).
        $step->setReqUrl((string) ($body['url'] ?? $step->getReqUrl()));
        $step->setReqParams($this->pairs($body['params'] ?? []));
        $step->setReqHeaders($this->pairs($body['headers'] ?? []));
        if (isset($body['method'])) {
            $step->setReqMethod((string) $body['method']);
        }
        $mode = (string) ($body['bodyMode'] ?? $step->getReqBodyMode());
        $step->setReqBodyMode(\in_array($mode, ['none', 'raw', 'json', 'form'], true) ? $mode : 'none');
        $b = (string) ($body['body'] ?? '');
        $step->setReqBody('' === $b ? null : $b);
        $steps->save($step);

        return new JsonResponse(['ok' => true]);
    }

    /**
     * Variables produced by ancestor nodes (their extractions), reachable via edges.
     *
     * @return array<int, array{var: string, from: string}>
     */
    private function upstreamVars(TestFlow $flow, FlowStep $target): array
    {
        $byId = [];
        foreach ($flow->getSteps() as $s) {
            $byId[(string) $s->getId()] = $s;
        }
        // reverse adjacency
        $incoming = [];
        foreach ($flow->getCanvasEdges() as [$from, $to]) {
            $incoming[$to][] = $from;
        }
        // BFS backwards from target
        $ancestors = [];
        $queue = [(string) $target->getId()];
        $seen = [];
        while ([] !== $queue) {
            $cur = array_shift($queue);
            foreach ($incoming[$cur] ?? [] as $prev) {
                if (!isset($seen[$prev])) {
                    $seen[$prev] = true;
                    $ancestors[] = $prev;
                    $queue[] = $prev;
                }
            }
        }

        $out = [];
        foreach ($ancestors as $aid) {
            $s = $byId[$aid] ?? null;
            if (null === $s) {
                continue;
            }
            foreach ($s->getExtractions() as $ex) {
                if (isset($ex['var']) && '' !== $ex['var']) {
                    $out[] = ['var' => (string) $ex['var'], 'from' => $s->getName()];
                }
            }
        }

        return $out;
    }

    /**
     * @param mixed $input
     *
     * @return array<int, array{name: string, value: string}>
     */
    private function pairs(mixed $input): array
    {
        $out = [];
        if (\is_array($input)) {
            foreach ($input as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $name = trim((string) ($row['name'] ?? ''));
                if ('' === $name) {
                    continue;
                }
                $out[] = ['name' => $name, 'value' => (string) ($row['value'] ?? '')];
            }
        }

        return $out;
    }

    /**
     * Derives FlowStep.position from the connection chain: start nodes (no incoming
     * edge) first, ordered by canvas Y; each chain walked along its outgoing edge.
     * Unconnected/leftover nodes are appended by canvas Y.
     *
     * @param array<string, FlowStep>            $byId
     * @param array<int, array{0: string, 1: string}> $edges
     */
    private function applyChainOrder(TestFlow $flow, array $byId, array $edges): void
    {
        $outgoing = [];
        $hasIncoming = [];
        foreach ($edges as [$from, $to]) {
            $outgoing[$from] = $to; // single out-edge per node (linear)
            $hasIncoming[$to] = true;
        }

        // start candidates: nodes without an incoming edge, ordered by Y then X
        $starts = [];
        foreach ($byId as $id => $s) {
            if (!isset($hasIncoming[$id])) {
                $starts[] = $id;
            }
        }
        usort($starts, fn (string $a, string $b): int => [$byId[$a]->getCanvasY(), $byId[$a]->getCanvasX()] <=> [$byId[$b]->getCanvasY(), $byId[$b]->getCanvasX()]);

        $pos = 0;
        $visited = [];
        foreach ($starts as $start) {
            $cur = $start;
            while (null !== $cur && isset($byId[$cur]) && !isset($visited[$cur])) {
                $visited[$cur] = true;
                $byId[$cur]->setPosition($pos++);
                $cur = $outgoing[$cur] ?? null;
            }
        }
        // any leftovers (cycles / detached), ordered by Y
        $rest = array_filter(array_keys($byId), static fn (string $id): bool => !isset($visited[$id]));
        usort($rest, fn (string $a, string $b): int => $byId[$a]->getCanvasY() <=> $byId[$b]->getCanvasY());
        foreach ($rest as $id) {
            $byId[$id]->setPosition($pos++);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function nodeData(FlowStep $s): array
    {
        $detail = '';
        $method = null;
        if ($s->isDb()) {
            $detail = ($s->getDbConnection()?->getType() ?? 'db') . ' · ' . mb_substr((string) $s->getQuery(), 0, 40);
        } elseif ($s->isSetvar()) {
            $detail = mb_substr((string) $s->getQuery(), 0, 40);
        } elseif ($s->isDelay()) {
            $detail = ($s->getQuery() ?: '0') . ' ms';
        } else {
            $method = $s->getReqMethod();
            $detail = $s->getReqMethod() . ' ' . $s->getReqUrl();
        }

        // "Full edit" always opens this step's own editor (flow-owned request),
        // never the shared collection request.
        $editUrl = $this->generateUrl('app_flow_step_edit', [
            'workspace' => $s->getFlow()->getWorkspace()->getId(),
            'flow' => $s->getFlow()->getId(),
            'step' => $s->getId(),
        ]);

        return [
            'id' => (string) $s->getId(),
            'type' => $s->getType(),
            'name' => $s->getName(),
            'method' => $method,
            'detail' => $detail,
            'x' => $s->getCanvasX(),
            'y' => $s->getCanvasY(),
            'position' => $s->getPosition(),
            'assertions' => \count($s->getAssertions()),
            'extractions' => \count($s->getExtractions()),
            'editUrl' => $editUrl,
        ];
    }

    private function nextPosition(TestFlow $flow): int
    {
        $max = -1;
        foreach ($flow->getSteps() as $s) {
            $max = max($max, $s->getPosition());
        }

        return $max + 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(Request $request): array
    {
        $data = json_decode((string) $request->getContent(), true);

        return \is_array($data) ? $data : [];
    }

    private function assertCsrf(Request $request, TestFlow $flow): void
    {
        if (!$this->isCsrfTokenValid('flow-builder' . $flow->getId(), (string) $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('CSRF');
        }
    }

    private function assertFlow(Workspace $workspace, TestFlow $flow): void
    {
        if ($flow->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }
    }
}
