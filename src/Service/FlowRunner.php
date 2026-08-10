<?php

namespace App\Service;

use App\Entity\Environment;
use App\Entity\FlowRun;
use App\Entity\FlowStep;
use App\Entity\StepResult;
use App\Entity\TestFlow;
use App\Entity\User;
use App\Service\Db\DbQueryRunner;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Executes a TestFlow step by step (HTTP requests and DB queries), threading
 * each result into a shared run context so later steps can reference earlier
 * extractions via {{var}}.
 */
class FlowRunner
{
    private const MAX_BODY_SNAPSHOT = 20000;
    private const MAX_LOOP = 100;

    public function __construct(
        private readonly RequestRunner $requestRunner,
        private readonly DbQueryRunner $dbQueryRunner,
        private readonly JsonPathExtractor $jsonPath,
        private readonly VariableResolver $resolver,
        private readonly EntityManagerInterface $em,
        private readonly DynamicVariableGenerator $dynamic,
        private readonly \App\Repository\DataFactoryRepository $factories,
        private readonly ResponseShape $shape,
        private readonly JsonSchema $jsonSchema,
        private readonly \App\Repository\DbConnectionRepository $dbConnections,
    ) {
    }

    /**
     * @param array<string, string> $vars one-off variables merged over the environment
     */
    public function run(TestFlow $flow, ?Environment $environment, string $trigger = 'manual', array $vars = [], ?User $actor = null): FlowRun
    {
        $run = $this->createRun($flow, $environment, $trigger, null, 0, [], $actor);

        return $this->executeInto($run, $flow, $environment, $vars);
    }

    /**
     * Runs the flow once per dataset row, each with that row's variables merged
     * into the context. Returns one FlowRun per iteration, grouped by a batch id.
     *
     * @param array<int, array<string, mixed>> $dataset
     * @param array<string, string>            $baseVars merged under every row (e.g. personal env values)
     *
     * @return FlowRun[]
     */
    public function runDataset(TestFlow $flow, ?Environment $environment, array $dataset, string $trigger = 'manual', array $baseVars = [], ?User $actor = null): array
    {
        $batchId = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
        $runs = [];
        $i = 0;
        foreach ($dataset as $row) {
            $run = $this->createRun($flow, $environment, $trigger, $batchId, $i, \is_array($row) ? $row : [], $actor);
            // Dataset row wins over $baseVars (which carries the user's personal env values).
            $runs[] = $this->executeInto($run, $flow, $environment, array_merge($baseVars, $this->rowVars($row)));
            ++$i;
        }

        return $runs;
    }

