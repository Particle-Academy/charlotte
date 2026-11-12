<?php

declare(strict_types=1);

namespace Charlotte\Islands;

use stdClass;

/**
 * A small JSON Schema subset for island props, written here rather than
 * taken as a dependency: island props are JSON-friendly by contract, so the
 * keywords that matter are few.
 *
 * Supported: `type` (string or list), `enum`, `const`, `properties`,
 * `required`, `additionalProperties` (boolean or schema), `items` (schema),
 * `minItems`, `maxItems`, `minLength`, `maxLength`, `minimum`, `maximum`,
 * `pattern`, `anyOf`, `oneOf` (treated as anyOf), and `$ref` to the
 * catalogue's own `#/$defs/<name>`. Unknown keywords are
 * ignored, so a richer schema from fancy-cms validates at least this much.
 *
 * Errors are `{path, expected, got}`, the shape every Charlotte tool error
 * uses, so the model can fix the exact field.
 */
final class PropsSchema
{
    private const MAX_DEPTH = 32;

    private const MAX_ERRORS = 20;

    /** @return list<array{path: string, expected: string, got: string}> */
    /** The `$defs` a `$ref` resolves against during one validate() call. */
    private static array $defs = [];

    /**
     * @param  array<string, array>  $defs  a catalogue's `$defs`, for `{"$ref": "#/$defs/<name>"}`
     */
    public static function validate(array $schema, mixed $value, string $path = '$', array $defs = []): array
    {
        $errors = [];
        $outer = self::$defs;
        self::$defs = $defs;
        try {
            self::walk($schema, self::plain($value), $path, $errors, 0);
        } finally {
            self::$defs = $outer;
        }

        return array_slice($errors, 0, self::MAX_ERRORS);
    }

    /** Objects (stdClass from wire decoding) become arrays, recursively. */
    public static function plain(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = (array) $value;
            if ($value === []) {
                return new EmptyObject;
            }
        }

        return is_array($value) ? array_map(self::plain(...), $value) : $value;
    }

    private static function walk(array $schema, mixed $value, string $path, array &$errors, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            $errors[] = self::error($path, 'at most '.self::MAX_DEPTH.' levels of nesting', 'deeper');

            return;
        }
        // `$ref` into the catalogue's $defs (the only form fancy-cms uses); its
        // siblings (a description, a default) apply alongside the target.
        $ref = $schema['$ref'] ?? null;
        if (is_string($ref)) {
            $name = str_starts_with($ref, '#/$defs/') ? substr($ref, strlen('#/$defs/')) : null;
            $target = $name === null ? null : (self::$defs[$name] ?? null);
            if (! is_array($target)) {
                $errors[] = self::error($path, 'a schema the catalogue defines ('.$ref.')', 'an unresolvable $ref');

                return;
            }
            unset($schema['$ref']);
            self::walk($target + $schema, $value, $path, $errors, $depth + 1);

            return;
        }
        foreach (['anyOf', 'oneOf'] as $key) {
            if (is_array($schema[$key] ?? null) && $schema[$key] !== []) {
                foreach ($schema[$key] as $option) {
                    $sub = [];
                    if (is_array($option)) {
                        self::walk($option, $value, $path, $sub, $depth + 1);
                    }
                    if ($sub === []) {
                        continue 2;
                    }
                }
                $errors[] = self::error($path, 'a value matching one of the allowed shapes', self::kind($value));

                return;
            }
        }
        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            $errors[] = self::error($path, 'exactly '.json_encode($schema['const']), self::kind($value));

            return;
        }
        if (is_array($schema['enum'] ?? null) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = self::error($path, 'one of '.json_encode($schema['enum'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), self::kind($value));

            return;
        }
        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            $ok = false;
            foreach ($types as $type) {
                if (self::is($type, $value)) {
                    $ok = true;
                    break;
                }
            }
            if (! $ok) {
                $errors[] = self::error($path, implode(' or ', $types), self::kind($value));

                return;
            }
        }

        if (is_string($value)) {
            $length = mb_strlen($value);
            if (isset($schema['minLength']) && $length < (int) $schema['minLength']) {
                $errors[] = self::error($path, "a string of at least {$schema['minLength']} characters", "{$length} characters");
            }
            if (isset($schema['maxLength']) && $length > (int) $schema['maxLength']) {
                $errors[] = self::error($path, "a string of at most {$schema['maxLength']} characters", "{$length} characters");
            }
            if (is_string($schema['pattern'] ?? null)) {
                $matched = @preg_match('/'.str_replace('/', '\/', $schema['pattern']).'/u', $value);
                if ($matched !== 1) {
                    $errors[] = self::error($path, 'a string matching '.$schema['pattern'], 'a string that does not match');
                }
            }
        }
        if ((is_int($value) || is_float($value))) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = self::error($path, "a number of at least {$schema['minimum']}", (string) $value);
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = self::error($path, "a number of at most {$schema['maximum']}", (string) $value);
            }
        }
        if (is_array($value) && array_is_list($value) && ! self::isObjectOnly($schema)) {
            $count = count($value);
            if (isset($schema['minItems']) && $count < (int) $schema['minItems']) {
                $errors[] = self::error($path, "at least {$schema['minItems']} items", "{$count} items");
            }
            if (isset($schema['maxItems']) && $count > (int) $schema['maxItems']) {
                $errors[] = self::error($path, "at most {$schema['maxItems']} items", "{$count} items");
            }
            if (is_array($schema['items'] ?? null)) {
                foreach ($value as $i => $item) {
                    self::walk($schema['items'], $item, $path.'['.$i.']', $errors, $depth + 1);
                }
            }
        }
        if (self::isObject($value)) {
            $object = $value instanceof EmptyObject ? [] : $value;
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            foreach ((array) ($schema['required'] ?? []) as $name) {
                if (is_string($name) && ! array_key_exists($name, $object)) {
                    $errors[] = self::error($path.'.'.$name, 'a required property', 'missing');
                }
            }
            foreach ($object as $name => $item) {
                $name = (string) $name;
                if (is_array($properties[$name] ?? null)) {
                    self::walk($properties[$name], $item, $path.'.'.$name, $errors, $depth + 1);
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = self::error($path.'.'.$name, 'no such property; declared: '.($properties === [] ? 'none' : implode(', ', array_keys($properties))), 'undeclared property');
                } elseif (is_array($schema['additionalProperties'] ?? null)) {
                    self::walk($schema['additionalProperties'], $item, $path.'.'.$name, $errors, $depth + 1);
                }
            }
        }
    }

    private static function isObjectOnly(array $schema): bool
    {
        return isset($schema['type']) && (array) $schema['type'] === ['object'];
    }

    private static function isObject(mixed $value): bool
    {
        return $value instanceof EmptyObject || (is_array($value) && (! array_is_list($value) || $value === []));
    }

    private static function is(string $type, mixed $value): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value) || (is_float($value) && floor($value) === $value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            'array' => is_array($value) && array_is_list($value),
            'object' => self::isObject($value),
            default => false,
        };
    }

    private static function kind(mixed $value): string
    {
        return match (true) {
            $value instanceof EmptyObject => 'object',
            is_string($value) => 'string',
            is_int($value), is_float($value) => 'number',
            is_bool($value) => 'boolean',
            $value === null => 'null',
            is_array($value) => array_is_list($value) && $value !== [] ? 'array' : 'object',
            default => 'unsupported value',
        };
    }

    private static function error(string $path, string $expected, string $got): array
    {
        return ['path' => $path, 'expected' => $expected, 'got' => $got];
    }
}
