<?php

namespace App\Service;

use App\Entity\Workspace;
use Doctrine\DBAL\Connection;

/**
 * Numbers behind the workspace overview: the metric strip, the coverage card
 * and the health pill.
 *
 * Read-only aggregates over flow_run / flow_step, kept as native SQL because
 * they are window-and-bucket shaped and never hydrate an entity.
 */
final class WorkspaceStats
{
    /** How many daily buckets the sparklines show. */
    public const SPARK_DAYS = 12;

    /** Comparison window for the deltas, in days. */
    private const WINDOW_DAYS = 7;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * The four cards of the metric strip.
     *
     * @return list<array{key:string, label:string, value:string, unit:string,
     *                    delta:?int, deltaGood:?bool, spark:list<float>, tone:string}>
     */
    public function metrics(Workspace $workspace): array
    {
        $ws = (string) $workspace->getId();
        $daily = $this->dailyRuns($ws);           // [ymd => [finished, passed, p95]]
        $spark = $this->sparkSeries($daily);

        [$passNow, $passPrev] = $this->windowPassRate($ws);
        [$p95Now, $p95Prev] = $this->windowP95($ws);
        [$runsNow, $runsPrev] = $this->windowRuns($ws);
        $failing = $this->failingFlows($ws);

        return [
            [
                'key' => 'pass',
                'label' => 'Pass rate',
                'value' => null === $passNow ? '—' : (string) $passNow,
                'unit' => null === $passNow ? '' : '%',
                'delta' => $this->delta($passNow, $passPrev),
                'deltaText' => $this->signed($this->delta($passNow, $passPrev)),
                'deltaGood' => true,      // higher is better
                'spark' => $spark['pass'],
                'tone' => 'pass',
            ],
            [
                'key' => 'p95',
                'label' => 'p95 duration',
                'value' => null === $p95Now ? '—' : $this->humanMs($p95Now),
                'unit' => null === $p95Now ? '' : ($p95Now >= 1000 ? 's' : 'ms'),
                'delta' => $this->delta($p95Now, $p95Prev),
                // a raw millisecond diff reads as noise in a 6-character chip
                'deltaText' => $this->signedDuration($this->delta($p95Now, $p95Prev)),
                'deltaGood' => false,     // lower is better
                'spark' => $spark['p95'],
                'tone' => 'teal',
            ],
            [
                'key' => 'runs',
                'label' => 'Runs 24h',
                'value' => (string) $runsNow,
                'unit' => '',
                'delta' => $this->delta($runsNow, $runsPrev),
                'deltaText' => $this->signed($this->delta($runsNow, $runsPrev)),
                'deltaGood' => true,
                'spark' => $spark['runs'],
                'tone' => '',
            ],
            [
                'key' => 'failing',
                'label' => 'Failing flows',
                'value' => (string) $failing,
                'unit' => '',
                'delta' => null,          // a point-in-time count; no meaningful trend
                'deltaText' => null,
                'deltaGood' => null,
                'spark' => $spark['fail'],
                'tone' => 'fail',
            ],
        ];
    }

