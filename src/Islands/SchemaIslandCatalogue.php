<?php

declare(strict_types=1);

namespace Charlotte\Islands;

use InvalidArgumentException;

/**
 * An island catalogue from a list of `{type, description?, props}` entries,
 * where `props` is a JSON Schema (the {@see PropsSchema} subset is enforced).
 *
 *     app()->instance(IslandCatalogue::class, SchemaIslandCatalogue::fromArray([
 *         ['type' => 'kit:Tabs', 'description' => 'Tabbed panels', 'props' => [...JSON Schema...]],
 *     ]));
 */
final class SchemaIslandCatalogue implements IslandCatalogue
{
    /**
     * `namespace:Name`. The colon is what keeps an island type from ever
     * equalling a native fancy-cms type, `richtext` included.
     */
    public const TYPE = '/\A[a-z][a-z0-9-]{0,31}:[A-Za-z][A-Za-z0-9-]{0,63}\z/';

    private const MAX_TYPES = 200;

    /** @param array<string, array{type: string, description: string, props: array}> $entries */
    private function __construct(private readonly array $entries, private readonly string $source) {}

    /** @param list<array{type: string, description?: string, props?: array, schema?: array}> $entries */
    public static function fromArray(array $entries, string $source = 'host'): self
    {
        if (count($entries) > self::MAX_TYPES) {
            throw new InvalidArgumentException('An island catalogue holds at most '.self::MAX_TYPES.' types.');
        }
        $out = [];
        foreach ($entries as $entry) {
            $type = is_array($entry) ? ($entry['type'] ?? null) : null;
            $schema = is_array($entry) ? ($entry['props'] ?? $entry['schema'] ?? ['type' => 'object']) : null;
            if (! is_string($type) || preg_match(self::TYPE, $type) !== 1 || ! is_array($schema) || isset($out[$type])) {
                throw new InvalidArgumentException('Each island needs a unique namespace:Name type (such as kit:Tabs) and a JSON Schema for its props.');
            }
            $description = is_string($entry['description'] ?? null) ? mb_substr($entry['description'], 0, 500) : '';
            $out[$type] = ['type' => $type, 'description' => $description, 'props' => $schema];
        }

        return new self($out, $entries === [] && $source === 'host' ? 'none' : $source);
    }

    public function types(): array
    {
        return array_keys($this->entries);
    }

    public function describe(): array
    {
        return array_values($this->entries);
    }

    public function validate(string $type, mixed $props): array
    {
        if (! isset($this->entries[$type])) {
            return [['path' => '$', 'expected' => 'an island type from the catalogue', 'got' => 'unknown island type']];
        }

        return PropsSchema::validate($this->entries[$type]['props'], $props);
    }

    public function source(): string
    {
        return $this->source;
    }
}
