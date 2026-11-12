<?php

declare(strict_types=1);

namespace Charlotte\Data;

use Closure;
use stdClass;

/**
 * Every `$bind` in a document, read against the host {@see DataCatalogue}.
 *
 * A binding is fancy-cms's `{"$bind": "dot.path", "$source"?: [...]}`, with
 * two optional keys Charlotte adds: `$params` (the stream's declared params)
 * and `$scope` (the scope parameters the stream accepts). The path's first
 * segment names the QUERY: a stream, or `<stream>#<alias>` to bind one
 * stream twice with different params or scope. That segment is also the key
 * the host supplies the query's result under in the render data context, so
 * fancy-cms resolves the path unchanged:
 *
 *     <query>.rows                 list of row objects
 *     <query>.columns.<field>      one field's values across the rows
 *     <query>.totals.<field>       one total (streams that declare totals)
 *     <as>.<field>                 inside a node repeating over <query>.rows
 *
 * Bindings are found in node `props` (at any depth) and in `repeat.each`.
 */
final class Bindings
{
    public const KEYS = ['$bind', '$source', '$params', '$scope'];

    public const HINT = 'Bind only to streams and fields listed in cms_describe data. Scope and params are optional and only narrow; the host resolves them for each viewer.';

    private const ALIAS = '/\A[A-Za-z0-9_-]{1,32}\z/';

    /**
     * @return list<array{node: string, path: string, binding: array, repeat: bool}>
     */
    public static function find(array $doc): array
    {
        $out = [];
        foreach (self::nodes($doc) as $id => $node) {
            $walk = function (mixed $value, string $path) use (&$walk, &$out, $id): void {
                if (! is_array($value)) {
                    return;
                }
                if (array_key_exists('$bind', $value)) {
                    $out[] = ['node' => $id, 'path' => $path, 'binding' => $value, 'repeat' => false];

                    return;
                }
                foreach ($value as $key => $item) {
                    $walk($item, $path.'.'.$key);
                }
            };
            $walk($node['props'] ?? [], '$.nodes.'.$id.'.props');
            $each = is_array($node['repeat'] ?? null) ? ($node['repeat']['each'] ?? null) : null;
            if (is_array($each) && array_key_exists('$bind', $each)) {
                $out[] = ['node' => $id, 'path' => '$.nodes.'.$id.'.repeat.each', 'binding' => $each, 'repeat' => true];
            }
        }

        return $out;
    }

    /**
     * Every binding problem, as `{path, expected, got}`. With `$only`, just
     * the problems on those node ids (a write judges the nodes it changed).
     *
     * @param  list<string>|null  $only
     * @return list<array{path: string, expected: string, got: string}>
     */
    public static function validate(array $doc, DataCatalogue $catalogue, ?array $only = null): array
    {
        $streams = $catalogue->streams();
        $nodes = self::nodes($doc);
        $repeats = self::repeats($nodes, $streams);
        $errors = [];
        $queries = [];
        foreach (self::find($doc) as $found) {
            if ($only !== null && ! in_array($found['node'], $only, true)) {
                continue;
            }
            $error = self::problem($found, $nodes, $repeats, $streams, $catalogue);
            if ($error !== null) {
                $errors[] = $error;

                continue;
            }
            $key = self::queryKey($found, $nodes, $repeats);
            if ($key === null) {
                continue; // a repeat variable: no query of its own
            }
            $params = $found['binding']['$params'] ?? null;
            $scope = $found['binding']['$scope'] ?? null;
            if ($params === null && $scope === null) {
                continue;
            }
            $signature = json_encode([$params, $scope]);
            if (isset($queries[$key]) && $queries[$key]['signature'] !== $signature) {
                $field = json_encode($params) !== json_encode($queries[$key]['params']) ? '$params' : '$scope';
                $errors[] = ['path' => $found['path'].'.'.$field,
                    'expected' => "the same \$params and \$scope as {$queries[$key]['path']}, or an alias such as {$key}#<alias> for a second query",
                    'got' => 'a different '.$field.' for query '.$key];

                continue;
            }
            $queries[$key] ??= ['signature' => $signature, 'params' => $params, 'path' => $found['path']];
        }

        return $errors;
    }

