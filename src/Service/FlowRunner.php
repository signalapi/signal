<?php

namespace App\Service;

use App\Entity\Environment;
use App\Entity\FlowRun;
use App\Entity\FlowStep;
use App\Entity\StepResult;
use App\Entity\TestFlow;
use App\Entity\User;
use App\Event\DatasetRunFinished;
use App\Event\FlowRunFinished;
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
    /** Repeats per dataset row — enough for a pass rate, short of a runaway bill. */
    private const MAX_REPEATS = 20;
    /**
     * Runs a REPEATED dataset may schedule in one synchronous batch. A plain
     * one-run-per-row dataset stays unbounded as it always was; it is the
     * repeat multiplier that can turn 40 rows into 800 model calls and outlive
     * any request timeout.
     */
    public const MAX_DATASET_RUNS = 60;
    /** Model calls one agent step may make before it is cut off. */
    private const MAX_AGENT_TURNS = 20;

    public function __construct(
        private readonly RequestRunner $requestRunner,
        private readonly DbQueryRunner $dbQueryRunner,
        private readonly JsonPathExtractor $jsonPath,
        private readonly VariableResolver $resolver,
        private readonly EntityManagerInterface $em,
        private readonly DynamicVariableGenerator $dynamic,
        private readonly \App\Repository\DataFactoryRepository $factories,
        private readonly ResponseShape $shape,
        private readonly ResponseSnapshot $snapshot,
        private readonly JsonSchema $jsonSchema,
        private readonly \App\Repository\DbConnectionRepository $dbConnections,
        private readonly \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $events,
        private readonly AnthropicClient $claude,
        private readonly SemanticJudge $judge,
        private readonly \App\Service\Mcp\McpClient $mcp,
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
     * With $repeats > 1 every row is run that many times in the same batch. That
     * is what makes a non-deterministic flow measurable: one run of an LLM or
     * agent step tells you it passed once, K runs tell you how often it passes —
     * and EvalReport turns the batch into that number. Repeats of a row share
     * its `iteration`; the attempt is on the run's repeatIndex.
     *
     * @param array<int, array<string, mixed>> $dataset
     * @param array<string, string>            $baseVars merged under every row (e.g. personal env values)
     *
     * @return FlowRun[]
     */
    public function runDataset(TestFlow $flow, ?Environment $environment, array $dataset, string $trigger = 'manual', array $baseVars = [], ?User $actor = null, int $repeats = 1, ?string $batchId = null, bool $announce = true): array
    {
        // A caller that owns the batch (an evaluation) passes its id in, so the
        // record exists before the first run does and progress is observable.
        $batchId ??= \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
        $repeats = max(1, min(self::MAX_REPEATS, $repeats));
        $runs = [];
        $i = 0;
        foreach ($dataset as $row) {
            for ($r = 0; $r < $repeats; ++$r) {
                $run = $this->createRun($flow, $environment, $trigger, $batchId, $i, \is_array($row) ? $row : [], $actor, $r);
                // Dataset row wins over $baseVars (which carries the user's personal env values).
                $runs[] = $this->executeInto($run, $flow, $environment, array_merge($baseVars, $this->rowVars($row)));
            }
            ++$i;
        }

        // The batch reports once; the per-row events stay silent (see
        // NotificationDispatcher), so a 50-row dataset is not 50 messages.
        // An evaluation reports its own rate afterwards and passes announce=false,
        // or the same batch would be announced twice with different framing.
        if ($announce) {
            $this->events->dispatch(new DatasetRunFinished($flow, $batchId, $runs));
        }

        return $runs;
    }

    /**
     * Creates and persists the FlowRun shell up-front (status=running) so it has
     * an id immediately (needed for async dispatch) and progress is observable.
     *
     * @param array<string, mixed> $iterationData
     */
    public function createRun(TestFlow $flow, ?Environment $environment, string $trigger, ?string $batchId, int $iteration, array $iterationData, ?User $actor = null, int $repeatIndex = 0): FlowRun
    {
        $run = new FlowRun();
        $run->setFlow($flow);
        $run->setTrigger($trigger);
        $run->setTriggeredBy($actor);
        $run->setEnvironmentName($environment?->getName());
        $run->setBatchId($batchId);
        $run->setIteration($iteration);
        $run->setRepeatIndex($repeatIndex);
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

        // Single choke point for "a flow run just ended": notifications — and any
        // future post-run work — hang off this event, not off each caller.
        $this->events->dispatch(new FlowRunFinished($run));

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
     * @param bool                   $forceLive the caller is a teardown step, so these
     *                                          steps run even though the flow has stopped
     */
    private function executeSteps(FlowRun $run, TestFlow $flow, array &$context, array &$state, array $callStack, bool $stopOnFailure, string $labelPrefix = '', bool $forceLive = false): void
    {
        foreach ($flow->getSteps() as $step) {
            /** @var FlowStep $step */
            if (!$state['stopped'] && $this->cancelRequested($run)) {
                $state['cancelled'] = true;
                $state['stopped'] = true;
            }

            // Teardown steps outlive the stop — including a cancelled run, where
            // putting external state back matters more, not less. Their run-if
            // and loop guards below still apply, so "restore it only if we got as
            // far as taking a snapshot" behaves the way it reads.
            $live = !$state['stopped'] || $step->isAlwaysRun() || $forceLive;

            // Run-if guard: an unmet condition skips the step (call included) — not a failure.
            // A looped step defers its condition to each iteration (filter semantics),
            // because the loop variable ({{item}}) only exists inside the loop.
            if ($live && $step->hasCondition() && !$step->hasLoop() && !$this->conditionMet($step, $context)) {
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
            if ($live && $step->hasLoop()) {
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
                    $this->executeStepBody($run, $flow, $step, $context, $state, $callStack, $stopOnFailure, $labelPrefix . '[' . $i . '] ', $forceLive);
                    ++$ran;
                    ++$i;
                    if ($state['stopped'] && !$step->isAlwaysRun()) {
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

            $this->executeStepBody($run, $flow, $step, $context, $state, $callStack, $stopOnFailure, $labelPrefix, $forceLive);
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
    private function executeStepBody(FlowRun $run, TestFlow $flow, FlowStep $step, array &$context, array &$state, array $callStack, bool $stopOnFailure, string $labelPrefix, bool $forceLive = false): void
    {
        // A teardown step, or anything inside a teardown sub-flow, still owes work.
        $live = !$state['stopped'] || $step->isAlwaysRun() || $forceLive;
        if ($step->isCall()) {
            $called = $step->getCalledFlow();
            $calledId = $called?->getId()?->toRfc4122();
            if (null !== $called && null !== $calledId && !\in_array($calledId, $callStack, true)) {
                $this->executeSteps($run, $called, $context, $state, array_merge($callStack, [$calledId]), $stopOnFailure, $labelPrefix . $called->getName() . ' › ', $forceLive || $step->isAlwaysRun());

                return;
            }
            // Missing or cyclic → a single marker result.
            $result = new StepResult();
            $result->setPosition($state['position']++);
            $result->setLabel($labelPrefix . $step->getName());
            $result->setRequestMethod('CALL');
            if (!$live) {
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

        if (!$live) {
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
            $step->isLlm() => $this->runLlmStep($step, $result, $context),
            $step->isMcp() => $this->runMcpStep($step, $result, $context),
            $step->isAgent() => $this->runAgentStep($step, $result, $context),
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
        $op = (string) ($c['op'] ?? 'eq');
        $right = (string) ($this->resolver->resolve((string) ($c['right'] ?? ''), $context) ?? '');

        // judge lives outside applyOp (it needs a model and returns a reason), so
        // without this a judge condition fell through to applyOp's default and
        // silently never matched — the step just quietly skipped, for ever.
        if ('judge' === $op) {
            return $found && $this->judge->judge($left, $right)['pass'];
        }

        return $this->applyOp($op, $found, $left, $right);
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
                $status = $this->enforceContract($step, $result, $status);
                $status = $this->checkSnapshot($step, $result, $decoded, $status);
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
     * Value snapshot: the first successful JSON response is captured (volatile
     * paths masked); every later run must match it VALUE by VALUE or the step
     * fails with a synthetic assertion listing what changed. Reset on the step
     * to approve an intended change.
     */
    private function checkSnapshot(FlowStep $step, StepResult $result, mixed $decoded, string $status): string
    {
        if (!$step->isSnapshotEnabled() || !\is_array($decoded)) {
            return $status;
        }

        $normalized = $this->snapshot->normalize($decoded, ResponseSnapshot::parseIgnore($step->getSnapshotIgnore()));
        $assertions = $result->getAssertionResults();

        if (null === $step->getSnapshotValue()) {
            // Only a run that is otherwise green may set the approved snapshot —
            // capturing a broken response as "the truth" would lock the bug in.
            if (StepResult::STATUS_PASSED === $status) {
                $step->setSnapshotValue($normalized);
                $step->setSnapshotAt(new \DateTimeImmutable());
                $assertions[] = ['label' => 'snapshot: captured as the approved response', 'ok' => true, 'actual' => 'baseline set'];
                $result->setAssertionResults($assertions);
            }

            return $status;
        }

        $diff = $this->snapshot->diff($step->getSnapshotValue(), $normalized);
        if ([] === $diff) {
            $assertions[] = ['label' => 'snapshot: matches the approved response', 'ok' => true, 'actual' => 'no value changes'];
            $result->setAssertionResults($assertions);

            return $status;
        }

        $assertions[] = [
            'label' => 'snapshot: response matches the approved snapshot',
            'ok' => false,
            'actual' => implode(' · ', \array_slice($diff, 0, 3)) . (\count($diff) > 3 ? sprintf(' · +%d more', \count($diff) - 3) : ''),
        ];
        $result->setAssertionResults($assertions);
        $result->setStatus(StepResult::STATUS_FAILED);

        return StepResult::STATUS_FAILED;
    }

    /**
     * Strict contract mode: a step that drifted from its baseline fails, with a
     * synthetic assertion naming the first changes so the report says why.
     * Informational mode (the default) leaves the status untouched.
     */
    private function enforceContract(FlowStep $step, StepResult $result, string $status): string
    {
        $drift = $result->getShapeDrift();
        if ([] === $drift || !$step->getFlow()->isContractStrict()) {
            return $status;
        }

        $assertions = $result->getAssertionResults();
        $assertions[] = [
            'label' => 'contract: response shape matches the baseline',
            'ok' => false,
            'actual' => implode(' · ', \array_slice($drift, 0, 3)) . (\count($drift) > 3 ? sprintf(' · +%d more', \count($drift) - 3) : ''),
        ];
        $result->setAssertionResults($assertions);
        $result->setStatus(StepResult::STATUS_FAILED);

        return StepResult::STATUS_FAILED;
    }

    /**
     * Captures the step's baseline response shape on first success, or records
     * how the current response's shape drifted from that baseline. Drift is
     * informational unless the flow runs in strict contract mode (see
     * enforceContract). Non-JSON responses are skipped.
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

    /**
     * Sends a prompt to Claude and exposes the reply to the step's extractions
     * and assertions — the step type that lets a flow drive, or stand in for,
     * an LLM-backed system.
     *
     * The step's query field holds a JSON config:
     *   {prompt, system?, model?, maxTokens?}   — prompt/system resolve {{vars}}.
     *
     * Assertions/extractions run over the runner's JSON result:
     *   text, model, stopReason, inputTokens, outputTokens, durationMs, and
     *   `json` when the reply itself parsed as JSON (so "json.intent == refund"
     *   works against a structured answer). The reply is also bound to
     *   {{llmText}} for later steps.
     *
     * Retry deliberately reuses the step's normal retry spec: an LLM answer
     * that only sometimes satisfies its assertions is exactly the case retry
     * was built for — but every attempt is a billed call, so the same
     * assertions-only guard in retrySpec() applies.
     *
     * @param array<string, string> $context
     */
    private function runLlmStep(FlowStep $step, StepResult $result, array &$context): string
    {
        $result->setRequestMethod('LLM');

        $config = json_decode((string) $step->getQuery(), true);
        if (!\is_array($config) || '' === trim((string) ($config['prompt'] ?? ''))) {
            $result->setStatus(StepResult::STATUS_ERROR);
            $result->setError('LLM step config must be JSON with a "prompt" field.');

            return StepResult::STATUS_ERROR;
        }

        if (!$this->claude->isConfigured()) {
            $result->setStatus(StepResult::STATUS_ERROR);
            $result->setError('AI is not connected — an LLM step needs an Anthropic API key.');

            return StepResult::STATUS_ERROR;
        }

        $prompt = (string) $this->resolver->resolve((string) $config['prompt'], $context);
        $system = (string) $this->resolver->resolve((string) ($config['system'] ?? ''), $context);
        $model = trim((string) ($config['model'] ?? ''));
        $maxTokens = max(16, min(8000, (int) ($config['maxTokens'] ?? 1024)));
        $result->setRequestUrl('' !== $model ? $model : $this->claude->activeModel());

        [$max, $delay] = $this->retrySpec($step);
        $attempt = 0;
        $status = StepResult::STATUS_FAILED;

        while ($attempt < $max) {
            ++$attempt;
            $started = microtime(true);

            try {
                $reply = $this->claude->complete($system, $prompt, $maxTokens, '' !== $model ? $model : null, 120);
            } catch (\Throwable $e) {
                // 429 and 529 are the failures retry exists for; returning here
                // would spend the budget the step asked for on nothing.
                $result->setStatus(StepResult::STATUS_ERROR);
                $result->setError($e->getMessage());
                $result->setDurationMs((int) round((microtime(true) - $started) * 1000));
                if ($attempt < $max) {
                    usleep($delay * 1000);
                    continue;
                }
                $result->setAttempts($attempt);

                return StepResult::STATUS_ERROR;
            }

            $durationMs = (microtime(true) - $started) * 1000;
            $data = $reply + ['durationMs' => (int) round($durationMs)];
            // A reply that is itself JSON becomes addressable, so assertions can
            // target a field of a structured answer instead of matching prose.
            $json = $this->claude->decodeJson($reply['text']);
            if (null !== $json) {
                $data['json'] = $json;
            }

            $display = (string) json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            $result->setDurationMs((int) round($durationMs));
            $result->setResponseBody($this->truncate($display));
            $result->setError(null);

            // Available to later steps even without an explicit extraction.
            $context['llmText'] = $data['text'];

            $status = $this->applyExtractionsAndAssertions($step, $result, $context, $data, $display, null, $durationMs, []);

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
     * Calls one tool on an MCP server and exposes the result to the step's
     * checks — the deterministic half of testing an agent stack. The tool is
     * called directly, with the arguments you wrote, so what it asserts is the
     * SERVER's behaviour and nothing else.
     *
     * The step's query field holds a JSON config:
     *   {server, tool, arguments?, headers?, timeoutMs?}
     * server/tool/headers and every string inside arguments resolve {{vars}},
     * so the bearer token lives in the environment rather than in the step.
     *
     * Assertions/extractions run over:
     *   isError, text, result (structuredContent, or the text parsed as JSON),
     *   tool, server, durationMs.
     *
     * A tool that reports its own failure comes back as isError, not as a step
     * error: MCP puts tool failures inside the result, and whether that is a
     * test failure is for the assertions to say — "isError == true" is a
     * perfectly good thing to assert.
     *
     * @param array<string, string> $context
     */
    private function runMcpStep(FlowStep $step, StepResult $result, array &$context): string
    {
        $result->setRequestMethod('MCP');

        $config = json_decode((string) $step->getQuery(), true);
        if (!\is_array($config) || '' === trim((string) ($config['server'] ?? '')) || '' === trim((string) ($config['tool'] ?? ''))) {
            $result->setStatus(StepResult::STATUS_ERROR);
            $result->setError('MCP step config must be JSON with "server" and "tool" fields.');

            return StepResult::STATUS_ERROR;
        }

        $server = (string) $this->resolver->resolve((string) $config['server'], $context);
        $tool = (string) $this->resolver->resolve((string) $config['tool'], $context);
        $headers = $this->resolveHeaders($config['headers'] ?? [], $context);
        $arguments = (array) $this->resolveDeep($config['arguments'] ?? [], $context);
        $timeout = max(1, (int) round(((int) ($config['timeoutMs'] ?? 30000)) / 1000));
        $result->setRequestUrl($server . ' · ' . $tool);

        [$max, $delay] = $this->retrySpec($step);
        $attempt = 0;
        $status = StepResult::STATUS_FAILED;

        while ($attempt < $max) {
            ++$attempt;
            $started = microtime(true);

            try {
                $session = $this->mcp->open($server, $headers, $timeout);
                $call = $this->mcp->callTool($session, $tool, $arguments);
            } catch (\Throwable $e) {
                // A dropped connection or a server still starting up is exactly
                // what the step's retry budget is for; spend it before giving up.
                $result->setStatus(StepResult::STATUS_ERROR);
                $result->setError($e->getMessage());
                $result->setDurationMs((int) round((microtime(true) - $started) * 1000));
                if ($attempt < $max) {
                    usleep($delay * 1000);
                    continue;
                }
                $result->setAttempts($attempt);

                return StepResult::STATUS_ERROR;
            }

            $durationMs = (microtime(true) - $started) * 1000;
            $data = $call + [
                'tool' => $tool,
                'server' => $session->serverName,
                'durationMs' => (int) round($durationMs),
            ];
            $display = (string) json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            $result->setDurationMs((int) round($durationMs));
            $result->setResponseBody($this->truncate($display));
            $result->setError(null);

            $status = $this->applyExtractionsAndAssertions($step, $result, $context, $data, $display, null, $durationMs, []);

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
     * Gives a model an MCP server's tools and a task, runs the loop, and records
     * what it DID — not just what it said.
     *
     * This is the step the rest of the platform was pointed at. An agent's reply
     * is the easy part to check and the least interesting; the thing that breaks
     * in production is which tools it reached for, in what order, and whether it
     * kept going after it had the answer. So the trace is the product:
     *
     *   toolSequence  — "search_flights › book_flight", one flat string, which
     *                   means ordinary operators test agent behaviour:
     *                   "toolSequence == a › b" pins the exact chain,
     *                   "toolSequence contains refund" catches one call anywhere,
     *                   "toolSequence matches ^search" anchors the opener.
     *   toolCalls     — each call with its input and whether it errored.
     *   turns         — model calls made; "turns <= 3" is a real efficiency test.
     *   text          — the final reply, for a judge assertion.
     *
     * Config: {server, prompt, system?, model?, maxTurns?, maxTokens?, headers?, tools?}
     * `tools` narrows the server's list to a named allow-list, so a test can ask
     * whether the agent copes when a tool it wants is not there.
     *
     * @param array<string, string> $context
     */
    private function runAgentStep(FlowStep $step, StepResult $result, array &$context): string
    {
        $result->setRequestMethod('AGENT');

        $config = json_decode((string) $step->getQuery(), true);
        if (!\is_array($config) || '' === trim((string) ($config['server'] ?? '')) || '' === trim((string) ($config['prompt'] ?? ''))) {
            $result->setStatus(StepResult::STATUS_ERROR);
            $result->setError('Agent step config must be JSON with "server" and "prompt" fields.');

            return StepResult::STATUS_ERROR;
        }
        if (!$this->claude->isConfigured()) {
            $result->setStatus(StepResult::STATUS_ERROR);
            $result->setError('AI is not connected — an agent step needs an Anthropic API key.');

            return StepResult::STATUS_ERROR;
        }

        $server = (string) $this->resolver->resolve((string) $config['server'], $context);
        $prompt = (string) $this->resolver->resolve((string) $config['prompt'], $context);
        $system = (string) $this->resolver->resolve((string) ($config['system'] ?? ''), $context);
        $headers = $this->resolveHeaders($config['headers'] ?? [], $context);
        $model = trim((string) ($config['model'] ?? ''));
        $maxTokens = max(256, min(8000, (int) ($config['maxTokens'] ?? 2048)));
        $maxTurns = max(1, min(self::MAX_AGENT_TURNS, (int) ($config['maxTurns'] ?? 8)));
        $allow = array_filter(array_map('strval', (array) ($config['tools'] ?? [])));
        $result->setRequestUrl($server);

        $started = microtime(true);

        try {
            $session = $this->mcp->open($server, $headers, 30);
        } catch (\Throwable $e) {
            $result->setStatus(StepResult::STATUS_ERROR);
            $result->setError('MCP server unreachable: ' . $e->getMessage());
            $result->setDurationMs((int) round((microtime(true) - $started) * 1000));

            return StepResult::STATUS_ERROR;
        }

        $tools = $this->anthropicTools($session->tools ?? [], $allow);
        if ([] === $tools) {
            $result->setStatus(StepResult::STATUS_ERROR);
            $result->setError('The MCP server exposed no usable tools' . ([] !== $allow ? ' matching the allow-list.' : '.'));
            $result->setDurationMs((int) round((microtime(true) - $started) * 1000));

            return StepResult::STATUS_ERROR;
        }

        // The session and its tool list survive an attempt; the conversation does
        // not. Retry re-runs the whole agent from the original prompt — which on
        // a non-deterministic step means a DIFFERENT trace, so it is off unless
        // the step asks for it. Reach for run_dataset repeats to measure how
        // often an agent gets it right; retry is for the transient 429 that
        // would otherwise end the step on turn one.
        [$maxAttempts, $retryDelay] = $this->retrySpec($step);
        $attempt = 0;
        $status = StepResult::STATUS_FAILED;

        while ($attempt < $maxAttempts) {
            ++$attempt;
            $attemptStarted = microtime(true);

            $messages = [['role' => 'user', 'content' => $prompt]];
            $toolCalls = [];
            $text = '';
            $turns = 0;
            $stopReason = '';
            $inputTokens = $outputTokens = 0;

            while ($turns < $maxTurns) {
                ++$turns;

                try {
                    $reply = $this->claude->converse($messages, $tools, $system, $maxTokens, '' !== $model ? $model : null);
                } catch (\Throwable $e) {
                    $result->setStatus(StepResult::STATUS_ERROR);
                    $result->setError($e->getMessage());
                    $result->setDurationMs((int) round((microtime(true) - $started) * 1000));
                    if ($attempt < $maxAttempts) {
                        usleep($retryDelay * 1000);
                        continue 2;
                    }
                    $result->setAttempts($attempt);

                    return StepResult::STATUS_ERROR;
                }

                $stopReason = (string) ($reply['stop_reason'] ?? '');
                $inputTokens += (int) ($reply['usage']['input_tokens'] ?? 0);
                $outputTokens += (int) ($reply['usage']['output_tokens'] ?? 0);
                $content = (array) ($reply['content'] ?? []);

                $text = '';
                $uses = [];
                foreach ($content as $block) {
                    $type = \is_array($block) ? ($block['type'] ?? '') : '';
                    if ('text' === $type) {
                        $text .= (string) ($block['text'] ?? '');
                    } elseif ('tool_use' === $type) {
                        $uses[] = $block;
                    }
                }

                // Verbatim: thinking and tool_use blocks have to go back exactly as
                // they came, or the next turn is answering a different conversation.
                $messages[] = ['role' => 'assistant', 'content' => $content];

                if ([] === $uses) {
                    break;
                }

                $results = [];
                foreach ($uses as $use) {
                    $name = (string) ($use['name'] ?? '');
                    $input = (array) ($use['input'] ?? []);
                    try {
                        $call = $this->mcp->callTool($session, $name, $input);
                        $isError = $call['isError'];
                        $output = '' !== $call['text'] ? $call['text'] : (string) json_encode($call['result']);
                    } catch (\Throwable $e) {
                        $isError = true;
                        $output = 'Tool call failed: ' . $e->getMessage();
                    }

                    $toolCalls[] = ['name' => $name, 'input' => $input, 'isError' => $isError];
                    $results[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => (string) ($use['id'] ?? ''),
                        'content' => mb_substr($output, 0, 20000),
                        'is_error' => $isError,
                    ];
                }

                // Every result in ONE user message: splitting them teaches the model
                // to stop calling tools in parallel.
                $messages[] = ['role' => 'user', 'content' => $results];
            }

            $names = array_column($toolCalls, 'name');
            $durationMs = (microtime(true) - $attemptStarted) * 1000;
            $data = [
                'text' => $text,
                'toolCalls' => $toolCalls,
                'toolNames' => $names,
                'toolSequence' => implode(' › ', $names),
                'toolCallCount' => \count($toolCalls),
                'turns' => $turns,
                // True when the model was still working when the budget ran out —
                // worth asserting false, or the trace is only half the story.
                'truncated' => $turns >= $maxTurns && 'tool_use' === $stopReason,
                'stopReason' => $stopReason,
                'server' => $session->serverName,
                'inputTokens' => $inputTokens,
                'outputTokens' => $outputTokens,
                'durationMs' => (int) round($durationMs),
            ];

            $display = (string) json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            $result->setDurationMs((int) round($durationMs));
            $result->setResponseBody($this->truncate($display));
            $result->setError(null);
            $context['agentText'] = $text;
            $context['agentToolSequence'] = $data['toolSequence'];

            $status = $this->applyExtractionsAndAssertions($step, $result, $context, $data, $display, null, $durationMs, []);

            if (StepResult::STATUS_PASSED === $status) {
                break;
            }
            if ($attempt < $maxAttempts) {
                usleep($retryDelay * 1000);
            }
        }

        $result->setAttempts($attempt);

        return $status;
    }

    /**
     * MCP tool declarations in the shape the Messages API wants. Anything whose
     * name the API would reject is dropped rather than failing the whole request.
     *
     * @param array<int, array<string, mixed>> $mcpTools
     * @param string[]                         $allow    empty = every tool
     *
     * @return array<int, array<string, mixed>>
     */
    private function anthropicTools(array $mcpTools, array $allow): array
    {
        $out = [];
        foreach ($mcpTools as $tool) {
            $name = (string) ($tool['name'] ?? '');
            if (1 !== preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $name)) {
                continue;
            }
            if ([] !== $allow && !\in_array($name, $allow, true)) {
                continue;
            }
            $schema = $tool['inputSchema'] ?? $tool['input_schema'] ?? null;
            $out[] = [
                'name' => $name,
                'description' => (string) ($tool['description'] ?? ''),
                'input_schema' => $this->normalizeSchema(\is_array($schema) && [] !== $schema ? $schema : [], true),
            ];
        }

        return $out;
    }

    /**
     * Makes a JSON Schema survive json_encode.
     *
     * An empty PHP array encodes as `[]`, but `properties` must be an OBJECT —
     * and a tool that declares no arguments naturally ends up with an empty one
     * (Signal's own MCP server does exactly this). Left alone it sends
     * `"properties": []` and the Messages API rejects the request, so the
     * failure would land on the first server anyone points an agent step at.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function normalizeSchema(array $schema, bool $root = false): array
    {
        if ($root) {
            $schema['type'] ??= 'object';
        }

        // Only where `properties` belongs: the root (which must be an object
        // schema) and any node that already declares one. Adding the key to a
        // `{"type": "string"}` node would be wrong JSON Schema and would pad
        // every tool definition in every request for nothing.
        if ($root || \array_key_exists('properties', $schema)) {
            $properties = $schema['properties'] ?? [];
            if (!\is_array($properties) || [] === $properties) {
                $schema['properties'] = new \stdClass();
            } else {
                foreach ($properties as $key => $property) {
                    if (\is_array($property)) {
                        $properties[$key] = $this->normalizeSchema($property);
                    }
                }
                $schema['properties'] = $properties;
            }
        }

        // Sub-schemas hide under the composition keywords too, and one bad
        // `properties` anywhere makes the API reject the WHOLE request — so a
        // single such tool would break every agent step against that server,
        // not just calls to that tool.
        foreach (['anyOf', 'oneOf', 'allOf', 'prefixItems'] as $keyword) {
            if (!isset($schema[$keyword]) || !\is_array($schema[$keyword])) {
                continue;
            }
            foreach ($schema[$keyword] as $i => $sub) {
                if (\is_array($sub)) {
                    $schema[$keyword][$i] = $this->normalizeSchema($sub);
                }
            }
        }

        foreach (['$defs', 'definitions'] as $keyword) {
            if (!isset($schema[$keyword]) || !\is_array($schema[$keyword])) {
                continue;
            }
            if ([] === $schema[$keyword]) {
                $schema[$keyword] = new \stdClass();
                continue;
            }
            foreach ($schema[$keyword] as $name => $sub) {
                if (\is_array($sub)) {
                    $schema[$keyword][$name] = $this->normalizeSchema($sub);
                }
            }
        }

        foreach (['items', 'additionalProperties'] as $keyword) {
            if (!isset($schema[$keyword]) || !\is_array($schema[$keyword])) {
                continue;
            }
            // An empty one is an object position too ({} = anything goes).
            $schema[$keyword] = [] === $schema[$keyword]
                ? new \stdClass()
                : $this->normalizeSchema($schema[$keyword]);
        }

        return $schema;
    }

    /**
     * @param array<string, string> $context
     *
     * @return array<string, string>
     */
    private function resolveHeaders(mixed $headers, array $context): array
    {
        $out = [];
        foreach ((array) $headers as $name => $value) {
            if (\is_string($name) && \is_scalar($value)) {
                $out[strtolower($name)] = (string) $this->resolver->resolve((string) $value, $context);
            }
        }

        return $out;
    }

    /**
     * Resolves {{vars}} in every string of a nested structure, so a tool argument
     * can carry a value an earlier step extracted however deep it sits.
     *
     * @param array<string, string> $context
     */
    private function resolveDeep(mixed $value, array $context): mixed
    {
        if (\is_string($value)) {
            return $this->resolver->resolve($value, $context);
        }
        if (\is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->resolveDeep($v, $context);
            }

            return $out;
        }

        return $value;
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
        'contains' => 'contains', 'notContains' => 'notContains', 'matches' => 'matches',
        'exists' => 'exists', 'empty' => 'empty', 'notEmpty' => 'notEmpty',
        'judge' => 'judge',
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
     * @return array{label: string, key: string, ok: bool, actual: string}
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
                return ['label' => 'response matches the JSON schema', 'key' => 'schema', 'ok' => false, 'actual' => 'invalid schema definition'];
            }
            $violations = $this->jsonSchema->validate($schema, $decoded);

            return [
                'label' => 'response matches the JSON schema',
                'key' => 'schema',
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

        // Identifies the assertion by WHAT IT TARGETS, leaving the expected value
        // out. The label carries the resolved value ("name == Test"), so an
        // env-driven assertion renders differently on every dataset row and
        // grouping by label would count one check as many. This key is the same
        // on every row, which is what lets EvalReport tell a check that is
        // deterministic-but-wrong-for-some-inputs from one that is truly flaky.
        $key = $kind . ':' . $target . ':' . $op;

        // A judge verdict is worthless without its reason, so it returns early
        // and keeps the full sentence instead of the 120-char actual-value clip.
        if ('judge' === $op) {
            $verdict = $found
                ? $this->judge->judge($actual, $expected)
                : ['pass' => false, 'reason' => sprintf('%s is not present in the response.', $target)];

            return [
                'label' => sprintf('%s judge %s', $target, $expected),
                'key' => $key,
                'ok' => $verdict['pass'],
                'actual' => $verdict['reason'],
            ];
        }

        $ok = $this->applyOp($op, $found, $actual, $expected);

        $label = \in_array($op, ['exists', 'empty', 'notEmpty'], true)
            ? sprintf('%s %s', $target, $token)
            : sprintf('%s %s %s', $target, $token, $expected);

        return ['label' => $label, 'key' => $key, 'ok' => $ok, 'actual' => mb_substr($actual, 0, 120)];
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
            // The absence of something is a first-class thing to assert — "this
            // agent must never have called the destructive tool" is exactly this.
            // A missing target passes (what is not there contains nothing), but an
            // EMPTY expected fails: an unconfigured assertion must never be the
            // reason a test is green.
            'notContains' => '' !== $expected && !($found && str_contains($actual, $expected)),
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
