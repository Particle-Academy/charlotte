<?php

declare(strict_types=1);

namespace Charlotte\Data;

/**
 * The host's named data streams: what a page may bind to (phase 3 of the
 * report-dashboards plan).
 *
 * Bound by the host, read by `cms_describe` (under `data`), and enforced on
 * every write by {@see BindingPolicy}: a node binds ONLY to a stream listed
 * here, and only to its declared fields. The data itself never passes through
 * Charlotte: the host computes each stream per viewer at render time, with
 * the scope {@see Scope::narrow()} resolved against that viewer.
 *
 * {@see ArrayDataCatalogue::fromArray()} builds one from a declaration and
 * validates it; implement this directly only to compute the same normalised
 * shape some other way.
 */
interface DataCatalogue
{
    /**
     * @return array<string, array{
     *     description: string,
     *     fields: array<string, string>,
     *     totals: array<string, string>|null,
     *     params: array<string, list<string>>,
     *     scope: list<string>,
     * }> stream name => declaration
     */
    public function streams(): array;

    /** @return array<string, array{kind: string, description: string}> scope parameter => declaration */
    public function scope(): array;

    /** What `cms_describe` returns under `data`: the streams, the scope parameters and how to bind. */
    public function describe(): array;
}