    /**
     * Creates and persists the FlowRun shell up-front (status=running) so it has
     * an id immediately (needed for async dispatch) and progress is observable.
     *
     * @param array<string, mixed> $iterationData
     */
    public function createRun(TestFlow $flow, ?Environment $environment, string $trigger, ?string $batchId, int $iteration, array $iterationData, ?User $actor = null): FlowRun
    {
        $run = new FlowRun();
        $run->setFlow($flow);
        $run->setTrigger($trigger);
        $run->setTriggeredBy($actor);
        $run->setEnvironmentName($environment?->getName());
        $run->setBatchId($batchId);
        $run->setIteration($iteration);
        $run->setIterationData($iterationData);
        $run->setTotalSteps($flow->getSteps()->count());
        $run->setStatus(FlowRun::STATUS_RUNNING);

        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    /**
     * Executes the flow's steps into an already-persisted run, flushing after each
     * step so progress is visible live, and honouring a cooperative cancel flag.
     *
     * @param array<string, string> $extraVars merged over the environment (row wins)
     */
    public function executeInto(FlowRun $run, TestFlow $flow, ?Environment $environment, array $extraVars = []): FlowRun
    {
        // Polling steps can sleep between attempts; don't let PHP's own timer abort the run.
        @set_time_limit(0);

        $context = array_merge($environment ? $environment->toMap() : [], $extraVars);
        $this->loadFactories($flow);
        // Expand call steps so progress/total reflect the sub-flow steps that actually run.
        $run->setTotalSteps($this->countExpanded($flow, []));

        $state = ['position' => 0, 'passed' => 0, 'stopped' => false, 'sawError' => false, 'failed' => false, 'cancelled' => false];
        $this->executeSteps($run, $flow, $context, $state, [], $flow->isStopOnFailure());

        $run->setPassedSteps($state['passed']);
        // Total = steps actually emitted (condition-skipped calls collapse to 1),
        // so "N/total" stays truthful even with branching.
        $run->setTotalSteps($state['position']);
        $run->setFinishedAt(new \DateTimeImmutable());
        $run->setStatus(match (true) {
            $state['cancelled'] => FlowRun::STATUS_CANCELLED,
            $state['sawError'] => FlowRun::STATUS_ERROR,
            $state['failed'] => FlowRun::STATUS_FAILED,
            default => FlowRun::STATUS_PASSED,
        });
        $this->em->flush();

        return $run;
    }

    /**
     * Executes a flow's steps into the run, sharing a single variable context and
     * progress state across the (possibly nested) call. A "call" step runs the
     * referenced flow's current steps inline — so a reusable "login" sub-flow can
     * extract a token the parent then uses.
     *
     * @param array<string, string> $context
     * @param array{position: int, passed: int, stopped: bool, sawError: bool, cancelled: bool} $state
     * @param string[]               $callStack flow ids on the current call path (cycle guard)
     */
    private function executeSteps(FlowRun $run, TestFlow $flow, array &$context, array &$state, array $callStack, bool $stopOnFailure, string $labelPrefix = ''): void
    {
        foreach ($flow->getSteps() as $step) {
            /** @var FlowStep $step */
            if (!$state['stopped'] && $this->cancelRequested($run)) {
                $state['cancelled'] = true;
                $state['stopped'] = true;
            }

            // Run-if guard: an unmet condition skips the step (call included) — not a failure.
            // A looped step defers its condition to each iteration (filter semantics),
            // because the loop variable ({{item}}) only exists inside the loop.
            if (!$state['stopped'] && $step->hasCondition() && !$step->hasLoop() && !$this->conditionMet($step, $context)) {
                $result = new StepResult();
                $result->setPosition($state['position']++);
                $result->setLabel($labelPrefix . $step->getName());
                $result->setStatus(StepResult::STATUS_SKIPPED);
                $result->setError('Condition not met — step skipped.');
                $run->addStepResult($result);
                $this->em->flush();
                continue;
            }

            // forEach loop: run the step once per element of the resolved list.
            if (!$state['stopped'] && $step->hasLoop()) {
                $loop = $step->getLoop();
                $items = $this->resolveList((string) ($loop['over'] ?? ''), $context);
                $as = trim((string) ($loop['as'] ?? 'item')) ?: 'item';
                if ([] === $items) {
                    $result = new StepResult();
                    $result->setPosition($state['position']++);
                    $result->setLabel($labelPrefix . $step->getName());
                    $result->setStatus(StepResult::STATUS_SKIPPED);
                    $result->setError('Loop list is empty — 0 items.');
                    $run->addStepResult($result);
                    $this->em->flush();
                    continue;
                }
                $i = 0;
                $ran = 0;
                foreach ($items as $item) {
                    if ($i >= self::MAX_LOOP) {
                        break;
                    }
                    $this->bindLoopVars($context, $as, $item, $i);
                    // Per-iteration run-if: with a condition the loop acts as a
                    // filter — only matching elements execute the step body.
                    if ($step->hasCondition() && !$this->conditionMet($step, $context)) {
                        ++$i;
                        continue;
                    }
                    $this->executeStepBody($run, $flow, $step, $context, $state, $callStack, $stopOnFailure, $labelPrefix . '[' . $i . '] ');
                    ++$ran;
                    ++$i;
                    if ($state['stopped']) {
                        break;
                    }
                }
                if (0 === $ran) {
                    $result = new StepResult();
                    $result->setPosition($state['position']++);
                    $result->setLabel($labelPrefix . $step->getName());
                    $result->setStatus(StepResult::STATUS_SKIPPED);
                    $result->setError(\sprintf('Loop condition matched 0 of %d items.', \count($items)));
                    $run->addStepResult($result);
                    $this->em->flush();
                }
                continue;
            }

            $this->executeStepBody($run, $flow, $step, $context, $state, $callStack, $stopOnFailure, $labelPrefix);
        }
    }

    /**
     * Runs a single step once (a call recurses into its flow; a leaf executes),
     * emitting its result(s) and updating shared state.
     *
     * @param array<string, string> $context
     * @param array<string, mixed>  $state
     * @param string[]              $callStack
     */
    private function executeStepBody(FlowRun $run, TestFlow $flow, FlowStep $step, array &$context, array &$state, array $callStack, bool $stopOnFailure, string $labelPrefix): void
    {
        if ($step->isCall()) {
            $called = $step->getCalledFlow();
            $calledId = $called?->getId()?->toRfc4122();
            if (null !== $called && null !== $calledId && !\in_array($calledId, $callStack, true)) {
                $this->executeSteps($run, $called, $context, $state, array_merge($callStack, [$calledId]), $stopOnFailure, $labelPrefix . $called->getName() . ' › ');

                return;
            }
            // Missing or cyclic → a single marker result.
            $result = new StepResult();
            $result->setPosition($state['position']++);
            $result->setLabel($labelPrefix . $step->getName());
            $result->setRequestMethod('CALL');
            if ($state['stopped']) {
                $result->setStatus(StepResult::STATUS_SKIPPED);
            } else {
                $result->setStatus(StepResult::STATUS_ERROR);
                $result->setError(null === $called ? 'The called sub-flow was not found or has been deleted.' : 'Recursive sub-flow call blocked.');
                $state['sawError'] = true;
                $state['stopped'] = $stopOnFailure;
            }
            $run->addStepResult($result);
            $run->setPassedSteps($state['passed']);
            $this->em->flush();

            return;
        }

        $result = new StepResult();
        $result->setPosition($state['position']++);
        $result->setLabel($labelPrefix . $step->getName());

        if ($state['stopped']) {
            $result->setStatus(StepResult::STATUS_SKIPPED);
            $run->addStepResult($result);
            $this->em->flush();

            return;
        }

        $outcome = match (true) {
            $step->isDelay() => $this->runDelayStep($step, $result),
            $step->isSetvar() => $this->runSetvarStep($step, $result, $context),
            $step->isDb() => $this->runDbStep($step, $result, $context, $flow->getWorkspace()),
            $step->isBrowser() => $this->runBrowserStep($step, $result, $context),
            default => $this->runHttpStep($step, $result, $context, $flow->getWorkspace(), $run->getTriggeredBy()),
        };

        $run->addStepResult($result);

        if (StepResult::STATUS_PASSED === $outcome) {
            ++$state['passed'];
        } else {
            if (StepResult::STATUS_ERROR === $outcome) {
                $state['sawError'] = true;
            } else {
                $state['failed'] = true;
            }
            $state['stopped'] = $stopOnFailure;
        }

        $run->setPassedSteps($state['passed']);
        $this->em->flush();
    }

    /**
     * Registers the workspace's data factories so {{$name}} resolves them for
     * this run (fresh value per occurrence).
     */
    private function loadFactories(TestFlow $flow): void
    {
        $map = [];
        foreach ($this->factories->findByWorkspace($flow->getWorkspace()) as $f) {
            $map[$f->getName()] = ['kind' => $f->getKind(), 'config' => $f->getConfig()];
        }
        $this->dynamic->setFactories($map);
    }

    /**
     * Resolves a loop's `over` expression to a list (JSON array; {{vars}} first).
     *
     * @param array<string, string> $context
     *
     * @return array<int, mixed>
     */
    private function resolveList(string $over, array $context): array
    {
        $raw = $this->resolver->resolve($over, $context) ?? '';
        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * Binds the current loop element into the context: {{as}}, {{as_index}},
     * and (for objects/arrays) dotted {{as.field}} paths.
     *
     * @param array<string, string> $context
     */
    private function bindLoopVars(array &$context, string $as, mixed $item, int $index): void
    {
        $context[$as . '_index'] = (string) $index;
        $context[$as] = \is_scalar($item) ? (string) $item : (string) json_encode($item, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if (\is_array($item)) {
            $this->flattenInto($context, $as, $item);
        }
    }

    /**
     * @param array<string, string> $context
     * @param array<mixed>          $value
     */
    private function flattenInto(array &$context, string $prefix, array $value): void
    {
        foreach ($value as $k => $v) {
            $key = $prefix . '.' . $k;
            if (\is_array($v)) {
                $this->flattenInto($context, $key, $v);
            } else {
                $context[$key] = \is_scalar($v) ? (string) $v : (string) json_encode($v, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            }
        }
    }

    /**
     * Evaluates a step's run-if condition against the current context.
     *
     * @param array<string, string> $context
     */
    private function conditionMet(FlowStep $step, array $context): bool
    {
        $c = $step->getCondition();
        if (null === $c || '' === trim((string) ($c['left'] ?? ''))) {
            return true;
        }
        $left = $this->resolver->resolve((string) $c['left'], $context) ?? '';
        // An unresolved {{placeholder}} counts as "not found".
        $found = '' !== $left && !str_contains($left, '{{');

        return $this->applyOp((string) ($c['op'] ?? 'eq'), $found, $left, (string) ($c['right'] ?? ''));
    }

    /**
     * Number of leaf steps that will actually run, expanding call steps.
     * A missing/cyclic call counts as 1 (its error marker).
     *
     * @param string[] $callStack
     */
    private function countExpanded(TestFlow $flow, array $callStack): int
    {
        $n = 0;
        foreach ($flow->getSteps() as $step) {
            if ($step->isCall()) {
                $called = $step->getCalledFlow();
                $calledId = $called?->getId()?->toRfc4122();
                if (null !== $called && null !== $calledId && !\in_array($calledId, $callStack, true)) {
                    $n += $this->countExpanded($called, array_merge($callStack, [$calledId]));
                } else {
                    ++$n;
                }
            } else {
                ++$n;
            }
        }

        return $n;
    }

    /**
     * @param mixed $row
     *
     * @return array<string, string>
     */
    private function rowVars(mixed $row): array
    {
        $vars = [];
        if (\is_array($row)) {
            foreach ($row as $k => $v) {
                $vars[(string) $k] = \is_scalar($v) ? (string) $v : (string) json_encode($v);
            }
        }

        return $vars;
    }

    private function cancelRequested(FlowRun $run): bool
    {
        return (bool) $this->em->getConnection()->fetchOne(
            'SELECT cancel_requested FROM flow_run WHERE id = ?',
            [(string) $run->getId()],
        );
    }

    /**
     * @param array<string, string> $context
     */
    private function runHttpStep(FlowStep $step, StepResult $result, array &$context, \App\Entity\Workspace $workspace, ?User $actor): string
    {
        // Each step carries its own flow-owned request copy (independent of the collection).
        $apiRequest = $step->toTransientRequest();
        if ('' === trim($apiRequest->getUrl())) {
            $result->setStatus(StepResult::STATUS_ERROR);
            $result->setError('The step URL is empty.');

            return StepResult::STATUS_ERROR;
        }

        [$max, $delay] = $this->retrySpec($step);
        $attempt = 0;
        $status = StepResult::STATUS_FAILED;

        while ($attempt < $max) {
            ++$attempt;
            // The jar belongs to whoever set the run off; scheduled runs (no
            // actor) use the workspace's shared jar.
            $response = $this->requestRunner->send($apiRequest, $context, $workspace, $actor);
            $result->setRequestMethod($response->method);
            $result->setRequestUrl($response->url);
            $result->setResponseStatus($response->statusCode);
            $result->setDurationMs((int) round($response->durationMs));
            $result->setResponseBody($this->truncate($response->body));

            if (!$response->ok) {
                $result->setStatus(StepResult::STATUS_ERROR);
                $result->setError($response->error);
                $status = StepResult::STATUS_ERROR;
            } else {
                $result->setError(null);
                $decoded = json_decode((string) $response->body, true);
                $this->checkContract($step, $result, $decoded);
                $status = $this->applyExtractionsAndAssertions($step, $result, $context, $decoded, (string) $response->body, $response->statusCode, $response->durationMs, $response->headers);
            }

            if (StepResult::STATUS_PASSED === $status) {
                break;
            }
            if ($attempt < $max) {
                usleep($delay * 1000);
            }
        }

        $result->setAttempts($attempt);

        return $status;
    }

    /**
     * @param array<string, string> $context
     */
    private function runDbStep(FlowStep $step, StepResult $result, array &$context, \App\Entity\Workspace $workspace): string
    {
        $connection = $step->getDbConnection();
        $result->setRequestMethod('DB');

        // Env-aware connection override: if the active environment defines a
        // `dbConnection` variable naming another connection in this workspace,
        // run the query against that instead of the step's bound connection.
        // Lets one flow verify Dev's DB or Pre-Prod's DB purely by env choice.
        $override = trim((string) ($context['dbConnection'] ?? ''));
        if ('' !== $override && (null === $connection || $connection->getName() !== $override)) {
            $resolved = $this->dbConnections->findOneBy(['workspace' => $workspace, 'name' => $override]);
            // Only reroute to a connection of the SAME type: a MySQL override
            // must not hijack a Mongo/Redis step (its query wouldn't parse).
            if (null !== $resolved && (null === $connection || $resolved->getType() === $connection->getType())) {
                $connection = $resolved;
            }
        }

        if (null === $connection) {
            $result->setStatus(StepResult::STATUS_ERROR);
            $result->setError('The database connection for this step has been deleted.');

            return StepResult::STATUS_ERROR;
        }

        $result->setRequestUrl(sprintf('%s: %s', $connection->getType(), $connection->getName()));

        [$max, $delay] = $this->retrySpec($step);
        $attempt = 0;
        $status = StepResult::STATUS_FAILED;

        while ($attempt < $max) {
            ++$attempt;
            $dbResult = $this->dbQueryRunner->run($connection, $step->getQuery(), $context);
            $result->setDurationMs((int) round($dbResult->durationMs));
            $result->setResponseBody($this->truncate($dbResult->display));

            if (!$dbResult->ok) {
                $result->setStatus(StepResult::STATUS_ERROR);
                $result->setError($dbResult->error);
                $status = StepResult::STATUS_ERROR;
            } else {
                $result->setError(null);
                $status = $this->applyExtractionsAndAssertions($step, $result, $context, $dbResult->data, $dbResult->display, null, $dbResult->durationMs, []);
            }

            if (StepResult::STATUS_PASSED === $status) {
                break;
            }
            if ($attempt < $max) {
                usleep($delay * 1000);
            }
        }

        $result->setAttempts($attempt);

        return $status;
    }

    /**
     * Captures the step's baseline response shape on first success, or records
     * how the current response's shape drifted from that baseline. Drift is
     * informational — it does not fail the step. Non-JSON responses are skipped.
     */
    private function checkContract(FlowStep $step, StepResult $result, mixed $decoded): void
    {
        if (!\is_array($decoded)) {
            return;
        }
        $shape = $this->shape->of($decoded);
        $baseline = $step->getResponseShape();
        if (null === $baseline) {
            $step->setResponseShape($shape);
            $step->setContractBaselineAt(new \DateTimeImmutable());
            $result->setShapeDrift([]);

            return;
        }
        $result->setShapeDrift($this->shape->diff($baseline, $shape));
    }

    private function runDelayStep(FlowStep $step, StepResult $result): string
    {
        $ms = max(0, min(60000, (int) trim((string) $step->getQuery())));
        $result->setRequestMethod('DELAY');
        $result->setRequestUrl($ms . ' ms bekle');

        $start = microtime(true);
        usleep($ms * 1000);
        $result->setDurationMs((int) round((microtime(true) - $start) * 1000));
        $result->setStatus(StepResult::STATUS_PASSED);

        return StepResult::STATUS_PASSED;
    }

    /**
     * @param array<string, string> $context
     */
    /**
     * Drives a real (headless) browser through a redirect/challenge page —
     * 3DS simulators, OTP screens, PSP-hosted forms. The step's query field
     * holds a JSON config: {url, successUrlPattern?, actions?, timeoutMs?}.
     * PSP simulators (Checkout, Stripe, Adyen, generic) are auto-detected by
     * the signal_browser service; no per-PSP flow configuration is needed.
     *
     * Assertions/extractions run over the runner's JSON result:
     *   sessionStatus ('completed'|'timeout'|'error'), finalUrl, log[], durationMs.
     *   (The field is named sessionStatus because a bare "status" in an
     *   assertion is parsed as the HTTP status-code kind.)
     *
     * @param array<string, string> $context
     */
    private function runBrowserStep(FlowStep $step, StepResult $result, array &$context): string
    {
        $result->setRequestMethod('BROWSER');

        $config = json_decode((string) $step->getQuery(), true);
        if (!\is_array($config) || '' === trim((string) ($config['url'] ?? ''))) {
            $result->setStatus(StepResult::STATUS_ERROR);
            $result->setError('Browser step config must be JSON with a "url" field.');

            return StepResult::STATUS_ERROR;
        }

        $url = (string) $this->resolver->resolve((string) $config['url'], $context);
        $payload = [
            'url' => $url,
            'successUrlPattern' => $this->resolver->resolve((string) ($config['successUrlPattern'] ?? ''), $context) ?: null,
            'actions' => $config['actions'] ?? null,
            'timeoutMs' => $config['timeoutMs'] ?? null,
        ];
        $result->setRequestUrl($url);

        $runnerUrl = rtrim((string) ($_SERVER['BROWSER_RUNNER_URL'] ?? getenv('BROWSER_RUNNER_URL') ?: 'http://browser:7300'), '/');
        $started = microtime(true);

        try {
            $response = $this->browserClient()->request('POST', $runnerUrl.'/run', [
                'json' => array_filter($payload, static fn ($v) => null !== $v),
                'timeout' => 150,
            ]);
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $result->setStatus(StepResult::STATUS_ERROR);
            $result->setError('Browser runner unreachable: '.$e->getMessage());
            $result->setDurationMs((int) round((microtime(true) - $started) * 1000));

            return StepResult::STATUS_ERROR;
        }

        $durationMs = (float) ($data['durationMs'] ?? (microtime(true) - $started) * 1000);
        $display = (string) json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $result->setDurationMs((int) round($durationMs));
        $result->setResponseBody($this->truncate($display));

        if (($data['sessionStatus'] ?? 'error') === 'error') {
            $result->setStatus(StepResult::STATUS_ERROR);
            $result->setError((string) ($data['error'] ?? 'Browser session failed.'));

            return StepResult::STATUS_ERROR;
        }

        // Expose the landing URL to later steps even without an explicit extraction.
        $context['browserFinalUrl'] = (string) ($data['finalUrl'] ?? '');

        return $this->applyExtractionsAndAssertions($step, $result, $context, $data, $display, null, $durationMs, []);
    }

    private ?\Symfony\Contracts\HttpClient\HttpClientInterface $browserHttp = null;

    private function browserClient(): \Symfony\Contracts\HttpClient\HttpClientInterface
    {
        return $this->browserHttp ??= \Symfony\Component\HttpClient\HttpClient::create();
    }

    private function runSetvarStep(FlowStep $step, StepResult $result, array &$context): string
    {
        $result->setRequestMethod('SET');
        $set = [];

        foreach (preg_split('/\r\n|\r|\n/', (string) $step->getQuery()) ?: [] as $line) {
            $line = trim($line);
            if ('' === $line || !str_contains($line, '=')) {
                continue;
            }
            [$name, $expr] = explode('=', $line, 2);
            $name = trim($name);
            if ('' === $name) {
                continue;
            }
            $value = $this->resolver->resolve(trim($expr), $context) ?? '';
            $context[$name] = $value;
            $set[$name] = $value;
        }

        $result->setRequestUrl(\count($set) . ' variables set');
        $result->setExtractedVars($set);
        $result->setDurationMs(0);
        $result->setStatus(StepResult::STATUS_PASSED);

        return StepResult::STATUS_PASSED;
    }

    /**
     * @return array{0: int, 1: int} [maxAttempts, delayMs]; retry only when assertions exist.
     */
    private function retrySpec(FlowStep $step): array
    {
        if (!$step->isRetryEnabled() || [] === $step->getAssertions()) {
            return [1, 0];
        }

        return [max(1, min(20, $step->getRetryMax())), max(0, min(10000, $step->getRetryDelayMs()))];
    }

    /**
     * Shared extraction + assertion evaluation for both step kinds.
     *
     * @param array<string, string> $context
     */
    private const OP_TOKEN = [
        'eq' => '==', 'equals' => '==', 'ne' => '!=', 'gt' => '>', 'lt' => '<', 'ge' => '>=', 'le' => '<=',
        'contains' => 'contains', 'matches' => 'matches', 'exists' => 'exists', 'empty' => 'empty', 'notEmpty' => 'notEmpty',
    ];

    /**
     * @param array<string, string>        $context
     * @param array<string, array<string>> $headers
     */
    private function applyExtractionsAndAssertions(
        FlowStep $step,
        StepResult $result,
        array &$context,
        mixed $decoded,
        string $rawBody,
        ?int $statusCode,
        float $durationMs,
        array $headers,
    ): string {
        $extracted = [];
        foreach ($step->getExtractions() as $extraction) {
            $found = $this->jsonPath->find($decoded, $extraction['path']);
            $value = $found['found'] ? $this->jsonPath->stringify($found['value']) : '';
            $context[$extraction['var']] = $value;
            $extracted[$extraction['var']] = $value;
        }
        $result->setExtractedVars($extracted);

        $assertionResults = [];
        $allPassed = true;
        foreach ($step->getAssertions() as $assertion) {
            $eval = $this->evaluate($assertion, $statusCode, $rawBody, $decoded, $durationMs, $headers, $context);
            $assertionResults[] = $eval;
            if (!$eval['ok']) {
                $allPassed = false;
            }
        }
        $result->setAssertionResults($assertionResults);
        $result->setStatus($allPassed ? StepResult::STATUS_PASSED : StepResult::STATUS_FAILED);

        return $result->getStatus();
    }

    /**
     * @param array<string, string>        $assertion
     * @param array<string, array<string>> $headers
     *
     * @return array{label: string, ok: bool, actual: string}
     */
    private function evaluate(array $assertion, ?int $statusCode, string $rawBody, mixed $decoded, float $durationMs, array $headers, array $context = []): array
    {
        $kind = $assertion['kind'] ?? '';
        $op = $assertion['op'] ?? 'eq';
        // Resolve {{var}} in the expected value so assertions can be env-driven
        // (e.g. provider_id == {{yunoProviderId}} → 40 on Dev, 144249 on Pre-Prod).
        $expected = (string) ($this->resolver->resolve((string) ($assertion['expected'] ?? ''), $context) ?? '');
        $token = self::OP_TOKEN[$op] ?? $op;

        // Schema validation doesn't fit the target/op/value model.
        if ('schema' === $kind) {
            $schema = json_decode((string) ($assertion['schema'] ?? ''), true);
            if (!\is_array($schema)) {
                return ['label' => 'response matches the JSON schema', 'ok' => false, 'actual' => 'invalid schema definition'];
            }
            $violations = $this->jsonSchema->validate($schema, $decoded);

            return [
                'label' => 'response matches the JSON schema',
                'ok' => [] === $violations,
                'actual' => [] === $violations ? 'uygun' : implode(' · ', \array_slice($violations, 0, 5)),
            ];
        }

        // Resolve (target label, actual value, whether the target exists).
        switch ($kind) {
            case 'status':
                $target = 'status';
                $found = null !== $statusCode;
                $actual = $found ? (string) $statusCode : '(yok)';
                break;
            case 'responseTime':
                $target = 'responseTime';
                $found = true;
                $actual = (string) (int) round($durationMs);
                break;
            case 'body':
                $target = 'body';
                $found = '' !== $rawBody;
                $actual = $rawBody;
                break;
            case 'header':
                $name = (string) ($assertion['name'] ?? '');
                $target = 'header ' . $name;
                $values = $headers[strtolower($name)] ?? null;
                $found = null !== $values;
                $actual = $found ? implode(', ', $values) : '(yok)';
                break;
            default:
                $path = (string) ($assertion['path'] ?? '');
                $target = $path;
                $res = $this->jsonPath->find($decoded, $path);
                $found = $res['found'];
                $actual = $found ? $this->jsonPath->stringify($res['value']) : '(yok)';
        }

        $ok = $this->applyOp($op, $found, $actual, $expected);

        $label = \in_array($op, ['exists', 'empty', 'notEmpty'], true)
            ? sprintf('%s %s', $target, $token)
            : sprintf('%s %s %s', $target, $token, $expected);

        return ['label' => $label, 'ok' => $ok, 'actual' => mb_substr($actual, 0, 120)];
    }

    private function applyOp(string $op, bool $found, string $actual, string $expected): bool
    {
        return match ($op) {
            'exists' => $found,
            'empty' => !$found || \in_array(trim($actual), ['', '[]', '{}', 'null'], true),
            'notEmpty' => $found && !\in_array(trim($actual), ['', '[]', '{}', 'null'], true),
            'eq', 'equals' => $found && $actual === $expected,
            'ne' => $actual !== $expected,
            'gt' => $found && (float) $actual > (float) $expected,
            'lt' => $found && (float) $actual < (float) $expected,
            'ge' => $found && (float) $actual >= (float) $expected,
            'le' => $found && (float) $actual <= (float) $expected,
            'contains' => $found && '' !== $expected && str_contains($actual, $expected),
            'matches' => $found && 1 === @preg_match('~' . $expected . '~', $actual),
            default => false,
        };
    }

    private function truncate(?string $body): ?string
    {
        if (null === $body) {
            return null;
        }

        return mb_strlen($body) > self::MAX_BODY_SNAPSHOT
            ? mb_substr($body, 0, self::MAX_BODY_SNAPSHOT) . "\n… (truncated)"
            : $body;
    }
}
