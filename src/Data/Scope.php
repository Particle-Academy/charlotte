<?php

declare(strict_types=1);

namespace Charlotte\Data;

use Closure;
use InvalidArgumentException;

/**
 * Scope values: checked when the agent writes a binding, and NARROWED
 * against the viewer when the host renders it.
 *
 * The rule the contract enforces: a binding's scope (written by the agent,
 * or fed by a page input a visitor controls) can only narrow what the viewer
 * may already see. The host states the viewer's whole visible scope; the
 * result is the intersection, per kind:
 *
 * - `set`: the requested ids that the viewer may see; nothing requested (or
 *   an empty request, such as a multiselect left empty) means the viewer's
 *   whole set. An empty RESULT means nothing is visible.
 * - `tree`: requested ids, plus their descendants when `children` is true
 *   (the host's `$descendants` callback), then intersected with the viewer's
 *   ids, which the host passes already expanded.
 * - `period`: the overlap of the two ranges; `null` when they do not
 *   overlap, which means nothing is visible.
 *
 * It fails CLOSED: a viewer scope that does not state a parameter the stream
 * accepts is an error, never "unrestricted".
 */
final class Scope
{
    public const INPUT = '/\A[a-z][a-z0-9-]{0,47}\z/';

    private const ID = '/\A[\x21-\x7e]{1,191}\z/';

    private const DATE = '/\A\d{4}-\d{2}-\d{2}\z/';

    private const MAX_IDS = 200;

    /**
     * Why a binding-time value is not valid for its kind, or null.
     *
     * @return array{0: string, 1: string}|null [expected, got]
     */
    public static function problem(string $kind, mixed $value): ?array
    {
        if (self::input($value) !== null) {
            return null;
        }
        if (is_array($value) && array_key_exists('$input', $value)) {
            return ['{"$input": "<page input id>"} with an id of lowercase letters, digits and hyphens', 'a malformed input reference'];
        }

        return match ($kind) {
            'set' => self::ids($value) !== null ? null : ['a list of 1-'.self::MAX_IDS.' ids, or {"$input": "<id>"}', self::kind($value)],
            'tree' => self::tree($value) !== null ? null : ['{"ids": [...], "children": true|false}, or {"$input": "<id>"}', self::kind($value)],
            'period' => self::period($value) !== null ? null : ['{"from": "YYYY-MM-DD", "to": "YYYY-MM-DD"} with from <= to, or {"$input": "<id>"}', self::kind($value)],
            default => ['a declared scope kind', 'unknown kind'],
        };
    }

    /**
     * The scope a query runs with: `$requested` (a binding's `$scope`) narrowed
     * to `$viewer`. `$inputs` are the page input values by id; a malformed one
     * is ignored, so it falls back to the viewer's own scope.
     *
     * @param  array<string, mixed>  $viewer  every scope parameter the stream accepts, as the viewer may see it
     * @param  array<string, mixed>  $requested
     * @param  array<string, mixed>  $inputs
     * @param  (Closure(string): list<string>)|null  $descendants  a tree id's descendants, for `children: true`
     * @return array<string, mixed>
     */
    public static function narrow(DataCatalogue $catalogue, string $stream, array $viewer, array $requested, array $inputs = [], ?Closure $descendants = null,
        ?Closure $periods = null): array
    {
        $declared = $catalogue->streams()[$stream] ?? throw new InvalidArgumentException("Unknown stream [{$stream}].");
        $kinds = $catalogue->scope();
        $out = [];
        foreach ($declared['scope'] as $param) {
            if (! array_key_exists($param, $viewer)) {
                throw new InvalidArgumentException("The viewer scope must state [{$param}]; a missing parameter is never read as unrestricted.");
            }
            $kind = $kinds[$param]['kind'];
            $value = $requested[$param] ?? null;
            $input = self::input($value);
            if ($input !== null) {
                $value = $inputs[$input] ?? null;
            }
            $value = self::fromInput($kind, $value, $periods);
            $out[$param] = match ($kind) {
                'set' => self::narrowSet(self::ids($viewer[$param]) ?? self::viewerError($param, 'a list of ids'), self::ids($value)),
                'tree' => self::narrowTree(self::ids($viewer[$param]) ?? self::viewerError($param, 'a list of ids, already expanded'), self::tree($value), $descendants),
                'period' => self::narrowPeriod(self::period($viewer[$param]) ?? self::viewerError($param, 'a {from, to} range'), self::period($value)),
            };
        }

        return $out;
    }

