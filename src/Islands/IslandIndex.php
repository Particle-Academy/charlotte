<?php

declare(strict_types=1);

namespace Charlotte\Islands;

/**
 * The island catalogue as the agent reads it, in two sizes.
 *
 * The full kit catalogue (fancy-cms's `IslandCatalogue::kit()`, 186 types,
 * about 300 KB of schemas) is far too big for a prompt or a single tool
 * result. So `cms_describe` lists every type by FAMILY with a one-line
 * description ({@see self::families()}), plus the full schemas of fancy-cms's
 * own built-ins, which are few and small; `cms_island_schema` returns the
 * full schema and example of only the types asked for ({@see self::schemas()}).
 */
final class IslandIndex
{
    /** A one-line description is cut to this many characters. */
    public const LINE = 120;

    /** The family of fancy-cms's built-ins (chart, echart, kpi, table, leaderboard, input). */
    public const BUILTIN_FAMILY = 'fancy-cms';

    /**
     * Every type, grouped by family, each with a one-line description.
     *
     * @return array<string, array<string, string>> family => [type => description]
     */
    public static function families(IslandCatalogue $catalogue): array
    {
        $families = [];
        foreach ($catalogue->describe() as $entry) {
            $type = (string) $entry['type'];
            $families[self::family($catalogue, $entry)][$type] = self::line((string) ($entry['description'] ?? ''));
        }

        return $families;
    }

    /**
     * The full entries of fancy-cms's built-ins: small, and the default host's
     * whole catalogue, so they stay inline in cms_describe.
     *
     * @return list<array>
     */
    public static function builtins(IslandCatalogue $catalogue): array
    {
        return array_values(array_filter($catalogue->describe(), fn (array $e) => $catalogue->builtin((string) $e['type'])));
    }

    /**
     * The full schema and example of exactly the requested types, in the order
     * asked, with only the `$defs` they reference. Types the catalogue does not
     * have are listed under `unknown`, never guessed at.
     *
     * @param  list<string>  $types
     * @return array{types: list<array>, defs: array<string, mixed>, unknown?: list<string>}
     */
    public static function schemas(IslandCatalogue $catalogue, array $types): array
    {
        $byType = [];
        foreach ($catalogue->describe() as $entry) {
            $byType[(string) $entry['type']] = $entry;
        }
        $found = [];
        $unknown = [];
        foreach (array_values(array_unique($types)) as $type) {
            if (isset($byType[$type])) {
                $found[] = $byType[$type];
            } else {
                $unknown[] = $type;
            }
        }

        return ['types' => $found, 'defs' => self::referencedDefs($found, $catalogue->defs())]
            + ($unknown === [] ? [] : ['unknown' => $unknown]);
    }

    /** One line: the first line, whitespace collapsed, cut at a word near {@see self::LINE}. */
    public static function line(string $description): string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', strtok($description, "\r\n") ?: ''));
        if (mb_strlen($line) <= self::LINE) {
            return $line;
        }
        $cut = mb_substr($line, 0, self::LINE - 3);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > self::LINE / 2 ? mb_substr($cut, 0, $space) : $cut, " ,;:.").'...';
    }

    /**
     * A built-in's family is fancy-cms; a kit island's is its package with the
     * `@particle-academy/` scope dropped (`react-fancy`); any other host
     * island's is its namespace (`kit` for `kit:Tabs`).
     */
    private static function family(IslandCatalogue $catalogue, array $entry): string
    {
        $type = (string) $entry['type'];
        if ($catalogue->builtin($type)) {
            return self::BUILTIN_FAMILY;
        }
        $package = $entry['package'] ?? null;
        if (is_string($package) && $package !== '') {
            return (string) preg_replace('#\A@[^/]+/#', '', $package);
        }

        return strstr($type, ':', true) ?: $type;
    }

    /**
     * The `$defs` the given entries `$ref`, followed transitively.
     *
     * @param  list<array>  $entries
     * @param  array<string, mixed>  $defs
     * @return array<string, mixed>
     */
    private static function referencedDefs(array $entries, array $defs): array
    {
        $out = [];
        $queue = self::refs($entries);
        while ($queue !== []) {
            $name = array_shift($queue);
            if (isset($out[$name]) || ! array_key_exists($name, $defs)) {
                continue;
            }
            $out[$name] = $defs[$name];
            array_push($queue, ...self::refs($defs[$name]));
        }
        ksort($out);

        return $out;
    }

    /** @return list<string> the `#/$defs/<name>` names referenced anywhere in a value */
    private static function refs(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $names = [];
        foreach ($value as $key => $item) {
            if ($key === '$ref' && is_string($item) && str_starts_with($item, '#/$defs/')) {
                $names[] = substr($item, strlen('#/$defs/'));
            } else {
                array_push($names, ...self::refs($item));
            }
        }

        return $names;
    }
}
