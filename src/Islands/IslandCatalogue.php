<?php

declare(strict_types=1);

namespace Charlotte\Islands;

/**
 * The component node types Charlotte may place and the props each takes.
 *
 * Two kinds of type:
 * - fancy-cms's BUILT-INS (`chart`, `kpi`, `table`, `leaderboard`, `input`):
 *   bare names, read from fancy-cms's own catalogue (`{$schema, $defs,
 *   types}`, via {@see CmsDescribeIslands}); `island: true` is implied.
 * - the HOST's islands: `namespace:Name` (`kit:Tabs`), placed with
 *   `island: true`.
 *
 * The default binding is {@see CmsDescribeIslands::catalogue()}, fancy-cms's
 * built-ins; {@see CmsDescribeIslands::withHost()} adds a host's own.
 */
interface IslandCatalogue
{
    /** @return list<string> component node types, e.g. `kpi` or `kit:Tabs` */
    public function types(): array;

    /** @return list<array{type: string, description: string, props: array, hydrate?: string, example?: mixed}> what the agent reads */
    public function describe(): array;

    /** @return array<string, array> the `$defs` the props schemas `$ref`, for the agent and for validation */
    public function defs(): array;

    /** Whether a type is a fancy-cms built-in (bare name, `island: true` implied). */
    public function builtin(string $type): bool;

    /** @return list<array{path: string, expected: string, got: string}> empty when the props are valid; paths start at `$` */
    public function validate(string $type, mixed $props): array;

    /** Where the catalogue came from: `fancy-cms`, `host` or `none`. */
    public function source(): string;
}