    /**
     * "How complete is the testing setup" — four ratios with a bar each.
     *
     * @return list<array{label:string, done:int, total:int, pct:int}>
     */
    public function coverage(Workspace $workspace): array
    {
        $ws = (string) $workspace->getId();

        $requests = (int) $this->db->fetchOne(
            'SELECT count(r.id) FROM api_request r
               JOIN api_collection c ON c.id = r.collection_id
              WHERE c.workspace_id = :ws', ['ws' => $ws]);

        $inFlow = (int) $this->db->fetchOne(
            'SELECT count(DISTINCT s.api_request_id) FROM flow_step s
               JOIN test_flow f ON f.id = s.flow_id
              WHERE f.workspace_id = :ws AND s.api_request_id IS NOT NULL', ['ws' => $ws]);

        $withExample = (int) $this->db->fetchOne(
            'SELECT count(DISTINCT e.api_request_id) FROM response_example e
               JOIN api_request r ON r.id = e.api_request_id
               JOIN api_collection c ON c.id = r.collection_id
              WHERE c.workspace_id = :ws', ['ws' => $ws]);

        $steps = (int) $this->db->fetchOne(
            'SELECT count(s.id) FROM flow_step s
               JOIN test_flow f ON f.id = s.flow_id
              WHERE f.workspace_id = :ws', ['ws' => $ws]);

        $asserted = (int) $this->db->fetchOne(
            "SELECT count(s.id) FROM flow_step s
               JOIN test_flow f ON f.id = s.flow_id
              WHERE f.workspace_id = :ws AND json_array_length(s.assertions) > 0", ['ws' => $ws]);

        $flows = (int) $this->db->fetchOne(
            'SELECT count(*) FROM test_flow WHERE workspace_id = :ws', ['ws' => $ws]);

        // A flow counts as scheduled when a schedule points at it, or at a
        // suite it belongs to.
        $scheduled = (int) $this->db->fetchOne(
            'SELECT count(DISTINCT f.id) FROM test_flow f
               LEFT JOIN schedule sf ON sf.flow_id = f.id AND sf.enabled
               LEFT JOIN flow_group_item gi ON gi.flow_id = f.id
               LEFT JOIN schedule sg ON sg.flow_group_id = gi.flow_group_id AND sg.enabled
              WHERE f.workspace_id = :ws AND (sf.id IS NOT NULL OR sg.id IS NOT NULL)', ['ws' => $ws]);

        return [
            $this->ratio('Requests in a flow', $inFlow, $requests),
            $this->ratio('Steps with an assertion', $asserted, $steps),
            $this->ratio('Requests with an example', $withExample, $requests),
            $this->ratio('Flows on a schedule', $scheduled, $flows),
        ];
    }

    /**
     * healthy — nothing failing · degraded — some flows red · quiet — never run.
     */
    public function health(Workspace $workspace): string
    {
        $ws = (string) $workspace->getId();
        $finished = (int) $this->db->fetchOne(
            "SELECT count(*) FROM flow_run r JOIN test_flow f ON f.id = r.flow_id
              WHERE f.workspace_id = :ws AND r.status IN ('passed','failed','error')", ['ws' => $ws]);

        if (0 === $finished) {
            return 'quiet';
        }

        return $this->failingFlows($ws) > 0 ? 'degraded' : 'healthy';
    }

    // ---------------------------------------------------------------- helpers

    /** @return array{label:string, done:int, total:int, pct:int} */
    private function ratio(string $label, int $done, int $total): array
    {
        return [
            'label' => $label,
            'done' => $done,
            'total' => $total,
            'pct' => $total > 0 ? (int) round($done / $total * 100) : 0,
        ];
    }

    /**
     * One row per day for the last SPARK_DAYS days, oldest first.
     *
     * @return list<array{d:string, finished:int, passed:int, runs:int, failed:int, p95:?float}>
     */
    private function dailyRuns(string $ws): array
    {
        $sql = "
            SELECT to_char(d.day, 'YYYY-MM-DD') AS d,
                   count(r.id) FILTER (WHERE r.status IN ('passed','failed','error')) AS finished,
                   count(r.id) FILTER (WHERE r.status = 'passed')                     AS passed,
                   count(r.id) FILTER (WHERE r.status IN ('failed','error'))          AS failed,
                   count(r.id)                                                        AS runs,
                   percentile_cont(0.95) WITHIN GROUP (
                       ORDER BY EXTRACT(EPOCH FROM (r.finished_at - r.created_at)) * 1000
                   ) FILTER (WHERE r.finished_at IS NOT NULL)                         AS p95
              FROM generate_series(
                       (CURRENT_DATE - (:days::int - 1))::timestamp, CURRENT_DATE::timestamp, interval '1 day'
                   ) AS d(day)
              LEFT JOIN test_flow f ON f.workspace_id = :ws
              LEFT JOIN flow_run r  ON r.flow_id = f.id AND r.created_at >= d.day AND r.created_at < d.day + interval '1 day'
             GROUP BY d.day
             ORDER BY d.day";

        return $this->db->fetchAllAssociative($sql, ['ws' => $ws, 'days' => self::SPARK_DAYS]);
    }

    /**
     * Turns the daily rows into bar heights per series.
     *
     * A day with no runs is null, not 0 — "we did not test" and "nothing
     * passed" are different facts and the chart must not conflate them.
     *
     * @param  list<array<string, mixed>> $daily
     * @return array{pass:list<?float>, p95:list<?float>, runs:list<?float>, fail:list<?float>}
     */
    private function sparkSeries(array $daily): array
    {
        $pass = $p95 = $runs = $fail = [];
        foreach ($daily as $row) {
            $finished = (int) $row['finished'];
            $total = (int) $row['runs'];
            $pass[] = $finished > 0 ? (int) $row['passed'] / $finished : null;
            $p95[] = null === $row['p95'] ? null : (float) $row['p95'];
            $runs[] = $total > 0 ? (float) $total : null;
            $fail[] = $total > 0 ? (float) (int) $row['failed'] : null;
        }

        return [
            'pass' => $this->normalise($pass, 1.0),   // already a ratio
            'p95' => $this->normalise($p95),
            'runs' => $this->normalise($runs),
            'fail' => $this->normalise($fail),
        ];
    }

    /**
     * @param  list<?float> $values
     * @return list<?float> 0..1, null preserved as "no data"
     */
    private function normalise(array $values, ?float $max = null): array
    {
        $present = array_filter($values, static fn (?float $v): bool => null !== $v);
        $max ??= $present ? max($present) : 0.0;
        if ($max <= 0) {
            return array_map(static fn (?float $v): ?float => null === $v ? null : 0.0, $values);
        }

        return array_map(
            static fn (?float $v): ?float => null === $v ? null : min(1.0, $v / $max),
            $values,
        );
    }

    /** @return array{0:?int, 1:?int} current and previous window, in percent */
    private function windowPassRate(string $ws): array
    {
        $sql = "
            SELECT count(*) FILTER (WHERE r.created_at >= now() - (:d::int || ' days')::interval)                AS n_now,
                   count(*) FILTER (WHERE r.created_at >= now() - (:d::int || ' days')::interval
                                      AND r.status = 'passed')                                                   AS p_now,
                   count(*) FILTER (WHERE r.created_at <  now() - (:d::int || ' days')::interval
                                      AND r.created_at >= now() - (:d2::int || ' days')::interval)               AS n_prev,
                   count(*) FILTER (WHERE r.created_at <  now() - (:d::int || ' days')::interval
                                      AND r.created_at >= now() - (:d2::int || ' days')::interval
                                      AND r.status = 'passed')                                                   AS p_prev
              FROM flow_run r JOIN test_flow f ON f.id = r.flow_id
             WHERE f.workspace_id = :ws AND r.status IN ('passed','failed','error')";

        $row = $this->db->fetchAssociative($sql, [
            'ws' => $ws, 'd' => self::WINDOW_DAYS, 'd2' => self::WINDOW_DAYS * 2,
        ]) ?: [];

        $pct = static fn ($n, $p) => (int) $n > 0 ? (int) round((int) $p / (int) $n * 100) : null;

        return [$pct($row['n_now'] ?? 0, $row['p_now'] ?? 0), $pct($row['n_prev'] ?? 0, $row['p_prev'] ?? 0)];
    }

    /** @return array{0:?int, 1:?int} current and previous window p95, in ms */
    private function windowP95(string $ws): array
    {
        $sql = "
            SELECT percentile_cont(0.95) WITHIN GROUP (ORDER BY ms) FILTER (WHERE recent)  AS now_ms,
                   percentile_cont(0.95) WITHIN GROUP (ORDER BY ms) FILTER (WHERE NOT recent) AS prev_ms
              FROM (
                SELECT EXTRACT(EPOCH FROM (r.finished_at - r.created_at)) * 1000 AS ms,
                       r.created_at >= now() - (:d::int || ' days')::interval    AS recent
                  FROM flow_run r JOIN test_flow f ON f.id = r.flow_id
                 WHERE f.workspace_id = :ws
                   AND r.finished_at IS NOT NULL
                   AND r.created_at >= now() - (:d2::int || ' days')::interval
              ) t";

        $row = $this->db->fetchAssociative($sql, [
            'ws' => $ws, 'd' => self::WINDOW_DAYS, 'd2' => self::WINDOW_DAYS * 2,
        ]) ?: [];

        return [
            isset($row['now_ms']) ? (int) round((float) $row['now_ms']) : null,
            isset($row['prev_ms']) ? (int) round((float) $row['prev_ms']) : null,
        ];
    }

    /** @return array{0:int, 1:int} runs in the last 24h and the 24h before that */
    private function windowRuns(string $ws): array
    {
        $sql = "
            SELECT count(*) FILTER (WHERE r.created_at >= now() - interval '24 hours')  AS n_now,
                   count(*) FILTER (WHERE r.created_at <  now() - interval '24 hours'
                                      AND r.created_at >= now() - interval '48 hours')  AS n_prev
              FROM flow_run r JOIN test_flow f ON f.id = r.flow_id
             WHERE f.workspace_id = :ws";

        $row = $this->db->fetchAssociative($sql, ['ws' => $ws]) ?: [];

        return [(int) ($row['n_now'] ?? 0), (int) ($row['n_prev'] ?? 0)];
    }

    /** Flows whose most recent finished run did not pass. */
    private function failingFlows(string $ws): int
    {
        $sql = "
            SELECT count(*) FROM (
                SELECT DISTINCT ON (r.flow_id) r.status
                  FROM flow_run r JOIN test_flow f ON f.id = r.flow_id
                 WHERE f.workspace_id = :ws AND r.status IN ('passed','failed','error')
                 ORDER BY r.flow_id, r.created_at DESC
            ) last WHERE last.status <> 'passed'";

        return (int) $this->db->fetchOne($sql, ['ws' => $ws]);
    }

    private function delta(?int $now, ?int $prev): ?int
    {
        if (null === $now || null === $prev) {
            return null;
        }

        return $now - $prev;
    }

    /** 412 -> "412", 1840 -> "1.8" (the unit switches to s alongside). */
    private function humanMs(int $ms): string
    {
        return $ms >= 1000 ? number_format($ms / 1000, 1) : (string) $ms;
    }

    private function signed(?int $n): ?string
    {
        return null === $n ? null : ($n > 0 ? '+' : '') . $n;
    }

    /** -28150 ms is unreadable in a chip; show "-28.2s". */
    private function signedDuration(?int $ms): ?string
    {
        if (null === $ms) {
            return null;
        }
        $sign = $ms > 0 ? '+' : '-';
        $abs = abs($ms);

        return $abs >= 1000
            ? $sign . number_format($abs / 1000, 1) . 's'
            : $sign . $abs . 'ms';
    }
}
