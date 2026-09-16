<?php

namespace App\Service;

use App\Entity\FlowRun;

/**
 * Turns one data-driven batch into a verdict about a flow whose outcome is not
 * deterministic — an LLM step, an agent's tool choice, anything graded by the
 * judge operator.
 *
 * A single green run of such a flow means almost nothing; the question is how
 * OFTEN it is green. So a batch run with repeats is read on two axes:
 *
 *   - per ROW, whether the flow is reliably right (stable-pass), reliably wrong
 *     (stable-fail) or undecided (flaky) — the same distinction FlakinessSentry
 *     makes over time, made here over repeats of the same input;
 *   - per CHECK, which individual assertion is the unreliable one. This is the
 *     part that pays for itself: "row 3 fails sometimes" sends you reading logs,
 *     "the judge on `text` passes 6/10, sample reason: …" names the culprit.
 *
 * Nothing here calls a model. The report is arithmetic over results that have
 * already been recorded, so it costs nothing and cannot itself be flaky.
 */
class EvalReport
{
    /** Checks listed in the report; enough to act on, short of a dump. */
    private const MAX_CHECKS = 20;

    public const STABLE_PASS = 'stable-pass';
    public const STABLE_FAIL = 'stable-fail';
    public const FLAKY = 'flaky';
    /** Deterministic per input, but wrong for some of them — a bug, not flakiness. */
    public const INPUT_DEPENDENT = 'input-dependent';

    /**
     * @param FlowRun[] $runs every run of one batch
     *
     * @return array{
     *   rows: int, repeats: int, runs: int, runsPassed: int, passRate: float,
     *   stablePassRows: int, flakyRows: int, stableFailRows: int,
     *   anyPassRows: int, allPassRows: int,
     *   rowDetail: list<array{index: int, data: array<string, mixed>, runs: int, passed: int, passRate: float, verdict: string}>,
     *   checks: list<array{label: string, passed: int, total: int, passRate: float, verdict: string, flakyRows: int, failedRows: int, sample: string}>
     * }
     */
    public function of(array $runs): array
    {
        /** @var array<int, FlowRun[]> $byRow */
        $byRow = [];
        foreach ($runs as $run) {
            $byRow[$run->getIteration()][] = $run;
        }
        ksort($byRow);

        $rowDetail = [];
        $stablePass = $flaky = $stableFail = 0;
        $repeats = 0;
        $runsPassed = 0;

        foreach ($byRow as $index => $rowRuns) {
            $total = \count($rowRuns);
            $passed = \count(array_filter($rowRuns, static fn (FlowRun $r) => FlowRun::STATUS_PASSED === $r->getStatus()));
            $repeats = max($repeats, $total);
            $runsPassed += $passed;

            $verdict = match (true) {
                $passed === $total => self::STABLE_PASS,
                0 === $passed => self::STABLE_FAIL,
                default => self::FLAKY,
            };
            match ($verdict) {
                self::STABLE_PASS => ++$stablePass,
                self::STABLE_FAIL => ++$stableFail,
                default => ++$flaky,
            };

            $rowDetail[] = [
                'index' => $index,
                'data' => $rowRuns[0]->getIterationData(),
                'runs' => $total,
                'passed' => $passed,
                'passRate' => $this->rate($passed, $total),
                'verdict' => $verdict,
            ];
        }

        $rows = \count($byRow);
        $runCount = \count($runs);

        return [
            'rows' => $rows,
            'repeats' => $repeats,
            'runs' => $runCount,
            'runsPassed' => $runsPassed,
            // What a SINGLE attempt is worth — the number to quote when someone
            // asks "does this test pass?" about a non-deterministic flow.
            'passRate' => $this->rate($runsPassed, $runCount),
            'stablePassRows' => $stablePass,
            'flakyRows' => $flaky,
            'stableFailRows' => $stableFail,
            // pass@k and all-pass: the optimistic and pessimistic readings of
            // the same batch. A wide gap between them IS the flakiness.
            'anyPassRows' => $stablePass + $flaky,
            'allPassRows' => $stablePass,
            'rowDetail' => $rowDetail,
            // Only worth tallying when rows were actually repeated: with one run
            // per row a failing check is already the row's verdict, and walking
            // every run's step results to say so would load the whole batch's
            // results on a page that does not show them.
            'checks' => $repeats > 1 ? $this->checks($runs) : [],
        ];
    }

