<?php

declare(strict_types=1);

namespace Charlotte\Islands;

/**
 * The island types a host renders and the props each takes: what Charlotte
 * may place as `{type, island: true, props}` nodes.
 *
 * THE SEAM. fancy-cms is gaining an island catalogue exposed through
 * `cms_describe` (one JSON Schema per island type). Until that release
 * exists, Charlotte codes against this interface:
 * - {@see CmsDescribeIslands::catalogue()} (the default binding) reads
 *   `FancyCms\Agent::describe()['islands']` when the installed fancy-cms
 *   carries it, and is empty otherwise;
 * - a host can bind a {@see SchemaIslandCatalogue} built from its own list
 *   (the lab's `KitReferenceSites::ISLANDS` plus schemas) today.
 * When fancy-cms ships its catalogue, only `CmsDescribeIslands::fromDescribe`
 * may need to learn its exact shape; nothing else changes.
 */
interface IslandCatalogue
{
    /** @return list<string> island node types, e.g. `kit:Tabs` */
    public function types(): array;

    /** @return list<array{type: string, description: string, props: array}> what the agent reads */
    public function describe(): array;

    /** @return list<array{path: string, expected: string, got: string}> empty when the props are valid; paths start at `$` */
    public function validate(string $type, mixed $props): array;

    /** Where the catalogue came from: `fancy-cms`, `host` or `none`. */
    public function source(): string;
}
