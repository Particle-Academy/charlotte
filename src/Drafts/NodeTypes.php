<?php

declare(strict_types=1);

namespace Charlotte\Drafts;

use Charlotte\Islands\SchemaIslandCatalogue;
use FancyCms\Document\PageDoc;

/**
 * The node types any Charlotte page accepts: fancy-cms's list WITHOUT
 * `richtext`, for every actor, plus the host's island types. The browser
 * renderer injects richtext HTML as is, and a richtext node a person adds is
 * one the agent could later rewrite, so it is excluded for people as well as
 * for the agent. An island type always has a colon (`kit:Tabs`), so it can
 * never re-admit richtext.
 */
final class NodeTypes
{
    /**
     * @param  list<string>  $islands  island types from the IslandCatalogue
     * @return list<string>
     */
    public static function allowed(array $islands = []): array
    {
        $islands = array_filter($islands, fn ($t) => is_string($t) && preg_match(SchemaIslandCatalogue::TYPE, $t) === 1);

        return array_values(array_unique([...array_diff(PageDoc::NODE_TYPES, ['richtext']), ...$islands]));
    }

    /**
     * The types to load a STORED PageSession with: the allowed set plus every
     * island-shaped type the stored history already uses. A catalogue that
     * later drops a type must never make an existing draft unreadable; whether
     * such an island may be added again is the IslandPolicy's call.
     *
     * @param  list<string>  $islands
     * @return list<string>
     */
    public static function forStored(string $json, array $islands = []): array
    {
        // The op log stores each op as an encoded JSON string, so its quotes
        // arrive escaped (one or more backslashes deep); match either form.
        preg_match_all('/\\\\*"type\\\\*"\s*:\s*\\\\*"([a-z][a-z0-9-]{0,31}:[A-Za-z][A-Za-z0-9-]{0,63})\\\\*"/', $json, $m);

        return self::allowed([...$islands, ...$m[1]]);
    }

    /** A new empty page. */
    public static function emptyDocument(string $id, string $title = 'Untitled', string $slug = '/'): array
    {
        return [
            'id' => $id, 'seq' => 0,
            'meta' => ['title' => $title, 'slug' => $slug, 'scrollMode' => 'smooth'],
            'theme' => [], 'breakpoints' => ['base', 'md', 'lg'], 'nodes' => [],
        ];
    }
}
