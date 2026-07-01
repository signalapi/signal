<?php

namespace App\Service;

/**
 * Minimal JSON-Schema support: infer a schema from a sample response, and
 * validate a decoded response against a schema. Covers the common subset
 * (type, properties, required, items) — enough for auto-generated schemas
 * and light hand-editing, with no external dependency.
 */
class JsonSchema
{
    private const MAX_VIOLATIONS = 25;

    /**
     * Infers a schema from a decoded JSON value.
     *
     * @return array<string, mixed>
     */
    public function infer(mixed $value): array
    {
        if (\is_array($value)) {
            if (array_is_list($value)) {
                return [] === $value
                    ? ['type' => 'array']
                    : ['type' => 'array', 'items' => $this->infer($value[0])];
            }
            $props = [];
            foreach ($value as $k => $v) {
                $props[(string) $k] = $this->infer($v);
            }
            ksort($props);

            return ['type' => 'object', 'properties' => $props, 'required' => array_keys($props)];
        }

        return ['type' => match (true) {
            null === $value => 'null',
            \is_bool($value) => 'boolean',
            \is_int($value) => 'integer',
            \is_float($value) => 'number',
            default => 'string',
        }];
    }

    /**
     * Validates data against a schema; returns human-readable violation lines
     * (empty = valid).
     *
     * @param array<string, mixed> $schema
     *
     * @return string[]
     */
    public function validate(array $schema, mixed $data, string $path = '$'): array
    {
        $out = [];
        $this->walk($schema, $data, $path, $out);

        return \array_slice($out, 0, self::MAX_VIOLATIONS);
    }

    /**
     * @param array<string, mixed> $schema
     * @param string[]             $out
     */
    private function walk(array $schema, mixed $data, string $path, array &$out): void
    {
        if (\count($out) >= self::MAX_VIOLATIONS) {
            return;
        }
        $types = $schema['type'] ?? null;
        if (null !== $types) {
            $types = (array) $types;
            if (!$this->matchesAnyType($types, $data)) {
                $out[] = sprintf('%s: %s bekleniyordu, %s geldi', $path, implode('|', $types), $this->actualType($data));

                return; // type mismatch — deeper checks would be noise
            }
        }

        // Object constraints.
        if (\is_array($data) && !array_is_list($data)) {
            foreach ((array) ($schema['required'] ?? []) as $req) {
                if (!\array_key_exists($req, $data)) {
                    $out[] = sprintf('%s.%s eksik (required)', $path, $req);
                }
            }
            foreach ((array) ($schema['properties'] ?? []) as $key => $propSchema) {
                if (\array_key_exists($key, $data) && \is_array($propSchema)) {
                    $this->walk($propSchema, $data[$key], $path . '.' . $key, $out);
                }
            }
        }

        // Array item constraints.
        if (\is_array($data) && array_is_list($data) && isset($schema['items']) && \is_array($schema['items'])) {
            foreach ($data as $i => $elem) {
                $this->walk($schema['items'], $elem, $path . '[' . $i . ']', $out);
                if (\count($out) >= self::MAX_VIOLATIONS) {
                    break;
                }
            }
        }
    }

    /**
     * @param string[] $types
     */
    private function matchesAnyType(array $types, mixed $data): bool
    {
        foreach ($types as $t) {
            if ($this->matchesType((string) $t, $data)) {
                return true;
            }
        }

        return false;
    }

    private function matchesType(string $type, mixed $data): bool
    {
        // An empty array is ambiguous between {} and [] after json_decode.
        $emptyArray = [] === $data;

        return match ($type) {
            'object' => \is_array($data) && (!array_is_list($data) || $emptyArray),
            'array' => \is_array($data) && (array_is_list($data) || $emptyArray),
            'string' => \is_string($data),
            'integer' => \is_int($data),
            'number' => \is_int($data) || \is_float($data),
            'boolean' => \is_bool($data),
            'null' => null === $data,
            default => true, // unknown type keyword → don't fail
        };
    }

    private function actualType(mixed $data): string
    {
        return match (true) {
            null === $data => 'null',
            \is_bool($data) => 'boolean',
            \is_int($data) => 'integer',
            \is_float($data) => 'number',
            \is_string($data) => 'string',
            \is_array($data) => array_is_list($data) ? 'array' : 'object',
            default => 'mixed',
        };
    }
}
