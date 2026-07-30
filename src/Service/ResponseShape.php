<?php

namespace App\Service;

/**
 * Derives a value-agnostic "shape" of a decoded JSON response (keys + types,
 * not values) and diffs two shapes — so a run can flag when an API's contract
 * drifts (a field disappears, a type changes) even when assertions still pass.
 */
class ResponseShape
{
    /**
     * Canonical shape: objects → {key: shape} (sorted), lists → {"[]": elemShape},
     * scalars → a type name.
     */
    public function of(mixed $value): mixed
    {
        if (\is_array($value)) {
            if (array_is_list($value)) {
                return ['[]' => [] === $value ? 'empty' : $this->of($value[0])];
            }
            $out = [];
            foreach ($value as $k => $v) {
                $out[(string) $k] = $this->of($v);
            }
            ksort($out);

            return $out;
        }

        return match (true) {
            null === $value => 'null',
            \is_bool($value) => 'bool',
            \is_int($value) => 'int',
            \is_float($value) => 'float',
            \is_string($value) => 'string',
            default => 'mixed',
        };
    }

    /**
     * Compares two shapes and returns human-readable change lines (added /
     * removed keys, type changes). Empty array = no drift.
     *
     * @return string[]
     */
    public function diff(mixed $base, mixed $current, string $path = ''): array
    {
        // Container vs scalar (or object vs list) — treat as a type change.
        if (\is_array($base) !== \is_array($current)) {
            return ['~ ' . ($path ?: '(root)') . ': ' . $this->describe($base) . ' → ' . $this->describe($current)];
        }

        if (!\is_array($base)) {
            return $base === $current ? [] : ['~ ' . ($path ?: '(root)') . ': ' . $base . ' → ' . $current];
        }

        // 'empty' list placeholder shouldn't be reported as drift against a typed list.
        if (isset($base['[]']) && isset($current['[]'])) {
            if ('empty' === $base['[]'] || 'empty' === $current['[]']) {
                return [];
            }
        }

        $changes = [];
        foreach ($base as $k => $bv) {
            $p = $this->join($path, $k);
            if (!\array_key_exists($k, $current)) {
                $changes[] = '− ' . $p . ' removed';
            } else {
                $changes = array_merge($changes, $this->diff($bv, $current[$k], $p));
            }
        }
        foreach ($current as $k => $cv) {
            if (!\array_key_exists($k, $base)) {
                $changes[] = '＋ ' . $this->join($path, $k) . ' eklendi';
            }
        }

        return $changes;
    }

    private function join(string $path, string $key): string
    {
        if ('[]' === $key) {
            return ($path ?: '') . '[]';
        }

        return '' === $path ? $key : $path . '.' . $key;
    }

    private function describe(mixed $shape): string
    {
        if (\is_array($shape)) {
            return isset($shape['[]']) ? 'dizi' : 'nesne';
        }

        return (string) $shape;
    }
}
