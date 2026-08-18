<?php

namespace App\Service\Notification;

use App\Entity\FlowGroupRun;
use App\Entity\FlowRun;
use App\Entity\NotificationDelivery;
use App\Entity\StepResult;
use App\Entity\TestFlow;
use App\Entity\Workspace;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Turns a finished run into one channel-agnostic payload. Every channel formats
 * this same array, and it is stored on the delivery row so a retry never has to
 * read the run again.
 */
class RunSummary
{
    /** How many failing items a message lists before it says "and N more". */
    private const MAX_FAILURES = 5;

    public function __construct(private readonly UrlGeneratorInterface $urls)
    {
    }

    /** @return array<string, mixed> */
    public function fromFlowRun(FlowRun $run): array
    {
        $flow = $run->getFlow();
        $workspace = $flow->getWorkspace();

        $failures = [];
        foreach ($run->getStepResults() as $result) {
            if (\in_array($result->getStatus(), [StepResult::STATUS_PASSED, StepResult::STATUS_SKIPPED], true)) {
                continue;
            }
            $failures[] = [
                'name' => sprintf('#%d %s', $result->getPosition() + 1, $result->getLabel()),
                'detail' => $this->stepDetail($result),
            ];
        }

        return [
            'event' => NotificationDelivery::EVENT_FLOW_RUN,
            'kind' => 'flow',
            'status' => $run->getStatus(),
            'title' => $flow->getName(),
            'workspace' => $workspace->getName(),
            'environment' => $run->getEnvironmentName(),
            'trigger' => $run->getTrigger(),
            'unit' => 'step',
            'passed' => $run->getPassedSteps(),
            'total' => $run->getTotalSteps(),
            'durationMs' => $run->getDurationMs(),
            'runId' => (string) $run->getId(),
            'finishedAt' => $run->getFinishedAt()?->format(\DATE_ATOM),
            'failures' => \array_slice($failures, 0, self::MAX_FAILURES),
            'moreFailures' => max(0, \count($failures) - self::MAX_FAILURES),
            'url' => $this->absolute('app_flow_run_show', [
                'workspace' => (string) $workspace->getId(),
                'flow' => (string) $flow->getId(),
                'run' => (string) $run->getId(),
            ]),
        ];
    }

    /**
     * @param FlowRun[] $runs the flow runs of the batch
     *
     * @return array<string, mixed>
     */
    public function fromSuiteRun(FlowGroupRun $groupRun, array $runs): array
    {
        $group = $groupRun->getFlowGroup();
        $workspace = $group->getWorkspace();

        $failures = [];
        $passed = 0;
        $environment = null;
        foreach ($runs as $run) {
            $environment ??= $run->getEnvironmentName();
            if (FlowRun::STATUS_PASSED === $run->getStatus()) {
                ++$passed;
                continue;
            }
            $failures[] = [
                'name' => $run->getFlow()->getName(),
                'detail' => $this->firstFailureDetail($run),
            ];
        }

        return [
            'event' => NotificationDelivery::EVENT_SUITE_RUN,
            'kind' => 'suite',
            'status' => $groupRun->getStatus(),
            'title' => $group->getName(),
            'workspace' => $workspace->getName(),
            'environment' => $environment,
            'trigger' => $groupRun->getTrigger(),
            'unit' => 'flow',
            'passed' => $passed,
            'total' => \count($runs),
            'durationMs' => null === $groupRun->getFinishedAt()
                ? null
                : (int) (($groupRun->getFinishedAt()->format('U.u') - $groupRun->getCreatedAt()->format('U.u')) * 1000),
            'runId' => $groupRun->getBatchId(),
            'finishedAt' => $groupRun->getFinishedAt()?->format(\DATE_ATOM),
            'failures' => \array_slice($failures, 0, self::MAX_FAILURES),
            'moreFailures' => max(0, \count($failures) - self::MAX_FAILURES),
            'url' => $this->absolute('app_flow_group_run_show', [
                'workspace' => (string) $workspace->getId(),
                'group' => (string) $group->getId(),
                'batchId' => $groupRun->getBatchId(),
            ]),
        ];
    }

