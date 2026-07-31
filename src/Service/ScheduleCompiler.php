<?php

namespace App\Service;

use App\Entity\Schedule;
use Cron\CronExpression;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns a schedule's structured rules into cron expressions, and back into a
 * sentence a person can read.
 *
 * Rules are stored structured rather than as raw cron so the editor can be a
 * form instead of a text box, but the evaluation still goes through
 * dragonmantank/cron-expression — no home-grown "is it due yet" arithmetic.
 *
 * A rule looks like:
 *   [
 *     'days'      => [1,2],                 // ISO-8601 1=Mon … 7=Sun; [] = every day
 *     'monthDays' => [1,15],                // [] = every day of the month
 *     'mode'      => 'at' | 'every',
 *     'at'        => ['09:00','18:30'],     // mode=at
 *     'n'         => 2,                     // mode=every
 *     'unit'      => 'minute' | 'hour',     // mode=every
 *     'from'      => '09:00', 'to' => '18:00',  // mode=every, optional window
 *   ]
 */
final class ScheduleCompiler
{
    public const UNITS = ['minute', 'hour'];

    /** ISO-8601 day numbers in the order a week is shown. */
    public const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /**
     * Coerces submitted input into a valid rule, or returns null if it says
     * nothing runnable.
     *
     * @param  array<string, mixed>      $raw
     * @return array<string, mixed>|null
     */
    public function normaliseRule(array $raw): ?array
    {
        $mode = (string) ($raw['mode'] ?? 'at');
        if (!\in_array($mode, ['at', 'every', 'cron'], true)) {
            $mode = 'at';
        }

        // Escape hatch: a raw cron expression, also how pre-existing per-flow
        // schedules were carried over.
        if ('cron' === $mode) {
            $expr = trim((string) ($raw['expr'] ?? ''));

            return CronExpression::isValidExpression($expr) ? ['mode' => 'cron', 'expr' => $expr, 'days' => [], 'monthDays' => []] : null;
        }

        $days = array_values(array_unique(array_filter(
            array_map('intval', (array) ($raw['days'] ?? [])),
            static fn (int $d): bool => $d >= 1 && $d <= 7,
        )));
        sort($days);

        $monthDays = array_values(array_unique(array_filter(
            array_map('intval', (array) ($raw['monthDays'] ?? [])),
            static fn (int $d): bool => $d >= 1 && $d <= 31,
        )));
        sort($monthDays);

        $rule = ['days' => $days, 'monthDays' => $monthDays, 'mode' => $mode];

        if ('at' === $mode) {
            $times = [];
            foreach ((array) ($raw['at'] ?? []) as $t) {
                $hm = $this->parseTime((string) $t);
                if (null !== $hm) {
                    $times[] = $hm;
                }
            }
            $times = array_values(array_unique($times));
            sort($times);
            if (!$times) {
                return null;   // "run at" with no time is not a schedule
            }
            $rule['at'] = $times;

            return $rule;
        }

        $n = max(1, (int) ($raw['n'] ?? 1));
        $unit = \in_array($raw['unit'] ?? 'hour', self::UNITS, true) ? (string) $raw['unit'] : 'hour';
        // Cron steps only make sense inside the field's own range.
        $n = min($n, 'minute' === $unit ? 59 : 23);

        $rule['n'] = $n;
        $rule['unit'] = $unit;

        $from = $this->parseTime((string) ($raw['from'] ?? ''));
        $to = $this->parseTime((string) ($raw['to'] ?? ''));
        if (null !== $from && null !== $to && $from !== $to) {
            $rule['from'] = $from;
            $rule['to'] = $to;
        }

        return $rule;
    }

    /**
     * @param  array<string, mixed> $rule
     * @return string               a 5-field cron expression
     */
    public function toCron(array $rule): string
    {
        if ('cron' === ($rule['mode'] ?? 'at')) {
            return (string) $rule['expr'];
        }

        $dow = $rule['days'] ? implode(',', array_map(static fn (int $d): int => 7 === $d ? 0 : $d, $rule['days'])) : '*';
        $dom = $rule['monthDays'] ? implode(',', $rule['monthDays']) : '*';

        if ('at' === ($rule['mode'] ?? 'at')) {
            // Group the times so 09:00 and 18:00 stay one expression when they
            // share a minute, and fall back to a comma list otherwise.
            $minutes = [];
            $hours = [];
            foreach ($rule['at'] as $hm) {
                [$h, $m] = explode(':', $hm);
                $hours[] = (int) $h;
                $minutes[] = (int) $m;
            }
            $minutes = array_values(array_unique($minutes));
            $hours = array_values(array_unique($hours));

            // Several distinct minutes across several hours would multiply out
            // into times the user never asked for; that case is emitted as one
            // expression per time by cronList() instead.
            return sprintf('%s %s %s * %s', implode(',', $minutes), implode(',', $hours), $dom, $dow);
        }

        $n = (int) $rule['n'];
        if ('minute' === $rule['unit']) {
            $hourField = '*';
            if (isset($rule['from'], $rule['to'])) {
                $hourField = ((int) substr($rule['from'], 0, 2)) . '-' . ((int) substr($rule['to'], 0, 2));
            }

            return sprintf('%s %s %s * %s', 1 === $n ? '*' : '*/' . $n, $hourField, $dom, $dow);
        }

        $hourField = 1 === $n ? '*' : '*/' . $n;
        if (isset($rule['from'], $rule['to'])) {
            $range = ((int) substr($rule['from'], 0, 2)) . '-' . ((int) substr($rule['to'], 0, 2));
            $hourField = 1 === $n ? $range : $range . '/' . $n;
        }

        return sprintf('0 %s %s * %s', $hourField, $dom, $dow);
    }

