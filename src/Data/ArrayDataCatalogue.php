<?php

declare(strict_types=1);

namespace Charlotte\Data;

use InvalidArgumentException;

/**
 * A {@see DataCatalogue} from a declaration, validated when the host builds
 * it, so a typo surfaces at boot rather than as a refused binding mid-run.
 *
 *     Charlotte::dataCatalogue(ArrayDataCatalogue::fromArray([
 *         'scope' => [
 *             'people' => ['kind' => 'set', 'description' => 'User ids'],
 *             'period' => ['kind' => 'period'],
 *             'organizations' => ['kind' => 'tree', 'description' => 'children:true adds child organizations'],
 *         ],
 *         'streams' => [
 *             'metrics' => [
 *                 'description' => 'Pipeline metrics grouped by owner, stage or deal type.',
 *                 'fields' => ['group' => 'string', 'deals' => 'integer', 'revenue' => 'number'],
 *                 'totals' => ['deals' => 'integer', 'revenue' => 'number'],   // omit when the stream adds none
 *                 'params' => ['groupBy' => ['owner', 'stage', 'dealType']],
 *                 'scope' => ['people', 'period', 'organizations'],
 *             ],
 *         ],
 *     ]));
 *
 * Field types: string, number, integer, boolean, date. Scope kinds: `set`
 * (a list of ids), `tree` (ids, optionally with their children) and `period`
 * (an inclusive from/to date range). Each kind narrows mechanically; see
 * {@see Scope}.
 */
final class ArrayDataCatalogue implements DataCatalogue
{
    public const NAME = '/\A[A-Za-z][A-Za-z0-9_-]{0,63}\z/';

    public const FIELD_TYPES = ['string', 'number', 'integer', 'boolean', 'date'];

    public const SCOPE_KINDS = ['set', 'tree', 'period'];

    private const MAX_STREAMS = 100;

    private const MAX_FIELDS = 100;

    /**
     * @param  array<string, array{description: string, fields: array<string, string>, totals: array<string, string>|null, params: array<string, list<string>>, scope: list<string>}>  $streams
     * @param  array<string, array{kind: string, description: string}>  $scope
     */
    private function __construct(private readonly array $streams, private readonly array $scope) {}

    public static function empty(): self
    {
        return new self([], []);
    }

    /** @param array{scope?: array<string, array>, streams?: array<string, array>} $declaration */
    public static function fromArray(array $declaration): self
    {
        $scope = [];
        foreach ((array) ($declaration['scope'] ?? []) as $name => $param) {
            $kind = is_array($param) ? ($param['kind'] ?? null) : null;
            if (! is_string($name) || preg_match(self::NAME, $name) !== 1 || ! in_array($kind, self::SCOPE_KINDS, true)) {
                throw new InvalidArgumentException('Each scope parameter needs a name (letters, digits, _ or -) and a kind: '.implode(', ', self::SCOPE_KINDS).'.');
            }
            $scope[$name] = ['kind' => $kind, 'description' => self::description($param)];
        }

        $streams = [];
        $declared = (array) ($declaration['streams'] ?? []);
        if (count($declared) > self::MAX_STREAMS) {
            throw new InvalidArgumentException('A data catalogue holds at most '.self::MAX_STREAMS.' streams.');
        }
        foreach ($declared as $name => $stream) {
            if (! is_string($name) || preg_match(self::NAME, $name) !== 1 || ! is_array($stream)) {
                throw new InvalidArgumentException('Each stream needs a name of letters, digits, _ or - (no dots: a binding path splits on them).');
            }
            $fields = self::fields($stream['fields'] ?? null, $name, 'fields');
            if ($fields === []) {
                throw new InvalidArgumentException("Stream [{$name}] declares no fields.");
            }
            $totals = null;
            if (($stream['totals'] ?? null) !== null && ($stream['totals'] ?? null) !== false) {
                $totals = self::fields($stream['totals'], $name, 'totals');
                foreach (array_keys($totals) as $field) {
                    if (! isset($fields[$field])) {
                        throw new InvalidArgumentException("Stream [{$name}] totals [{$field}], which is not one of its fields.");
                    }
                }
            }
            $params = [];
            foreach ((array) ($stream['params'] ?? []) as $param => $values) {
                if (! is_string($param) || preg_match(self::NAME, $param) !== 1 || ! is_array($values) || $values === []
                    || count(array_filter($values, fn ($v) => is_string($v) && $v !== '' && strlen($v) <= 64)) !== count($values)) {
                    throw new InvalidArgumentException("Stream [{$name}] param [{$param}] needs a non-empty list of allowed string values.");
                }
                $params[$param] = array_values($values);
            }
            $accepts = [];
            foreach ((array) ($stream['scope'] ?? []) as $param) {
                if (! is_string($param) || ! isset($scope[$param])) {
                    throw new InvalidArgumentException("Stream [{$name}] accepts scope [".(is_string($param) ? $param : '?').'], which the catalogue does not declare.');
                }
                $accepts[] = $param;
            }
            $streams[$name] = ['description' => self::description($stream), 'fields' => $fields, 'totals' => $totals,
                'params' => $params, 'scope' => array_values(array_unique($accepts))];
        }

        return new self($streams, $scope);
    }

