<?php

namespace App\Service;

/**
 * Value snapshots: normalises a decoded JSON response (volatile paths masked,
 * object keys sorted) and diffs two normalised responses VALUE by VALUE — the
 * layer above ResponseShape, which only compares keys and types.
 */
class ResponseSnapshot
{
    private const MASK = '«ignored»';
    private const MAX_DIFF_LINES = 20;
    private const MAX_VALUE_CHARS = 60;

    /**
     * @param list<string> $ignorePaths dot paths; `*` matches one segment
     *                                  (so `items.*.id` masks every item's id)
     */
    public function normalize(mixed $value, array $ignorePaths): mixed
    {
        return $this->walk($value, '', array_values(array_filter(array_map(trim(...), $ignorePaths))));
    }

    /**
     * @param list<string> $ignore raw text, one path per line
     *
     * @return list<string>
     */
    public static function parseIgnore(?string $raw): array
    {
        return array_values(array_filter(array_map(trim(...), explode("\n", (string) $raw))));
    }

    /**
     * Human-readable value changes between two NORMALISED structures.
     *
     * @return string[]
     */
    public function diff(mixed $expected, mixed $actual, string $path = ''): array
    {
        if (\is_array($expected) !== \is_array($actual)) {
            return ['~ ' . ($path ?: '(root)') . ': ' . $this->render($expected) . ' → ' . $this->render($actual)];
        }

        if (!\is_array($expected)) {
            return $expected === $actual
                ? []
                : ['~ ' . ($path ?: '(root)') . ': ' . $this->render($expected) . ' → ' . $this->render($actual)];
        }

        $changes = [];
        foreach ($expected as $key => $value) {
            $p = '' === $path ? (string) $key : $path . '.' . $key;
            if (!\array_key_exists($key, $actual)) {
                $changes[] = '− ' . $p . ' removed (was ' . $this->render($value) . ')';
            } else {
                $changes = array_merge($changes, $this->diff($value, $actual[$key], $p));
            }
            if (\count($changes) >= self::MAX_DIFF_LINES) {
                return \array_slice($changes, 0, self::MAX_DIFF_LINES);
            }
        }
        foreach ($actual as $key => $value) {
            if (!\array_key_exists($key, $expected)) {
                $changes[] = '＋ ' . ('' === $path ? (string) $key : $path . '.' . $key) . ' added: ' . $this->render($value);
            }
        }

        return \array_slice($changes, 0, self::MAX_DIFF_LINES);
    }

    /**
     * @param list<string> $ignorePaths
     */
    private function walk(mixed $value, string $path, array $ignorePaths): mixed
    {
        if ($this->ignored($path, $ignorePaths)) {
            return self::MASK;
        }
        if (!\is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $key => $child) {
            $out[$key] = $this->walk($child, '' === $path ? (string) $key : $path . '.' . $key, $ignorePaths);
        }
        if (!array_is_list($value)) {
            ksort($out);
        }

        return $out;
    }

    /**
     * @param list<string> $ignorePaths
     */
    private function ignored(string $path, array $ignorePaths): bool
    {
        if ('' === $path) {
            return false;
        }
        foreach ($ignorePaths as $pattern) {
            $regex = '/^' . str_replace('\*', '[^.]+', preg_quote($pattern, '/')) . '$/';
            if (1 === preg_match($regex, $path)) {
                return true;
            }
        }

        return false;
    }

    private function render(mixed $value): string
    {
        $text = \is_array($value)
            ? (string) json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)
            : (string) json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return mb_strlen($text) > self::MAX_VALUE_CHARS ? mb_substr($text, 0, self::MAX_VALUE_CHARS) . '…' : $text;
    }
}