    /**
     * Every cron expression a rule stands for. A rule with times that do not
     * share a minute becomes one expression per time, so "09:00 and 18:30"
     * never fires at 09:30.
     *
     * @param  array<string, mixed> $rule
     * @return list<string>
     */
    public function cronList(array $rule): array
    {
        if ('at' !== ($rule['mode'] ?? 'at')) {
            return [$this->toCron($rule)];
        }


        $minutes = array_unique(array_map(static fn (string $hm): string => explode(':', $hm)[1], $rule['at']));
        if (1 === count($minutes)) {
            return [$this->toCron($rule)];
        }

        $out = [];
        foreach ($rule['at'] as $hm) {
            $out[] = $this->toCron(['days' => $rule['days'], 'monthDays' => $rule['monthDays'], 'mode' => 'at', 'at' => [$hm]]);
        }

        return $out;
    }

    /** All cron expressions of a whole schedule. @return list<string> */
    public function scheduleCrons(Schedule $schedule): array
    {
        $out = [];
        foreach ($schedule->getRules() as $rule) {
            foreach ($this->cronList($rule) as $cron) {
                $out[] = $cron;
            }
        }

        return $out;
    }

    /**
     * The most recent moment the schedule should have fired at or before $now,
     * or null if it has no valid rule.
     */
    public function previousDue(Schedule $schedule, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $tz = $this->timezone($schedule);
        $localNow = $now->setTimezone($tz);
        $best = null;

        foreach ($this->scheduleCrons($schedule) as $expression) {
            if (!CronExpression::isValidExpression($expression)) {
                continue;
            }
            $prev = \DateTimeImmutable::createFromInterface(
                (new CronExpression($expression))->getPreviousRunDate($localNow, 0, true, $tz->getName()),
            );
            if (null === $best || $prev > $best) {
                $best = $prev;
            }
        }

        return $best;
    }

    /** The next moment the schedule will fire, or null. */
    public function nextRun(Schedule $schedule, ?\DateTimeImmutable $now = null): ?\DateTimeImmutable
    {
        $tz = $this->timezone($schedule);
        $localNow = ($now ?? new \DateTimeImmutable())->setTimezone($tz);
        $best = null;

        foreach ($this->scheduleCrons($schedule) as $expression) {
            if (!CronExpression::isValidExpression($expression)) {
                continue;
            }
            $next = \DateTimeImmutable::createFromInterface(
                (new CronExpression($expression))->getNextRunDate($localNow, 0, false, $tz->getName()),
            );
            if (null === $best || $next < $best) {
                $best = $next;
            }
        }

        return $best;
    }

    /** "Mon, Tue · every 2 hours between 09:00 and 18:00" */
    public function describeRule(array $rule): string
    {
        $t = $this->translator;

        if ('cron' === ($rule['mode'] ?? 'at')) {
            return $t->trans('cron') . ' · ' . $rule['expr'];
        }

        if ($rule['days']) {
            $when = implode(', ', array_map(fn (int $d): string => $t->trans(self::DAYS[$d]), $rule['days']));
            if ($rule['monthDays']) {
                $when .= ' · ' . $t->trans('day %days% of the month', ['%days%' => implode(', ', $rule['monthDays'])]);
            }
        } elseif ($rule['monthDays']) {
            // "every day · day 1, 15 of the month" contradicts itself
            $when = $t->trans('day %days% of the month', ['%days%' => implode(', ', $rule['monthDays'])]);
        } else {
            $when = $t->trans('every day');
        }

        if ('at' === ($rule['mode'] ?? 'at')) {
            return $when . ' · ' . implode(', ', $rule['at']);
        }

        $unit = 'minute' === $rule['unit']
            ? $t->trans('%count% minute|%count% minutes', ['%count%' => $rule['n']])
            : $t->trans('%count% hour|%count% hours', ['%count%' => $rule['n']]);
        $every = $t->trans('every %interval%', ['%interval%' => $unit]);

        if (isset($rule['from'], $rule['to'])) {
            $every .= ' ' . $t->trans('between %from% and %to%', ['%from%' => $rule['from'], '%to%' => $rule['to']]);
        }

        return $when . ' · ' . $every;
    }

    /** @return list<string> one sentence per rule */
    public function describe(Schedule $schedule): array
    {
        return array_map(fn (array $r): string => $this->describeRule($r), $schedule->getRules());
    }

    private function timezone(Schedule $schedule): \DateTimeZone
    {
        try {
            return new \DateTimeZone($schedule->getTimezone());
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }

    /** "9:5" and "09:05" both become "09:05"; anything else is null. */
    private function parseTime(string $raw): ?string
    {
        $raw = trim($raw);
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $raw, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h > 23 || $min > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $h, $min);
    }
}