    public function streams(): array
    {
        return $this->streams;
    }

    public function scope(): array
    {
        return $this->scope;
    }

    public function describe(): array
    {
        $streams = [];
        foreach ($this->streams as $name => $stream) {
            $streams[] = ['name' => $name] + $stream;
        }

        return [
            'streams' => $streams,
            'scope' => $this->scope,
            'bind' => self::INSTRUCTIONS,
        ];
    }

    /** How to bind, in the words the agent reads. */
    public const INSTRUCTIONS = 'Bind data with {"$bind": "<stream>.<part>"} wherever a prop takes data; never type a figure in. '
        .'Parts: "<stream>.rows" (the list of row objects: table rows, leaderboard rows, repeat.each), '
        .'"<stream>.columns.<field>" (one field\'s values across the rows: chart categories and series data), '
        .'"<stream>.totals.<field>" (one total, only where the stream declares totals: kpi values). '
        .'For example metrics.rows, metrics.columns.revenue, metrics.totals.revenue. '
        .'Optional on a binding: "$params" (a stream\'s declared params, from its allowed values) and "$scope" (only the scope parameters that stream accepts: '
        .'set = a list of ids, tree = {"ids": [...], "children": true|false}, period = {"from": "YYYY-MM-DD", "to": "YYYY-MM-DD"}, or {"$input": "<page input id>"} for any of them). '
        .'Scope only narrows what the viewer may already see; the host resolves it, so you cannot widen it. '
        .'Every binding of one query shares its $params and $scope; to bind one stream twice with different ones, alias it: "metrics#byStage.rows". '
        .'Inside a node that repeats over "<stream>.rows" with as:"row", bind "row.<field>".';

    /** @return array<string, string> */
    private static function fields(mixed $fields, string $stream, string $what): array
    {
        if (! is_array($fields) || count($fields) > self::MAX_FIELDS) {
            throw new InvalidArgumentException("Stream [{$stream}] {$what} must map at most ".self::MAX_FIELDS.' field names to types.');
        }
        $out = [];
        foreach ($fields as $field => $type) {
            if (! is_string($field) || preg_match(self::NAME, $field) !== 1 || ! in_array($type, self::FIELD_TYPES, true)) {
                throw new InvalidArgumentException("Stream [{$stream}] {$what} needs field names of letters, digits, _ or - and types from: ".implode(', ', self::FIELD_TYPES).'.');
            }
            $out[$field] = $type;
        }

        return $out;
    }

    private static function description(mixed $entry): string
    {
        return is_array($entry) && is_string($entry['description'] ?? null) ? mb_substr($entry['description'], 0, 500) : '';
    }
}
