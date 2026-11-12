<?php

declare(strict_types=1);

namespace Charlotte\Islands;

use FancyCms\Agent;
use InvalidArgumentException;
use JsonException;

/**
 * The seam to fancy-cms's island catalogue: the `{$schema, $defs, types}`
 * document in `resources/islands/catalogue.json`, which `Agent::describe()`
 * returns under `islands`. Its types are fancy-cms's built-in component
 * nodes (chart, kpi, table, leaderboard, input), read here as bare names
 * with their `$defs`.
 *
 * A shape this version cannot read is treated as absent (null), never
 * half-read: Charlotte then has no built-ins, and the native nodes of those
 * names are left unvalidated rather than validated wrongly.
 */
final class CmsDescribeIslands
{
    /** The default binding: fancy-cms's built-ins, or an empty catalogue when the install has none. */
    public static function catalogue(): IslandCatalogue
    {
        return self::fromDescribe(Agent::describe()) ?? SchemaIslandCatalogue::fromArray([], 'none');
    }

    /**
     * fancy-cms's built-ins plus the host's own islands (`namespace:Name`).
     *
     * @param  list<array{type: string, description?: string, props?: array}>  $hostEntries
     */
    public static function withHost(array $hostEntries): SchemaIslandCatalogue
    {
        $builtins = self::fromDescribe(Agent::describe()) ?? SchemaIslandCatalogue::fromArray([], 'host');

        return $builtins->with($hostEntries);
    }

    /** null when `describe()` carries no catalogue this version reads. */
    public static function fromDescribe(array $describe): ?SchemaIslandCatalogue
    {
        $islands = $describe['islands'] ?? null;

        return $islands === null ? null : self::fromCatalogue($islands);
    }

    /**
     * The `{$schema, $defs, types}` document, decoded or as fancy-cms returns
     * it (schemas read from JSON keep stdClass objects; they are normalised to
     * arrays here). null when it is not that shape or names a native element.
     */
    public static function fromCatalogue(mixed $catalogue): ?SchemaIslandCatalogue
    {
        try {
            $plain = json_decode(json_encode($catalogue, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! is_array($plain) || ! is_array($plain['types'] ?? null) || array_is_list($plain['types'])) {
            return null;
        }
        try {
            return SchemaIslandCatalogue::fromBuiltins($plain);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
