<?php

declare(strict_types=1);

namespace Charlotte\Islands;

use FancyCms\Agent;
use InvalidArgumentException;

/**
 * The seam to fancy-cms's island catalogue (finding S10; plan section 1.5).
 *
 * fancy-cms 0.8.x has no catalogue: `Agent::describe()` carries no `islands`
 * key, and this returns an empty catalogue with source `none`. The release
 * being built is expected to add `islands` to `describe()` with a JSON Schema
 * per type. Its exact shape is not published yet, so both plausible shapes
 * are read:
 *
 *     ['islands' => ['kit:Tabs' => ['description' => '…', 'props' => {schema}]]]   // map
 *     ['islands' => [['type' => 'kit:Tabs', 'description' => '…', 'props' => {schema}]]] // list
 *
 * with `schema` accepted as a synonym of `props`. If fancy-cms ships a third
 * shape, this file is the only one to change.
 */
final class CmsDescribeIslands
{
    /** The default binding: fancy-cms's catalogue when present, else empty. */
    public static function catalogue(): IslandCatalogue
    {
        return self::fromDescribe(Agent::describe()) ?? SchemaIslandCatalogue::fromArray([], 'none');
    }

    /** null when `describe()` carries no island catalogue. */
    public static function fromDescribe(array $describe): ?SchemaIslandCatalogue
    {
        $islands = $describe['islands'] ?? null;
        if (! is_array($islands)) {
            return null;
        }
        $entries = [];
        if (array_is_list($islands)) {
            $entries = $islands;
        } else {
            foreach ($islands as $type => $entry) {
                $entries[] = ['type' => (string) $type] + (is_array($entry) ? $entry : []);
            }
        }
        try {
            return SchemaIslandCatalogue::fromArray($entries, 'fancy-cms');
        } catch (InvalidArgumentException) {
            return null; // a shape this version cannot read is treated as absent, never half-read
        }
    }
}