    /**
     * What the host must fetch to render this document for one viewer: one
     * entry per bound query, its params, and its scope NARROWED to the viewer
     * by {@see Scope::narrow()}. Invalid bindings are skipped.
     *
     * @param  array<string, mixed>  $viewer  the viewer's whole visible scope
     * @param  array<string, mixed>  $inputs  page input values by id
     * @param  (Closure(string): list<string>)|null  $descendants
     * @return list<array{key: string, stream: string, params: array<string, string>, scope: array<string, mixed>}>
     */
    public static function queries(array $doc, DataCatalogue $catalogue, array $viewer, array $inputs = [], ?Closure $descendants = null): array
    {
        $streams = $catalogue->streams();
        $nodes = self::nodes($doc);
        $repeats = self::repeats($nodes, $streams);
        $seen = [];
        foreach (self::find($doc) as $found) {
            if (self::problem($found, $nodes, $repeats, $streams, $catalogue) !== null) {
                continue;
            }
            $key = self::queryKey($found, $nodes, $repeats);
            if ($key === null) {
                continue;
            }
            $binding = $found['binding'];
            if (! isset($seen[$key])) {
                $seen[$key] = ['key' => $key, 'stream' => explode('#', $key, 2)[0], 'params' => null, 'scope' => null];
            }
            if ($seen[$key]['params'] === null && $seen[$key]['scope'] === null && (isset($binding['$params']) || isset($binding['$scope']))) {
                $seen[$key]['params'] = (array) ($binding['$params'] ?? []);
                $seen[$key]['scope'] = (array) ($binding['$scope'] ?? []);
            }
        }
        $out = [];
        foreach ($seen as $key => $query) {
            $declared = $streams[$query['stream']]['params'];
            $params = [];
            foreach ((array) $query['params'] as $param => $value) {
                $input = Scope::input($value);
                $value = $input !== null ? ($inputs[$input] ?? null) : $value;
                if (isset($declared[$param]) && in_array($value, $declared[$param], true)) {
                    $params[$param] = $value;
                }
            }
            $out[] = ['key' => $key, 'stream' => $query['stream'], 'params' => $params,
                'scope' => Scope::narrow($catalogue, $query['stream'], $viewer, (array) $query['scope'], $inputs, $descendants)];
        }

        return $out;
    }

    /**
     * The render data context from each query's result, by query key: rows
     * and totals as the host returned them, plus `columns` derived from the
     * rows (every field's values in row order) for chart bindings.
     *
     * @param  array<string, array{rows?: list<array>, totals?: array}>  $results
     * @return array<string, array{rows: list<array>, columns: array<string, list<mixed>>, totals?: array}>
     */
    public static function context(array $results): array
    {
        $data = [];
        foreach ($results as $key => $result) {
            $rows = array_values(array_filter((array) ($result['rows'] ?? []), is_array(...)));
            $columns = [];
            foreach ($rows as $row) {
                foreach ($row as $field => $value) {
                    $columns[$field][] = $value;
                }
            }
            $data[$key] = ['rows' => $rows, 'columns' => $columns] + (isset($result['totals']) ? ['totals' => (array) $result['totals']] : []);
        }

        return $data;
    }

    /** @return array{path: string, expected: string, got: string}|null */
    private static function problem(array $found, array $nodes, array $repeats, array $streams, DataCatalogue $catalogue): ?array
    {
        $binding = $found['binding'];
        $at = $found['path'];
        foreach (array_keys($binding) as $key) {
            if (! in_array($key, self::KEYS, true)) {
                return ['path' => $at.'.'.$key, 'expected' => 'only the binding keys '.implode(', ', self::KEYS), 'got' => 'undeclared key'];
            }
        }
        $path = $binding['$bind'];
        if (! is_string($path) || $path === '' || strlen($path) > 256) {
            return ['path' => $at.'.$bind', 'expected' => 'a dot path such as metrics.rows', 'got' => is_string($path) ? 'empty or too long' : 'not a string'];
        }
        if ($streams === []) {
            return ['path' => $at.'.$bind', 'expected' => 'a stream from cms_describe data; the host declares no data streams, so nothing can be bound', 'got' => $path];
        }
        $segments = explode('.', $path);
        $first = $segments[0];

        // A repeat variable from an enclosing node: <as>.<field> of that stream's rows.
        $vars = self::visible($found, $nodes, $repeats);
        if (isset($vars[$first])) {
            $stream = $vars[$first];
            $fields = $streams[$stream]['fields'];
            if ($found['repeat'] || count($segments) !== 2 || ! isset($fields[$segments[1]])) {
                return ['path' => $at.'.$bind', 'expected' => "{$first}.<field>, a field of {$stream}: ".implode(', ', array_keys($fields)), 'got' => $path];
            }
            if (isset($binding['$params']) || isset($binding['$scope'])) {
                return ['path' => $at, 'expected' => 'no $params or $scope on a repeat variable; set them on the repeat\'s each binding', 'got' => 'params or scope'];
            }

            return null;
        }

        [$stream, $alias] = array_pad(explode('#', $first, 2), 2, null);
        if (! isset($streams[$stream]) || ($alias !== null && preg_match(self::ALIAS, $alias) !== 1)) {
            return ['path' => $at.'.$bind', 'expected' => 'a stream from cms_describe data: '.implode(', ', array_keys($streams)).($alias !== null ? ' (alias: letters, digits, _ or -)' : ''), 'got' => $path];
        }
        $declared = $streams[$stream];
        $part = $segments[1] ?? '';
        $fields = array_keys($declared['fields']);
        $shape = match ($part) {
            'rows' => count($segments) === 2 ? null : "{$first}.rows",
            'columns' => count($segments) === 3 && in_array($segments[2], $fields, true) ? null : "{$first}.columns.<field>, a field of {$stream}: ".implode(', ', $fields),
            'totals' => $declared['totals'] === null ? "a part of {$stream} other than totals: {$stream} has no totals"
                : (count($segments) === 3 && isset($declared['totals'][$segments[2]]) ? null : "{$first}.totals.<field>, one of: ".implode(', ', array_keys($declared['totals']))),
            default => "{$first}.rows, {$first}.columns.<field> or {$first}.totals.<field>",
        };
        if ($shape === null && $found['repeat'] && $part !== 'rows') {
            $shape = "{$first}.rows: repeat.each iterates rows";
        }
        if ($shape !== null) {
            return ['path' => $at.'.$bind', 'expected' => $shape, 'got' => $path];
        }

        if (array_key_exists('$params', $binding)) {
            $params = $binding['$params'];
            if (! is_array($params) || ($params !== [] && array_is_list($params))) {
                return ['path' => $at.'.$params', 'expected' => 'an object of the stream\'s params', 'got' => 'not an object'];
            }
            foreach ($params as $param => $value) {
                $allowed = $declared['params'][$param] ?? null;
                if ($allowed === null) {
                    return ['path' => $at.'.$params.'.$param, 'expected' => $declared['params'] === [] ? "no params: {$stream} declares none" : 'a param of '.$stream.': '.implode(', ', array_keys($declared['params'])), 'got' => 'undeclared param'];
                }
                if (Scope::input($value) === null && ! in_array($value, $allowed, true)) {
                    return ['path' => $at.'.$params.'.$param, 'expected' => 'one of '.implode(', ', $allowed).', or {"$input": "<id>"}', 'got' => is_string($value) ? $value : get_debug_type($value)];
                }
            }
        }

        if (array_key_exists('$scope', $binding)) {
            $scope = $binding['$scope'];
            if (! is_array($scope) || ($scope !== [] && array_is_list($scope))) {
                return ['path' => $at.'.$scope', 'expected' => 'an object of scope parameters', 'got' => 'not an object'];
            }
            $kinds = $catalogue->scope();
            foreach ($scope as $param => $value) {
                if (! in_array($param, $declared['scope'], true)) {
                    return ['path' => $at.'.$scope.'.$param, 'expected' => $declared['scope'] === [] ? "no scope: {$stream} accepts none" : 'a scope parameter '.$stream.' accepts: '.implode(', ', $declared['scope']), 'got' => 'not accepted'];
                }
                $problem = Scope::problem($kinds[$param]['kind'], $value);
                if ($problem !== null) {
                    return ['path' => $at.'.$scope.'.$param, 'expected' => $problem[0], 'got' => $problem[1]];
                }
            }
        }

        return null;
    }