    /**
     * A page input's value in the shape its parameter kind takes. fancy-cms
     * fills `{"$input"}` references with the input's value: a person or
     * select is one id, a multiselect a list, a daterange `{from, to}`, and a
     * period a PRESET (`30d`), which only the host can turn into dates
     * (`$periods`; without one, or for a preset it does not know, the value
     * narrows nothing). Anything else passes through to be checked as usual.
     */
    private static function fromInput(string $kind, mixed $value, ?Closure $periods): mixed
    {
        return match (true) {
            $kind === 'set' && is_string($value) => [$value],
            $kind === 'tree' && is_string($value) => ['ids' => [$value], 'children' => false],
            $kind === 'tree' && is_array($value) && array_is_list($value) => ['ids' => $value, 'children' => false],
            $kind === 'period' && is_string($value) => $periods === null ? null : ($periods)($value),
            default => $value,
        };
    }

    /** @return list<string> */
    private static function narrowSet(array $viewer, ?array $requested): array
    {
        // Nothing requested, or an empty selection (a multiselect left
        // empty): not narrowed by this parameter, so the viewer's own set.
        if ($requested === null || $requested === []) {
            return $viewer;
        }

        return array_values(array_filter(array_unique($requested), fn (string $id) => in_array($id, $viewer, true)));
    }

    /** @return list<string> */
    private static function narrowTree(array $viewer, ?array $requested, ?Closure $descendants): array
    {
        if ($requested === null) {
            return $viewer;
        }
        $ids = $requested['ids'];
        if ($requested['children'] && $descendants !== null) {
            foreach ($requested['ids'] as $id) {
                foreach (($descendants)($id) as $child) {
                    if (is_string($child)) {
                        $ids[] = $child;
                    }
                }
            }
        }

        return self::narrowSet($viewer, $ids);
    }

    /** @return array{from: string, to: string}|null */
    private static function narrowPeriod(array $viewer, ?array $requested): ?array
    {
        if ($requested === null) {
            return $viewer;
        }
        $from = max($viewer['from'], $requested['from']);
        $to = min($viewer['to'], $requested['to']);

        return $from <= $to ? ['from' => $from, 'to' => $to] : null;
    }

    private static function viewerError(string $param, string $expected): never
    {
        throw new InvalidArgumentException("The viewer scope [{$param}] must be {$expected}.");
    }

    /** The page input id a value refers to, or null. */
    public static function input(mixed $value): ?string
    {
        if (! is_array($value) || array_keys($value) !== ['$input'] || ! is_string($value['$input'])) {
            return null;
        }

        return preg_match(self::INPUT, $value['$input']) === 1 ? $value['$input'] : null;
    }

    /** @return list<string>|null */
    private static function ids(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > self::MAX_IDS) {
            return null;
        }
        foreach ($value as $id) {
            if (! is_string($id) || preg_match(self::ID, $id) !== 1) {
                return null;
            }
        }

        return $value;
    }

    /** @return array{ids: list<string>, children: bool}|null */
    private static function tree(mixed $value): ?array
    {
        if (! is_array($value) || array_is_list($value) || array_diff(array_keys($value), ['ids', 'children']) !== []) {
            return null;
        }
        $ids = self::ids($value['ids'] ?? null);
        $children = $value['children'] ?? false;

        return $ids === null || ! is_bool($children) ? null : ['ids' => $ids, 'children' => $children];
    }

    /** @return array{from: string, to: string}|null */
    private static function period(mixed $value): ?array
    {
        if (! is_array($value) || array_is_list($value) || array_diff(array_keys($value), ['from', 'to']) !== []) {
            return null;
        }
        foreach (['from', 'to'] as $key) {
            $date = $value[$key] ?? null;
            if (! is_string($date) || preg_match(self::DATE, $date) !== 1 || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
                return null;
            }
        }

        return $value['from'] <= $value['to'] ? ['from' => $value['from'], 'to' => $value['to']] : null;
    }

    private static function kind(mixed $value): string
    {
        return match (true) {
            is_string($value) => 'string',
            is_int($value), is_float($value) => 'number',
            is_bool($value) => 'boolean',
            $value === null => 'null',
            is_array($value) => array_is_list($value) ? 'array' : 'object',
            default => 'unsupported value',
        };
    }
}
