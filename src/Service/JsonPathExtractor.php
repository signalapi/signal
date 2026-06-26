<?php

namespace App\Service;

/**
 * Minimal dot/bracket JSON path resolver.
 * Supports: $.a.b, a.b.c, data.items[0].id, items.2.name
 */
class JsonPathExtractor
{
    /**
     * @return array{found: bool, value: mixed}
     */
    public function find(mixed $data, string $path): array
    {
        $path = trim($path);
        $path = preg_replace('/^\$\.?/', '', $path) ?? $path;
        $path = preg_replace('/\[(\d+)\]/', '.$1', $path) ?? $path;
        $path = trim($path, '.');

        if ('' === $path) {
            return ['found' => true, 'value' => $data];
        }

        $current = $data;
        foreach (explode('.', $path) as $token) {
            if (\is_array($current) && \array_key_exists($token, $current)) {
                $current = $current[$token];
                continue;
            }

            return ['found' => false, 'value' => null];
        }

        return ['found' => true, 'value' => $current];
    }

    /**
     * Returns a string representation suitable for storing in the run context.
     */
    public function stringify(mixed $value): string
    {
        if (null === $value) {
            return '';
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}
