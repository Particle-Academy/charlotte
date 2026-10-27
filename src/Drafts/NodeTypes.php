<?php

declare(strict_types=1);

namespace Charlotte\Drafts;

use FancyCms\Document\PageDoc;

/**
 * The node types any Charlotte page accepts: fancy-cms's list WITHOUT
 * `richtext`, for every actor. The browser renderer injects richtext HTML as
 * is, and a richtext node a person adds is one the agent could later rewrite,
 * so it is excluded for people as well as for the agent.
 */
final class NodeTypes
{
    /** @return list<string> */
    public static function allowed(): array
    {
        return array_values(array_diff(PageDoc::NODE_TYPES, ['richtext']));
    }

    /** A new empty page. */
    public static function emptyDocument(string $id): array
    {
        return [
            'id' => $id, 'seq' => 0,
            'meta' => ['title' => 'Untitled', 'slug' => '/', 'scrollMode' => 'smooth'],
            'theme' => [], 'breakpoints' => ['base', 'md', 'lg'], 'nodes' => [],
        ];
    }
}
