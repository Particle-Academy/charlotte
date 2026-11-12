<?php

declare(strict_types=1);

namespace Charlotte\Islands;

use InvalidArgumentException;

/**
 * An island catalogue: fancy-cms's BUILT-IN component nodes (bare names such
 * as `kpi`, read from its `{$schema, $defs, types}` catalogue) and the host's
 * own islands (`namespace:Name`), each with a JSON Schema for its props (the
 * {@see PropsSchema} subset, `$ref` into the catalogue's `$defs` included).
 *
 *     app()->instance(IslandCatalogue::class, CmsDescribeIslands::withHost([
 *         ['type' => 'kit:Tabs', 'description' => 'Tabbed panels', 'props' => [...JSON Schema...]],
 *     ]));
 *
 * `fromArray()` builds a HOST-only catalogue (no built-ins): every type there
 * needs a namespace, so a host island can never take a native or built-in name.
 */
final class SchemaIslandCatalogue implements IslandCatalogue
{
    /**
     * A host island: `namespace:Name`. The colon is what keeps a host type
     * from ever equalling a native fancy-cms type, `richtext` included.
     */
    public const TYPE = '/\A[a-z][a-z0-9-]{0,31}:[A-Za-z][A-Za-z0-9-]{0,63}\z/';

    /** A fancy-cms built-in: one lowercase word, as fancy-cms's IslandCatalogue::TYPE_PATTERN allows. */
    public const BUILTIN = '/\A[a-z][a-z0-9-]{0,31}\z/';

    /**
     * fancy-cms's native elements, which are never islands. A built-in that
     * claimed one of these names (or `richtext`, excluded for every actor)
     * would turn its props schema loose on a native node, so it is refused.
     */
    public const NEVER_ISLANDS = ['section', 'stack', 'grid', 'frame', 'shape', 'card', 'text', 'heading', 'richtext', 'link', 'list', 'divider', 'callout', 'code', 'button', 'image', 'repeater', 'icon'];

    private const MAX_TYPES = 200;

    /**
     * @param  array<string, array{type: string, description: string, props: array, builtin: bool, hydrate?: string, example?: mixed}>  $entries
     * @param  array<string, array>  $defs
     */
    private function __construct(private readonly array $entries, private readonly string $source, private readonly array $defs = []) {}

    /** A host-only catalogue. @param list<array{type: string, description?: string, props?: array, schema?: array}> $entries */
    public static function fromArray(array $entries, string $source = 'host'): self
    {
        return new self(self::entries($entries, false), $entries === [] && $source === 'host' ? 'none' : $source);
    }

    /**
     * fancy-cms's built-ins from its catalogue document: `types` (name =>
     * {description, hydrate, props, example}) and `$defs`.
     *
     * @param  array{'$defs'?: array, types?: array}  $catalogue
     */
    public static function fromBuiltins(array $catalogue, string $source = 'fancy-cms'): self
    {
        $list = [];
        foreach ((array) ($catalogue['types'] ?? []) as $type => $definition) {
            $list[] = ['type' => (string) $type] + (is_array($definition) ? $definition : []);
        }
        $defs = $catalogue['$defs'] ?? [];
        if (! is_array($defs)) {
            throw new InvalidArgumentException('A catalogue\'s $defs must be an object of schemas.');
        }

        return new self(self::entries($list, true), $source, $defs);
    }

    /** These built-ins plus the host's own islands, which must be namespace:Name. */
    public function with(array $hostEntries): self
    {
        $host = self::entries($hostEntries, false);
        foreach (array_keys($host) as $type) {
            if (isset($this->entries[$type])) {
                throw new InvalidArgumentException("Island type [{$type}] is declared twice.");
            }
        }

        return new self($this->entries + $host, $this->source, $this->defs);
    }

    public function types(): array
    {
        return array_keys($this->entries);
    }

    public function describe(): array
    {
        return array_values(array_map(fn (array $e) => array_diff_key($e, ['builtin' => true]), $this->entries));
    }

    public function defs(): array
    {
        return $this->defs;
    }

    public function builtin(string $type): bool
    {
        return ($this->entries[$type]['builtin'] ?? false) === true;
    }

    public function validate(string $type, mixed $props): array
    {
        if (! isset($this->entries[$type])) {
            return [['path' => '$', 'expected' => 'an island type from the catalogue', 'got' => 'unknown island type']];
        }

        return PropsSchema::validate($this->entries[$type]['props'], $props, '$', $this->defs);
    }

    public function source(): string
    {
        return $this->source;
    }

    /** @return array<string, array> */
    private static function entries(array $entries, bool $builtin): array
    {
        if (count($entries) > self::MAX_TYPES) {
            throw new InvalidArgumentException('An island catalogue holds at most '.self::MAX_TYPES.' types.');
        }
        $out = [];
        foreach ($entries as $entry) {
            $type = is_array($entry) ? ($entry['type'] ?? null) : null;
            $schema = is_array($entry) ? ($entry['props'] ?? $entry['schema'] ?? ['type' => 'object']) : null;
            $named = is_string($type) && ($builtin
                ? preg_match(self::BUILTIN, $type) === 1 && ! in_array($type, self::NEVER_ISLANDS, true)
                : preg_match(self::TYPE, $type) === 1);
            if (! $named || ! is_array($schema) || isset($out[$type])) {
                throw new InvalidArgumentException($builtin
                    ? 'A built-in island needs a unique lowercase name that is not a native element, and a JSON Schema for its props.'
                    : 'Each host island needs a unique namespace:Name type (such as kit:Tabs) and a JSON Schema for its props.');
            }
            $description = is_string($entry['description'] ?? null) ? mb_substr($entry['description'], 0, 500) : '';
            $out[$type] = ['type' => $type, 'description' => $description, 'props' => $schema, 'builtin' => $builtin]
                + (is_string($entry['hydrate'] ?? null) ? ['hydrate' => $entry['hydrate']] : [])
                + (array_key_exists('example', $entry) ? ['example' => $entry['example']] : []);
        }

        return $out;
    }
}
