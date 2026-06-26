<?php

namespace App\Service;

use App\Entity\Environment;
use App\Entity\FlowRun;
use App\Entity\FlowStep;
use App\Entity\StepResult;
use App\Entity\TestFlow;
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

    public function __construct(
        private readonly RequestRunner $requestRunner,
        private readonly DbQueryRunner $dbQueryRunner,
        private readonly JsonPathExtractor $jsonPath,
        private readonly VariableResolver $resolver,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<string, string> $vars one-off variables merged over the environment
     */
    public function run(TestFlow $flow, ?Environment $environment, string $trigger = 'manual', array $vars = []): FlowRun
    {
        $run = $this->createRun($flow, $environment, $trigger, null, 0, []);

        return $this->executeInto($run, $flow, $environment, $vars);
    }

    /**
     * Runs the flow once per dataset row, each with that row's variables merged
     * into the context. Returns one FlowRun per iteration, grouped by a batch id.
     *
     * @param array<int, array<string, mixed>> $dataset
     *
     * @return FlowRun[]
     */
    public function runDataset(TestFlow $flow, ?Environment $environment, array $dataset, string $trigger = 'manual'): array
    {
        $batchId = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
        $runs = [];
        $i = 0;
        foreach ($dataset as $row) {
            $run = $this->createRun($flow, $environment, $trigger, $batchId, $i, \is_array($row) ? $row : []);
            $runs[] = $this->executeInto($run, $flow, $environment, $this->rowVars($row));
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
    public function createRun(TestFlow $flow, ?Environment $environment, string $trigger, ?string $batchId, int $iteration, array $iterationData): FlowRun
    {
        $run = new FlowRun();
        $run->setFlow($flow);
        $run->setTrigger($trigger);
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
        $passed = 0;
        $stopped = false;
        $sawError = false;
        $cancelled = false;
        $position = 0;

        foreach ($flow->getSteps() as $step) {
            /** @var FlowStep $step */
            if (!$stopped && $this->cancelRequested($run)) {
                $cancelled = true;
                $stopped = true;
            }

            $result = new StepResult();
            $result->setPosition($position++);
            $result->setLabel($step->getName());

            if ($stopped) {
                $result->setStatus(StepResult::STATUS_SKIPPED);
                $run->addStepResult($result);
                $this->em->flush();
                continue;
            }

            $outcome = match (true) {
                $step->isDelay() => $this->runDelayStep($step, $result),
                $step->isSetvar() => $this->runSetvarStep($step, $result, $context),
                $step->isDb() => $this->runDbStep($step, $result, $context),
                default => $this->runHttpStep($step, $result, $context, $flow->getWorkspace()),
            };

            $run->addStepResult($result);

            if (StepResult::STATUS_PASSED === $outcome) {
                ++$passed;
            } else {
                if (StepResult::STATUS_ERROR === $outcome) {
                    $sawError = true;
                }
                $stopped = $flow->isStopOnFailure();
            }

            $run->setPassedSteps($passed);
            $this->em->flush();
        }

        $run->setPassedSteps($passed);
        $run->setFinishedAt(new \DateTimeImmutable());
        $run->setStatus(match (true) {
            $cancelled => FlowRun::STATUS_CANCELLED,
            $sawError => FlowRun::STATUS_ERROR,
            $passed === $run->getTotalSteps() => FlowRun::STATUS_PASSED,
            default => FlowRun::STATUS_FAILED,
        });
        $this->em->flush();

        return $run;
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
    private function runHttpStep(FlowStep $step, StepResult $result, array &$context, \App\Entity\Workspace $workspace): string
    {
        // Each step carries its own flow-owned request copy (independent of the collection).
        $apiRequest = $step->toTransientRequest();
        if ('' === trim($apiRequest->getUrl())) {
            $result->setStatus(StepResult::STATUS_ERROR);
            $result->setError('Adımın URL\'i boş.');

            return StepResult::STATUS_ERROR;
        }

        [$max, $delay] = $this->retrySpec($step);
        $attempt = 0;
        $status = StepResult::STATUS_FAILED;

        while ($attempt < $max) {
            ++$attempt;
            $response = $this->requestRunner->send($apiRequest, $context, $workspace);
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
    private function runDbStep(FlowStep $step, StepResult $result, array &$context): string
    {
        $connection = $step->getDbConnection();
        $result->setRequestMethod('DB');

        if (null === $connection) {
            $result->setStatus(StepResult::STATUS_ERROR);
            $result->setError('Bu adıma bağlı veritabanı bağlantısı silinmiş.');

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

        $result->setRequestUrl(\count($set) . ' değişken set edildi');
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
            $eval = $this->evaluate($assertion, $statusCode, $rawBody, $decoded, $durationMs, $headers);
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
    private function evaluate(array $assertion, ?int $statusCode, string $rawBody, mixed $decoded, float $durationMs, array $headers): array
    {
        $kind = $assertion['kind'] ?? '';
        $op = $assertion['op'] ?? 'eq';
        $expected = (string) ($assertion['expected'] ?? '');
        $token = self::OP_TOKEN[$op] ?? $op;

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
            ? mb_substr($body, 0, self::MAX_BODY_SNAPSHOT) . "\n… (kısaltıldı)"
            : $body;
    }
}
