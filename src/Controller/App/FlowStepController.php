<?php

namespace App\Controller\App;

use App\Entity\DbConnection;
use App\Entity\FlowStep;
use App\Entity\TestFlow;
use App\Entity\Workspace;
use App\Repository\ApiRequestRepository;
use App\Repository\DbConnectionRepository;
use App\Repository\FlowStepRepository;
use App\Service\FlowExpressionParser;
use App\Service\RequestRunner;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/app/workspaces/{workspace}/flows/{flow}/steps')]
#[IsGranted('ROLE_USER')]
class FlowStepController extends AbstractAppController
{
    public function __construct(
        private readonly \App\Repository\TestFlowRepository $flows,
        private readonly \App\Repository\DataFactoryRepository $dataFactories,
        private readonly \App\Service\DynamicVariableGenerator $dynamic,
        private readonly \App\Service\FlowVariableScanner $varScanner,
    ) {
    }

    #[Route('/new', name: 'app_flow_step_new', methods: ['GET'])]
    public function chooseNew(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        ApiRequestRepository $requests,
        DbConnectionRepository $connections,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertFlow($workspace, $flow);

        return $this->render('app/flow/step_new.html.twig', [
            'workspace' => $workspace,
            'flow' => $flow,
            'available_requests' => $requests->findByWorkspace($workspace),
            'db_connections' => $connections->findByWorkspace($workspace),
            'callable_flows' => $this->callableFlows($workspace, $flow),
        ]);
    }

