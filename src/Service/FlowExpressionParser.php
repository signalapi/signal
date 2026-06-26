<?php

namespace App\Service;

/**
 * Parses the human-friendly textarea syntax used to define a step's extractions
 * and assertions, and renders the stored structures back to text for editing.
 *
 * Extractions (one per line):   varName = json.path
 * Assertions (one per line):
 *   status == 200
 *   data.user.id exists
 *   data.status == active
 *   data.items contains foo
 *   body contains "ok"
 */
class FlowExpressionParser
{
    /**
     * @return array<int, array{var: string, path: string}>
     */
    public function parseExtractions(string $raw): array
    {
        $out = [];
        foreach ($this->lines($raw) as $line) {
            if (!str_contains($line, '=')) {
                continue;
            }
            [$var, $path] = explode('=', $line, 2);
            $var = trim($var);
            $path = trim($path);
            if ('' !== $var && '' !== $path) {
                $out[] = ['var' => $var, 'path' => $path];
            }
        }

        return $out;
    }

    /**
     * @param array<int, array{var: string, path: string}> $extractions
     */
    public function renderExtractions(array $extractions): string
    {
        return implode("\n", array_map(
            static fn (array $e): string => sprintf('%s = %s', $e['var'], $e['path']),
            $extractions,
        ));
    }

    /**
     * @return array<int, array<string, string>>
     */
    private const TOKEN_TO_OP = [
        '==' => 'eq', 'equals' => 'eq', '!=' => 'ne', 'ne' => 'ne',
        '>' => 'gt', '<' => 'lt', '>=' => 'ge', '<=' => 'le',
        'contains' => 'contains', 'matches' => 'matches',
        'exists' => 'exists', 'empty' => 'empty', 'notEmpty' => 'notEmpty', 'not_empty' => 'notEmpty',
    ];

    private const OP_TO_TOKEN = [
        'eq' => '==', 'equals' => '==', 'ne' => '!=', 'gt' => '>', 'lt' => '<', 'ge' => '>=', 'le' => '<=',
        'contains' => 'contains', 'matches' => 'matches', 'exists' => 'exists', 'empty' => 'empty', 'notEmpty' => 'notEmpty',
    ];

    private const NO_VALUE_OPS = ['exists', 'empty', 'notEmpty'];

    public function parseAssertions(string $raw): array
    {
        $out = [];
        foreach ($this->lines($raw) as $line) {
            $head = preg_split('/\s+/', $line, 2) ?: [];
            $first = $head[0] ?? '';

            // header <name> <op> [value]
            if ('header' === $first) {
                $p = preg_split('/\s+/', $line, 4) ?: [];
                $op = self::TOKEN_TO_OP[$p[2] ?? ''] ?? null;
                if (null === $op || '' === ($p[1] ?? '')) {
                    continue;
                }
                $out[] = ['kind' => 'header', 'name' => $p[1], 'op' => $op, 'expected' => $this->unquote($p[3] ?? '')];
                continue;
            }

            // <target> <op> [value]   (target = status | responseTime | body | jsonpath)
            $p = preg_split('/\s+/', $line, 3) ?: [];
            $op = self::TOKEN_TO_OP[$p[1] ?? ''] ?? null;
            if (null === $op) {
                continue;
            }
            $value = $this->unquote($p[2] ?? '');

            $kind = match ($first) {
                'status' => 'status',
                'responseTime', 'responsetime' => 'responseTime',
                'body' => 'body',
                default => 'jsonpath',
            };

            $entry = ['kind' => $kind, 'op' => $op, 'expected' => $value];
            if ('jsonpath' === $kind) {
                $entry['path'] = $first;
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @param array<int, array<string, string>> $assertions
     */
    public function renderAssertions(array $assertions): string
    {
        $lines = [];
        foreach ($assertions as $a) {
            $op = $a['op'] ?? 'eq';
            $token = self::OP_TO_TOKEN[$op] ?? '==';
            $value = \in_array($op, self::NO_VALUE_OPS, true) ? '' : ' ' . ($a['expected'] ?? '');
            $lines[] = match ($a['kind'] ?? '') {
                'status' => 'status ' . $token . $value,
                'responseTime' => 'responseTime ' . $token . $value,
                'body' => 'body ' . $token . $value,
                'header' => 'header ' . ($a['name'] ?? '') . ' ' . $token . $value,
                'jsonpath' => ($a['path'] ?? '') . ' ' . $token . $value,
                default => '',
            };
        }

        return implode("\n", array_filter($lines));
    }

    /**
     * @return string[]
     */
    private function lines(string $raw): array
    {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ('' !== $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function unquote(string $value): string
    {
        $value = trim($value);
        if (\strlen($value) >= 2 && (('"' === $value[0] && '"' === substr($value, -1)) || ("'" === $value[0] && "'" === substr($value, -1)))) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