    /** The query a binding belongs to (its first path segment), or null for a repeat variable. */
    private static function queryKey(array $found, array $nodes, array $repeats): ?string
    {
        $first = explode('.', (string) $found['binding']['$bind'])[0];

        return isset(self::visible($found, $nodes, $repeats)[$first]) ? null : $first;
    }

    /**
     * Repeat variables in scope for a binding: every ancestor's repeat, and
     * the node's own repeat for its props (the node itself repeats), but not
     * for its own `repeat.each`.
     *
     * @return array<string, string> as => stream
     */
    private static function visible(array $found, array $nodes, array $repeats): array
    {
        $vars = [];
        $id = $found['repeat'] ? ($nodes[$found['node']]['parent'] ?? null) : $found['node'];
        $guard = 0;
        while (is_string($id) && isset($nodes[$id]) && $guard++ < 256) {
            if (isset($repeats[$id])) {
                $vars += [$repeats[$id]['as'] => $repeats[$id]['stream']];
            }
            $id = $nodes[$id]['parent'] ?? null;
        }

        return $vars;
    }

    /** @return array<string, array{as: string, stream: string}> node id => its repeat over a stream's rows */
    private static function repeats(array $nodes, array $streams): array
    {
        $out = [];
        foreach ($nodes as $id => $node) {
            $repeat = $node['repeat'] ?? null;
            $path = is_array($repeat) && is_array($repeat['each'] ?? null) ? ($repeat['each']['$bind'] ?? null) : null;
            if (! is_string($path) || ! is_string($repeat['as'] ?? null) || $repeat['as'] === '') {
                continue;
            }
            $segments = explode('.', $path);
            $stream = explode('#', $segments[0], 2)[0];
            if (count($segments) === 2 && $segments[1] === 'rows' && isset($streams[$stream])) {
                $out[$id] = ['as' => $repeat['as'], 'stream' => $stream];
            }
        }

        return $out;
    }

    /** @return array<string, array> node id => node, as plain arrays */
    private static function nodes(array $doc): array
    {
        $nodes = $doc['nodes'] ?? [];
        $nodes = json_decode(json_encode($nodes instanceof stdClass ? (array) $nodes : $nodes) ?: '[]', true);
        if (! is_array($nodes)) {
            return [];
        }
        $out = [];
        foreach ($nodes as $id => $node) {
            if (is_array($node)) {
                $out[(string) $id] = $node;
            }
        }

        return $out;
    }
}
