<?php

declare(strict_types=1);

namespace Charlotte\Islands;

use FancyCms\Agent;
use FancyCms\Islands\IslandCatalogue as FancyIslandCatalogue;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

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

    /**
     * EVERY kit component as an island: fancy-cms's `IslandCatalogue::kit()`
     * (its built-ins, `echart` included, plus 180 `<package>:<Component>`
     * types generated from the components' prop types), and the host's own.
     * Bind it only when the host renders with fancy-cms-ui's `kitIslands`
     * registered; otherwise the agent places types no page can render.
     *
     *     app()->instance(IslandCatalogue::class, CmsDescribeIslands::kit());
     *
     * Or set `charlotte.islands.kit` true. Throws when the installed fancy-cms
     * has no kit catalogue: a host that asked for every component and silently
     * got five would be a host whose agent cannot do what it was told it can.
     *
     * @param  list<array{type: string, description?: string, props?: array}>  $hostEntries
     */
    public static function kit(array $hostEntries = []): SchemaIslandCatalogue
    {
        if (! method_exists(FancyIslandCatalogue::class, 'kit')) {
            throw new RuntimeException('[charlotte] the installed particle-academy/fancy-cms has no kit island catalogue (IslandCatalogue::kit()); upgrade it, or leave charlotte.islands.kit off.');
        }

        // Immutable, and about 300 KB to read: built once per process.
        self::$kit ??= self::fromKit(FancyIslandCatalogue::kit()->toArray());

        return $hostEntries === [] ? self::$kit : self::$kit->with($hostEntries);
    }

    private static ?SchemaIslandCatalogue $kit = null;

    /**
     * A kit catalogue document (`{$schema, $defs, types}`, as
     * `IslandCatalogue::kit()->toArray()` returns it): its bare names are
     * fancy-cms built-ins, its `namespace:Name` types are kit islands.
     *
     * @param  list<array{type: string, description?: string, props?: array}>  $hostEntries
     */
    public static function fromKit(mixed $document, array $hostEntries = []): SchemaIslandCatalogue
    {
        $plain = json_decode(json_encode($document, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($plain) || ! is_array($plain['types'] ?? null) || array_is_list($plain['types'])) {
            throw new InvalidArgumentException('[charlotte] a kit island catalogue needs a "types" object.');
        }
        $builtins = [];
        $kit = [];
        foreach ($plain['types'] as $type => $definition) {
            $type = (string) $type;
            if (str_contains($type, ':')) {
                $kit[] = ['type' => $type] + (is_array($definition) ? $definition : []);
            } else {
                $builtins[$type] = $definition;
            }
        }

        return SchemaIslandCatalogue::fromBuiltins(['$defs' => $plain['$defs'] ?? [], 'types' => $builtins], 'fancy-cms-kit')
            ->with([...$kit, ...$hostEntries]);
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
