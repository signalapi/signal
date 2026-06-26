<?php

namespace App\Service;

/**
 * Replaces {{variable}} placeholders (Postman-compatible syntax).
 * - {{name}}        → looked up in the provided env/context map
 * - {{$randomEmail}} → generated dynamic variable (fresh per occurrence)
 * Unknown variables are left untouched so they stay visible in the sent request.
 */
class VariableResolver
{
    public function __construct(private readonly DynamicVariableGenerator $dynamic)
    {
    }

    /**
     * @param array<string, string> $vars
     */
    public function resolve(?string $input, array $vars): ?string
    {
        if (null === $input || '' === $input) {
            return $input;
        }

        return preg_replace_callback(
            '/\{\{\s*(\$?[\w.\-]+)\s*\}\}/',
            function (array $m) use ($vars): string {
                $name = $m[1];

                if (str_starts_with($name, '$')) {
                    return $this->dynamic->generate($name) ?? $m[0];
                }

                return $vars[$name] ?? $m[0];
            },
            $input,
        );
    }

    /**
     * @param array<int, array{name: string, value: string}> $pairs
     * @param array<string, string>                          $vars
     *
     * @return array<int, array{name: string, value: string}>
     */
    public function resolvePairs(array $pairs, array $vars): array
    {
        $resolved = [];
        foreach ($pairs as $pair) {
            $name = trim((string) ($pair['name'] ?? ''));
            if ('' === $name) {
                continue;
            }
            $resolved[] = [
                'name' => $this->resolve($name, $vars) ?? $name,
                'value' => $this->resolve((string) ($pair['value'] ?? ''), $vars) ?? '',
            ];
        }

        return $resolved;
    }
}