    /**
     * Every assertion that failed at least once, judged WITHIN each row first.
     *
     * That distinction is the whole point of the table. A check that passes in
     * rows 1-2 and fails in row 3 is not unreliable — it is deterministic and
     * broken for one input, and calling it "flaky" would send someone hunting a
     * race that does not exist. Only a check that gives different answers to the
     * SAME input is flaky. So the tally is per (check, row), and the row verdicts
     * are what get aggregated:
     *
     *   flaky            — flips within at least one row: genuine non-determinism
     *   stable-fail      — never passes anywhere: an ordinary bug
     *   input-dependent  — consistent per row, but fails for some inputs
     *
     * `sample` carries one real failure message — for a judge assertion that is
     * the model's own stated reason, which is usually the whole diagnosis.
     *
     * Reads each run's step results, so it is only called for a repeated batch.
     *
     * @param FlowRun[] $runs
     *
     * @return list<array{label: string, passed: int, total: int, passRate: float, verdict: string, flakyRows: int, failedRows: int, sample: string}>
     */
    private function checks(array $runs): array
    {
        /** @var array<string, array<int, array{passed: int, total: int}>> $tally */
        $tally = [];
        /** @var array<string, string> $samples */
        $samples = [];
        /** @var array<string, string> $labels */
        $labels = [];

        foreach ($runs as $run) {
            $row = $run->getIteration();
            foreach ($run->getStepResults() as $result) {
                foreach ($result->getAssertionResults() as $assertion) {
                    // Keyed on the assertion's identity, not its rendered text:
                    // "name == {{expected}}" reads differently on every row, and
                    // grouping by the rendered label would make one check look
                    // like several. (Runs recorded before the key existed fall
                    // back to the label, which is the old behaviour.)
                    $key = (string) ($assertion['key'] ?? $assertion['label'] ?? '');
                    $key = $result->getLabel() . ' › ' . $key;
                    $labels[$key] ??= $result->getLabel() . ' › ' . (string) ($assertion['label'] ?? '');
                    $tally[$key][$row] ??= ['passed' => 0, 'total' => 0];
                    ++$tally[$key][$row]['total'];
                    if ($assertion['ok'] ?? false) {
                        ++$tally[$key][$row]['passed'];
                    } elseif (!isset($samples[$key])) {
                        $samples[$key] = (string) ($assertion['actual'] ?? '');
                        // Prefer showing the variant that actually failed.
                        $labels[$key] = $result->getLabel() . ' › ' . (string) ($assertion['label'] ?? '');
                    }
                }
            }
        }

        $checks = [];
        foreach ($tally as $key => $rows) {
            $passed = array_sum(array_column($rows, 'passed'));
            $total = array_sum(array_column($rows, 'total'));
            if ($passed === $total) {
                continue;
            }

            $flakyRows = $failedRows = $passedRows = 0;
            foreach ($rows as $r) {
                if (0 === $r['passed']) {
                    ++$failedRows;
                } elseif ($r['passed'] === $r['total']) {
                    ++$passedRows;
                } else {
                    ++$flakyRows;
                }
            }

            $checks[] = [
                'label' => $labels[$key] ?? $key,
                'passed' => $passed,
                'total' => $total,
                'passRate' => $this->rate($passed, $total),
                'verdict' => match (true) {
                    $flakyRows > 0 => self::FLAKY,
                    0 === $passedRows => self::STABLE_FAIL,
                    default => self::INPUT_DEPENDENT,
                },
                'flakyRows' => $flakyRows,
                'failedRows' => $failedRows,
                'sample' => $samples[$key] ?? '',
            ];
        }

        // Outright bugs first, then the non-determinism the repeats were for,
        // then checks that only particular inputs break; worst rate within each.
        $order = [self::STABLE_FAIL => 0, self::FLAKY => 1, self::INPUT_DEPENDENT => 2];
        usort($checks, static fn (array $a, array $b) => [$order[$a['verdict']], $a['passRate']] <=> [$order[$b['verdict']], $b['passRate']]);

        return \array_slice($checks, 0, self::MAX_CHECKS);
    }

    private function rate(int $passed, int $total): float
    {
        return $total > 0 ? round($passed / $total, 4) : 0.0;
    }
}