    /**
     * A data-driven batch: one message for the whole dataset, listing the rows
     * that broke rather than the steps of a single run.
     *
     * @param FlowRun[] $runs
     *
     * @return array<string, mixed>
     */
    public function fromDatasetBatch(TestFlow $flow, string $batchId, array $runs): array
    {
        $workspace = $flow->getWorkspace();

        $failures = [];
        $passed = 0;
        $durationMs = 0;
        $environment = null;
        foreach ($runs as $run) {
            $environment ??= $run->getEnvironmentName();
            $durationMs += $run->getDurationMs() ?? 0;
            if (FlowRun::STATUS_PASSED === $run->getStatus()) {
                ++$passed;
                continue;
            }
            $failures[] = [
                'name' => sprintf('row %d', $run->getIteration() + 1),
                'detail' => $this->firstFailureDetail($run),
            ];
        }

        return [
            'event' => NotificationDelivery::EVENT_FLOW_RUN,
            'kind' => 'dataset',
            'status' => [] === $failures ? FlowRun::STATUS_PASSED : FlowRun::STATUS_FAILED,
            'title' => $flow->getName(),
            'workspace' => $workspace->getName(),
            'environment' => $environment,
            'trigger' => $runs[0]->getTrigger(),
            'unit' => 'row',
            'passed' => $passed,
            'total' => \count($runs),
            'durationMs' => $durationMs,
            'runId' => $batchId,
            'finishedAt' => end($runs)->getFinishedAt()?->format(\DATE_ATOM),
            'failures' => \array_slice($failures, 0, self::MAX_FAILURES),
            'moreFailures' => max(0, \count($failures) - self::MAX_FAILURES),
            'url' => $this->absolute('app_flow_batch_show', [
                'workspace' => (string) $workspace->getId(),
                'flow' => (string) $flow->getId(),
                'batchId' => $batchId,
            ]),
        ];
    }

    /**
     * The payload behind the "send a test message" button.
     *
     * @return array<string, mixed>
     */
    public function testMessage(Workspace $workspace): array
    {
        return [
            'event' => NotificationDelivery::EVENT_TEST,
            'kind' => 'test',
            'status' => FlowRun::STATUS_PASSED,
            'title' => 'Signal test message',
            'workspace' => $workspace->getName(),
            'environment' => null,
            'trigger' => 'manual',
            'unit' => 'step',
            'passed' => 1,
            'total' => 1,
            'durationMs' => 0,
            'runId' => null,
            'finishedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'failures' => [],
            'moreFailures' => 0,
            'url' => $this->absolute('app_workspace_show', ['id' => (string) $workspace->getId()]),
        ];
    }

    /** The one line that explains why a step is red. */
    private function stepDetail(StepResult $result): string
    {
        foreach ($result->getAssertionResults() as $assertion) {
            if (($assertion['ok'] ?? true)) {
                continue;
            }
            $label = (string) ($assertion['label'] ?? 'assertion');
            $actual = $assertion['actual'] ?? null;

            return null === $actual || '' === $actual
                ? $label
                : sprintf('%s → got %s', $label, mb_substr((string) $actual, 0, 120));
        }

        $error = $result->getError();
        if (null !== $error && '' !== $error) {
            return mb_substr($error, 0, 160);
        }

        return sprintf('status %s', $result->getStatus());
    }

    private function firstFailureDetail(FlowRun $run): string
    {
        foreach ($run->getStepResults() as $result) {
            if (\in_array($result->getStatus(), [StepResult::STATUS_PASSED, StepResult::STATUS_SKIPPED], true)) {
                continue;
            }

            return sprintf('#%d %s · %s', $result->getPosition() + 1, $result->getLabel(), $this->stepDetail($result));
        }

        return sprintf('%d/%d steps passed', $run->getPassedSteps(), $run->getTotalSteps());
    }

    /** @param array<string, mixed> $params */
    private function absolute(string $route, array $params): string
    {
        return $this->urls->generate($route, $params, UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
