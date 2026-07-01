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

#[Route('/app/workspaces/{workspace}/flows/{flow}/steps')]
#[IsGranted('ROLE_MERCHANT')]
class FlowStepController extends AbstractAppController
{
    #[Route('/add', name: 'app_flow_step_add', methods: ['POST'])]
    public function add(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        Request $httpRequest,
        ApiRequestRepository $requests,
        FlowStepRepository $steps,
    ): Response {
        $this->assertWorkspace($workspace);
        $this->assertFlow($workspace, $flow);

        if (!$this->isCsrfTokenValid('add-step' . $flow->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $apiRequest = $requests->find((string) $httpRequest->request->get('request'));
        if (null === $apiRequest || $apiRequest->getCollection()->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            $this->addFlash('error', 'Geçersiz istek seçimi.');

            return $this->redirectToRoute('app_flow_show', ['workspace' => $workspace->getId(), 'flow' => $flow->getId()]);
        }

        $maxPos = -1;
        foreach ($flow->getSteps() as $existing) {
            $maxPos = max($maxPos, $existing->getPosition());
        }

        $step = new FlowStep();
        $step->setFlow($flow);
        $step->setApiRequest($apiRequest);
        $step->copyRequestFrom($apiRequest); // flow-owned copy; later edits don't touch the collection
        $step->setName($apiRequest->getName());
        $step->setPosition($maxPos + 1);
        $steps->save($step);

        $this->addFlash('success', 'Adım eklendi.');

        return $this->redirectToRoute('app_flow_step_edit', [
            'workspace' => $workspace->getId(),
            'flow' => $flow->getId(),
            'step' => $step->getId(),
        ]);
    }

    #[Route('/add-db', name: 'app_flow_step_add_db', methods: ['POST'])]
    public function addDb(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        Request $httpRequest,
        DbConnectionRepository $connections,
        FlowStepRepository $steps,
    ): Response {
        $this->assertWorkspace($workspace);
        $this->assertFlow($workspace, $flow);

        if (!$this->isCsrfTokenValid('add-step' . $flow->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $connection = $connections->find((string) $httpRequest->request->get('connection'));
        if (null === $connection || $connection->getWorkspace()->getId()?->toRfc4122() !== $workspace->getId()?->toRfc4122()) {
            $this->addFlash('error', 'Geçersiz bağlantı seçimi.');

            return $this->redirectToRoute('app_flow_show', ['workspace' => $workspace->getId(), 'flow' => $flow->getId()]);
        }

        $maxPos = -1;
        foreach ($flow->getSteps() as $existing) {
            $maxPos = max($maxPos, $existing->getPosition());
        }

        $step = new FlowStep();
        $step->setFlow($flow);
        $step->setType(FlowStep::TYPE_DB);
        $step->setDbConnection($connection);
        $step->setName('DB: ' . $connection->getName());
        $step->setPosition($maxPos + 1);
        $steps->save($step);

        $this->addFlash('success', 'Veritabanı adımı eklendi.');

        return $this->redirectToRoute('app_flow_step_edit', [
            'workspace' => $workspace->getId(),
            'flow' => $flow->getId(),
            'step' => $step->getId(),
        ]);
    }

    #[Route('/add-utility', name: 'app_flow_step_add_utility', methods: ['POST'])]
    public function addUtility(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        Request $httpRequest,
        FlowStepRepository $steps,
    ): Response {
        $this->assertWorkspace($workspace);
        $this->assertFlow($workspace, $flow);

        if (!$this->isCsrfTokenValid('add-step' . $flow->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $type = (string) $httpRequest->request->get('type');
        if (!\in_array($type, [FlowStep::TYPE_SETVAR, FlowStep::TYPE_DELAY], true)) {
            $this->addFlash('error', 'Geçersiz adım türü.');

            return $this->redirectToRoute('app_flow_show', ['workspace' => $workspace->getId(), 'flow' => $flow->getId()]);
        }

        $maxPos = -1;
        foreach ($flow->getSteps() as $existing) {
            $maxPos = max($maxPos, $existing->getPosition());
        }

        $step = new FlowStep();
        $step->setFlow($flow);
        $step->setType($type);
        $step->setPosition($maxPos + 1);
        if (FlowStep::TYPE_DELAY === $type) {
            $step->setName('Bekle');
            $step->setQuery('1000');
        } else {
            $step->setName('Değişken set et');
            $step->setQuery('');
        }
        $steps->save($step);

        return $this->redirectToRoute('app_flow_step_edit', [
            'workspace' => $workspace->getId(),
            'flow' => $flow->getId(),
            'step' => $step->getId(),
        ]);
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
        $this->assertWorkspace($workspace);
        $this->assertFlow($workspace, $flow);
        $this->assertStep($flow, $step);

        if ($httpRequest->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit-step' . $step->getId(), (string) $httpRequest->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $step->setName(trim((string) $httpRequest->request->get('name')) ?: $step->getName());
            $step->setExtractions($parser->parseExtractions((string) $httpRequest->request->get('extractions')));
            $step->setAssertions($parser->parseAssertions((string) $httpRequest->request->get('assertions')));

            $step->setRetryEnabled((bool) $httpRequest->request->get('retryEnabled'));
            $step->setRetryMax(max(1, min(20, (int) $httpRequest->request->get('retryMax', 5))));
            $step->setRetryDelayMs(max(0, min(10000, (int) $httpRequest->request->get('retryDelayMs', 1000))));

            if ($step->isDb()) {
                $step->setQuery($this->nullable((string) $httpRequest->request->get('query')));
                $connId = (string) $httpRequest->request->get('connection');
                if ('' !== $connId) {
                    $conn = $connections->find($connId);
                    if ($conn && $conn->getWorkspace()->getId()?->toRfc4122() === $workspace->getId()?->toRfc4122()) {
                        $step->setDbConnection($conn);
                    }
                }
            } elseif ($step->isSetvar() || $step->isDelay()) {
                $step->setQuery($this->nullable((string) $httpRequest->request->get('query')));
            } else {
                // HTTP: edit this step's own flow-owned request copy.
                $step->setReqMethod((string) $httpRequest->request->get('method', $step->getReqMethod()));
                $step->setReqUrl((string) $httpRequest->request->get('url', ''));
                $step->setReqParams($this->kvFromArrays($httpRequest->request->all('param_name'), $httpRequest->request->all('param_value')));
                $step->setReqHeaders($this->kvFromArrays($httpRequest->request->all('header_name'), $httpRequest->request->all('header_value')));
                $mode = (string) $httpRequest->request->get('bodyMode', 'none');
                $step->setReqBodyMode(\in_array($mode, ['none', 'raw', 'json', 'form'], true) ? $mode : 'none');
                $step->setReqBody($this->nullable((string) $httpRequest->request->get('body')));
                $step->setReqAuth($this->authFromRequest($httpRequest));
            }

            $steps->save($step);
            $this->addFlash('success', 'Adım kaydedildi.');

            return $this->redirectToRoute('app_flow_show', ['workspace' => $workspace->getId(), 'flow' => $flow->getId()]);
        }

        return $this->render('app/flow/step_edit.html.twig', [
            'workspace' => $workspace,
            'flow' => $flow,
            'step' => $step,
            'extractions_text' => $parser->renderExtractions($step->getExtractions()),
            'assertions_text' => $parser->renderAssertions($step->getAssertions()),
            'connections' => $connections->findByWorkspace($workspace),
        ]);
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
    ): JsonResponse {
        $this->assertWorkspace($workspace);
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
                return new JsonResponse(['ok' => false, 'error' => 'Bağlantı seçili değil.']);
            }
            $r = $dbQuery->run($step->getDbConnection(), $step->getQuery(), $vars);

            return new JsonResponse(['ok' => $r->ok, 'kind' => 'db', 'json' => $r->ok ? $r->data : null, 'error' => $r->error]);
        }

        $result = $runner->send($step->toTransientRequest(), $vars, $workspace);
        $parsed = null;
        if (null !== $result->body && '' !== $result->body) {
            $decoded = json_decode($result->body, true);
            $parsed = \JSON_ERROR_NONE === json_last_error() ? $decoded : null;
        }

        return new JsonResponse([
            'ok' => $result->ok, 'kind' => 'http', 'status' => $result->statusCode,
            'json' => $parsed, 'rawBody' => null === $result->body ? null : mb_substr($result->body, 0, 20000),
            'error' => $result->error,
        ]);
    }

    #[Route('/{step}/move', name: 'app_flow_step_move', methods: ['POST'])]
    public function move(
        Workspace $workspace,
        #[MapEntity(mapping: ['flow' => 'id'])] TestFlow $flow,
        #[MapEntity(mapping: ['step' => 'id'])] FlowStep $step,
        Request $httpRequest,
        FlowStepRepository $steps,
    ): Response {
        $this->assertWorkspace($workspace);
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
        $this->assertWorkspace($workspace);
        $this->assertFlow($workspace, $flow);
        $this->assertStep($flow, $step);

        if (!$this->isCsrfTokenValid('delete' . $step->getId(), (string) $httpRequest->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $steps->remove($step);
        $this->addFlash('success', 'Adım silindi.');

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