    /**
     * These three "add" actions no longer persist. They build a transient step
     * from the picked request/connection/type and render the editor in "new"
     * mode. Nothing lands in the flow until the user saves (store()), so
     * cancelling leaves no orphan step.
     */
    #[Route('/add', name: 'app_flow_step_add', methods: ['POST'])]
    public function add(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        Request $httpRequest,
        ApiRequestRepository $requests,
        FlowExpressionParser $parser,
        DbConnectionRepository $connections,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertFlow($workspace, $flow);
        if (!$this->isCsrfTokenValid('add-step' . $flow->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $apiRequest = $requests->find((string) $httpRequest->request->get('request'));
        if (null === $apiRequest || $apiRequest->getCollection()->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            $this->addFlash('error', $this->translator->trans('Invalid request selection.'));

            return $this->redirectToRoute('app_flow_step_new', ['workspace' => $workspace->getId(), 'flow' => $flow->getId()]);
        }

        $step = new FlowStep();
        $step->setFlow($flow);
        $step->setApiRequest($apiRequest);
        $step->copyRequestFrom($apiRequest); // flow-owned copy; later edits don't touch the collection
        $step->setName($apiRequest->getName());

        return $this->renderEditor($workspace, $flow, $step, $parser, $connections, true, ['type' => 'http', 'reqId' => (string) $apiRequest->getId()]);
    }

    #[Route('/add-db', name: 'app_flow_step_add_db', methods: ['POST'])]
    public function addDb(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        Request $httpRequest,
        DbConnectionRepository $connections,
        FlowExpressionParser $parser,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertFlow($workspace, $flow);
        if (!$this->isCsrfTokenValid('add-step' . $flow->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $connection = $connections->find((string) $httpRequest->request->get('connection'));
        if (null === $connection || $connection->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            $this->addFlash('error', $this->translator->trans('Invalid connection selection.'));

            return $this->redirectToRoute('app_flow_step_new', ['workspace' => $workspace->getId(), 'flow' => $flow->getId()]);
        }

        $step = new FlowStep();
        $step->setFlow($flow);
        $step->setType(FlowStep::TYPE_DB);
        $step->setDbConnection($connection);
        $step->setName('DB: ' . $connection->getName());

        return $this->renderEditor($workspace, $flow, $step, $parser, $connections, true, ['type' => 'db']);
    }

    #[Route('/add-utility', name: 'app_flow_step_add_utility', methods: ['POST'])]
    public function addUtility(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        Request $httpRequest,
        FlowExpressionParser $parser,
        DbConnectionRepository $connections,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertFlow($workspace, $flow);
        if (!$this->isCsrfTokenValid('add-step' . $flow->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $type = (string) $httpRequest->request->get('type');
        if (!\in_array($type, [FlowStep::TYPE_SETVAR, FlowStep::TYPE_DELAY], true)) {
            $this->addFlash('error', $this->translator->trans('Invalid step type.'));

            return $this->redirectToRoute('app_flow_step_new', ['workspace' => $workspace->getId(), 'flow' => $flow->getId()]);
        }

        $step = new FlowStep();
        $step->setFlow($flow);
        $step->setType($type);
        if (FlowStep::TYPE_DELAY === $type) {
            $step->setName($this->translator->trans('Wait'));
            $step->setQuery('1000');
        } else {
            $step->setName($this->translator->trans('Set variable'));
            $step->setQuery('');
        }

        return $this->renderEditor($workspace, $flow, $step, $parser, $connections, true, ['type' => $type]);
    }

    #[Route('/add-call', name: 'app_flow_step_add_call', methods: ['POST'])]
    public function addCall(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        Request $httpRequest,
        FlowExpressionParser $parser,
        DbConnectionRepository $connections,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertFlow($workspace, $flow);
        if (!$this->isCsrfTokenValid('add-step' . $flow->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $called = $this->flows->find((string) $httpRequest->request->get('calledFlow'));
        if (null === $called
            || $called->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()
            || $called->getId()?->toRfc4122() === $flow->getId()?->toRfc4122()) {
            $this->addFlash('error', $this->translator->trans('Invalid sub-flow selection.'));

            return $this->redirectToRoute('app_flow_step_new', ['workspace' => $workspace->getId(), 'flow' => $flow->getId()]);
        }

        $step = new FlowStep();
        $step->setFlow($flow);
        $step->setType(FlowStep::TYPE_CALL);
        $step->setCalledFlow($called);
        $step->setName('↳ ' . $called->getName());

        return $this->renderEditor($workspace, $flow, $step, $parser, $connections, true, ['type' => 'call']);
    }

    /**
     * Commits a brand-new step to the flow — invoked only when the user saves
     * the "new step" editor. Rebuilds the step from the create context, appends
     * it at the end, and persists.
     */
    #[Route('/store', name: 'app_flow_step_store', methods: ['POST'])]
    public function store(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        Request $httpRequest,
        ApiRequestRepository $requests,
        FlowStepRepository $steps,
        FlowExpressionParser $parser,
        DbConnectionRepository $connections,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertFlow($workspace, $flow);
        if (!$this->isCsrfTokenValid('new-step' . $flow->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $type = (string) $httpRequest->request->get('stepType');
        $step = new FlowStep();
        $step->setFlow($flow);

        if ('http' === $type) {
            $apiRequest = $requests->find((string) $httpRequest->request->get('reqId'));
            if (null === $apiRequest || $apiRequest->getCollection()->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
                throw $this->createNotFoundException();
            }
            $step->setType(FlowStep::TYPE_HTTP);
            $step->setApiRequest($apiRequest);
            $step->copyRequestFrom($apiRequest);
        } elseif (\in_array($type, [FlowStep::TYPE_DB, FlowStep::TYPE_SETVAR, FlowStep::TYPE_DELAY, FlowStep::TYPE_CALL], true)) {
            $step->setType($type);
        } else {
            throw $this->createNotFoundException();
        }

        $maxPos = -1;
        foreach ($flow->getSteps() as $existing) {
            $maxPos = max($maxPos, $existing->getPosition());
        }
        $step->setPosition($maxPos + 1);

        $this->hydrateStep($step, $httpRequest, $workspace, $connections, $parser);
        $steps->save($step);
        $this->addFlash('success', $this->translator->trans('Step added.'));

        return $this->redirectToRoute('app_flow_show', ['workspace' => $workspace->getId(), 'flow' => $flow->getId()]);
    }

    #[Route('/{step}/edit', name: 'app_flow_step_edit', methods: ['GET', 'POST'])]
    public function edit(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        #[MapEntity(mapping: ['step' => 'id'])] FlowStep $step,
        Request $httpRequest,
        FlowStepRepository $steps,
        FlowExpressionParser $parser,
        DbConnectionRepository $connections,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertFlow($workspace, $flow);
        $this->assertStep($flow, $step);

        if ($httpRequest->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit-step' . $step->getId(), (string) $httpRequest->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $this->hydrateStep($step, $httpRequest, $workspace, $connections, $parser);
            $steps->save($step);
            $this->addFlash('success', $this->translator->trans('Step saved.'));

            return $this->redirectToRoute('app_flow_show', ['workspace' => $workspace->getId(), 'flow' => $flow->getId()]);
        }

        return $this->renderEditor($workspace, $flow, $step, $parser, $connections, false);
    }

    /**
     * Reads the editor form into a step. Shared by edit (update) and store (create).
     */
    private function hydrateStep(FlowStep $step, Request $r, Workspace $workspace, DbConnectionRepository $connections, FlowExpressionParser $parser): void
    {
        $step->setName(trim((string) $r->request->get('name')) ?: $step->getName());

        // Run-if condition (all step types).
        $condLeft = trim((string) $r->request->get('cond_left'));
        if ('' === $condLeft) {
            $step->setCondition(null);
        } else {
            $op = (string) $r->request->get('cond_op', 'eq');
            $allowed = ['eq', 'ne', 'contains', 'matches', 'gt', 'lt', 'ge', 'le', 'exists', 'empty', 'notEmpty'];
            $step->setCondition([
                'left' => $condLeft,
                'op' => \in_array($op, $allowed, true) ? $op : 'eq',
                'right' => (string) $r->request->get('cond_right', ''),
            ]);
        }

        // forEach loop (all step types).
        $loopOver = trim((string) $r->request->get('loop_over'));
        if ('' === $loopOver) {
            $step->setLoop(null);
        } else {
            $step->setLoop([
                'over' => $loopOver,
                'as' => trim((string) $r->request->get('loop_as')) ?: 'item',
            ]);
        }

        if ($step->isCall()) {
            // A call step delegates everything to the referenced flow; only the target matters.
            $calledId = (string) $r->request->get('calledFlow');
            if ('' !== $calledId) {
                $called = $this->flows->find($calledId);
                $step->setCalledFlow($called && $called->getWorkspace()->getId()?->toRfc4122() === $workspace->getId()?->toRfc4122() ? $called : null);
            } else {
                $step->setCalledFlow(null);
            }

            return;
        }

        $step->setExtractions($parser->parseExtractions((string) $r->request->get('extractions')));
        $assertions = $parser->parseAssertions((string) $r->request->get('assertions'));
        // JSON-schema assertion can't ride the text DSL — carried in its own field.
        $schema = trim((string) $r->request->get('schema'));
        if ('' !== $schema && \is_array(json_decode($schema, true))) {
            $assertions[] = ['kind' => 'schema', 'schema' => $schema];
        }
        $step->setAssertions($assertions);

        $step->setRetryEnabled((bool) $r->request->get('retryEnabled'));
        $step->setRetryMax(max(1, min(20, (int) $r->request->get('retryMax', 5))));
        $step->setRetryDelayMs(max(0, min(10000, (int) $r->request->get('retryDelayMs', 1000))));

        if ($step->isDb()) {
            $step->setQuery($this->nullable((string) $r->request->get('query')));
            $connId = (string) $r->request->get('connection');
            if ('' !== $connId) {
                $conn = $connections->find($connId);
                if ($conn && $conn->getWorkspace()->getId()?->toRfc4122() === $workspace->getId()?->toRfc4122()) {
                    $step->setDbConnection($conn);
                }
            }
        } elseif ($step->isSetvar() || $step->isDelay()) {
            $step->setQuery($this->nullable((string) $r->request->get('query')));
        } else {
            // HTTP: this step's own flow-owned request copy.
            $step->setReqMethod((string) $r->request->get('method', $step->getReqMethod()));
            $step->setReqUrl((string) $r->request->get('url', ''));
            $step->setReqParams($this->kvFromArrays($r->request->all('param_name'), $r->request->all('param_value')));
            $step->setReqHeaders($this->kvFromArrays($r->request->all('header_name'), $r->request->all('header_value')));
            $mode = (string) $r->request->get('bodyMode', 'none');
            $step->setReqBodyMode(\in_array($mode, ['none', 'raw', 'json', 'form'], true) ? $mode : 'none');
            $step->setReqBody($this->nullable((string) $r->request->get('body')));
            $step->setReqAuth($this->authFromRequest($r));
        }
    }

    /**
     * @param array<string, string> $createCtx type + reqId when is_new
     */
    private function renderEditor(Workspace $workspace, TestFlow $flow, FlowStep $step, FlowExpressionParser $parser, DbConnectionRepository $connections, bool $isNew, array $createCtx = []): Response
    {
        return $this->render('app/flow/step_edit.html.twig', [
            'workspace' => $workspace,
            'flow' => $flow,
            'step' => $step,
            'extractions_text' => $parser->renderExtractions($step->getExtractions()),
            'assertions_text' => $parser->renderAssertions($step->getAssertions()),
            'connections' => $connections->findByWorkspace($workspace),
            'flows' => $this->callableFlows($workspace, $flow),
            'vc_catalog' => $this->vcCatalog($workspace, $flow),
            'is_new' => $isNew,
            'create_ctx' => $createCtx,
        ]);
    }

    /**
     * Autocomplete catalog for {{...}} fields: generators (built-ins + factories)
     * and the flow's variables.
     *
     * @return array<int, array{token: string, label: string, desc: string, group: string}>
     */
    private function vcCatalog(Workspace $workspace, TestFlow $flow): array
    {
        $out = [];
        foreach ($this->varScanner->externalVariables($flow) as $v) {
            $out[] = ['token' => '{{' . $v['name'] . '}}', 'label' => $v['name'], 'desc' => $v['fromEnv'] ? $this->translator->trans('env variable') : $this->translator->trans('variable'), 'group' => 'var'];
        }
        foreach ($this->dataFactories->findByWorkspace($workspace) as $f) {
            $out[] = ['token' => '{{$' . $f->getName() . '}}', 'label' => $f->getName(), 'desc' => $this->translator->trans('factory') . ' · ' . $f->getKind(), 'group' => 'gen'];
        }
        foreach ($this->dynamic->builtins() as $b) {
            $out[] = ['token' => $b['token'], 'label' => $b['name'], 'desc' => $b['description'], 'group' => 'gen'];
        }

        return $out;
    }

    /**
     * Flows in the workspace this flow may call — all except itself (deeper
     * cycles are caught at run time by the engine's call-stack guard).
     *
     * @return TestFlow[]
     */
    private function callableFlows(Workspace $workspace, TestFlow $flow): array
    {
        return array_values(array_filter(
            $this->flows->findByWorkspace($workspace),
            static fn (TestFlow $f): bool => $f->getId()?->toRfc4122() !== $flow->getId()?->toRfc4122(),
        ));
    }

    /**
     * Runs this step live and returns the real response as JSON, so the rule
     * builder can render it as a clickable tree — the tester picks a field
     * instead of typing a path. Uses the flow's default environment plus the
     * variables extracted by the most recent run (so mid-flow steps that need
     * a token from an earlier step still get a usable response).
     */
    #[Route('/{step}/probe', name: 'app_flow_step_probe', methods: ['POST'])]
    public function probe(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        #[MapEntity(mapping: ['step' => 'id'])] FlowStep $step,
        Request $httpRequest,
        RequestRunner $runner,
        \App\Service\Db\DbQueryRunner $dbQuery,
        \App\Repository\FlowRunRepository $runs,
        \App\Service\JsonSchema $jsonSchema,
    ): JsonResponse {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertFlow($workspace, $flow);
        $this->assertStep($flow, $step);
        if (!$this->isCsrfTokenValid('edit-step' . $step->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        // Variable context: default env + latest run's extracted vars.
        $vars = null !== $flow->getDefaultEnvironment() ? $flow->getDefaultEnvironment()->toMap() : [];
        $recent = $runs->recentForFlow($flow, 1);
        if (isset($recent[0])) {
            foreach ($recent[0]->getStepResults() as $sr) {
                foreach ($sr->getExtractedVars() as $k => $v) {
                    $vars[(string) $k] = \is_scalar($v) ? (string) $v : (string) json_encode($v);
                }
            }
        }

        if ($step->isDb()) {
            if (null === $step->getDbConnection()) {
                return new JsonResponse(['ok' => false, 'error' => $this->translator->trans('No connection selected.')]);
            }
            $r = $dbQuery->run($step->getDbConnection(), $step->getQuery(), $vars);

            return new JsonResponse(['ok' => $r->ok, 'kind' => 'db', 'json' => $r->ok ? $r->data : null,
                'inferredSchema' => $r->ok && \is_array($r->data) ? $jsonSchema->infer($r->data) : null, 'error' => $r->error]);
        }

        $result = $runner->send($step->toTransientRequest(), $vars, $workspace, $this->currentUser());
        $parsed = null;
        if (null !== $result->body && '' !== $result->body) {
            $decoded = json_decode($result->body, true);
            $parsed = \JSON_ERROR_NONE === json_last_error() ? $decoded : null;
        }

        return new JsonResponse([
            'ok' => $result->ok, 'kind' => 'http', 'status' => $result->statusCode,
            'json' => $parsed, 'rawBody' => null === $result->body ? null : mb_substr($result->body, 0, 20000),
            'inferredSchema' => \is_array($parsed) ? $jsonSchema->infer($parsed) : null,
            'error' => $result->error,
        ]);
    }

    #[Route('/{step}/reset-baseline', name: 'app_flow_step_reset_baseline', methods: ['POST'])]
    public function resetBaseline(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        #[MapEntity(mapping: ['step' => 'id'])] FlowStep $step,
        Request $httpRequest,
        FlowStepRepository $steps,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertFlow($workspace, $flow);
        $this->assertStep($flow, $step);
        if (!$this->isCsrfTokenValid('reset-baseline' . $step->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $step->setResponseShape(null);
        $step->setContractBaselineAt(null);
        $steps->save($step);
        $this->addFlash('success', $this->translator->trans('Contract baseline reset; it will be captured again on the next successful run.'));

        return $this->redirectToRoute('app_flow_step_edit', ['workspace' => $workspace->getId(), 'flow' => $flow->getId(), 'step' => $step->getId()]);
    }

    #[Route('/{step}/move', name: 'app_flow_step_move', methods: ['POST'])]
    public function move(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        #[MapEntity(mapping: ['step' => 'id'])] FlowStep $step,
        Request $httpRequest,
        FlowStepRepository $steps,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertFlow($workspace, $flow);
        $this->assertStep($flow, $step);

        if (!$this->isCsrfTokenValid('move-step' . $step->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $direction = (string) $httpRequest->request->get('direction');
        $ordered = $flow->getSteps()->toArray();
        usort($ordered, static fn (FlowStep $a, FlowStep $b) => $a->getPosition() <=> $b->getPosition());

        $index = array_search($step, $ordered, true);
        $swapWith = 'up' === $direction ? $index - 1 : $index + 1;

        if (false !== $index && isset($ordered[$swapWith])) {
            $other = $ordered[$swapWith];
            $p = $step->getPosition();
            $step->setPosition($other->getPosition());
            $other->setPosition($p);
            $steps->save($step, false);
            $steps->save($other);
        }

        return $this->redirectToRoute('app_flow_show', ['workspace' => $workspace->getId(), 'flow' => $flow->getId()]);
    }

    #[Route('/{step}/delete', name: 'app_flow_step_delete', methods: ['POST'])]
    public function delete(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        #[MapEntity(mapping: ['step' => 'id'])] FlowStep $step,
        Request $httpRequest,
        FlowStepRepository $steps,
    ): Response {
        $this->assertWorkspace($workspace, 'edit');
        $this->assertFlow($workspace, $flow);
        $this->assertStep($flow, $step);

        if (!$this->isCsrfTokenValid('delete' . $step->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $steps->remove($step);
        $this->addFlash('success', $this->translator->trans('Step deleted.'));

        return $this->redirectToRoute('app_flow_show', ['workspace' => $workspace->getId(), 'flow' => $flow->getId()]);
    }

    private function assertFlow(Workspace $workspace, TestFlow $flow): void
    {
        if ($flow->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }
    }

    private function assertStep(TestFlow $flow, FlowStep $step): void
    {
        if ($step->getFlow()->getId()?->toRfc4122() !== $flow->getId()?->toRfc4122()) {
            throw $this->createNotFoundException();
        }
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);

        return '' === $value ? null : $value;
    }

    /**
     * @param array<int, mixed> $names
     * @param array<int, mixed> $values
     *
     * @return array<int, array{name: string, value: string}>
     */
    private function kvFromArrays(array $names, array $values): array
    {
        $out = [];
        foreach ($names as $i => $name) {
            $name = trim((string) $name);
            if ('' === $name) {
                continue;
            }
            $out[] = ['name' => $name, 'value' => (string) ($values[$i] ?? '')];
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function authFromRequest(Request $r): array
    {
        $type = (string) $r->request->get('authType', 'none');
        return match ($type) {
            'bearer' => ['type' => 'bearer', 'token' => (string) $r->request->get('authToken', '')],
            'basic' => ['type' => 'basic', 'username' => (string) $r->request->get('authUser', ''), 'password' => (string) $r->request->get('authPass', '')],
            'apikey' => ['type' => 'apikey', 'key' => (string) $r->request->get('authKey', ''), 'value' => (string) $r->request->get('authVal', ''), 'addTo' => (string) $r->request->get('authAddTo', 'header')],
            default => ['type' => 'none'],
        };
    }
}
