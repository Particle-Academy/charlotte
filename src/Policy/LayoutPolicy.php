<?php

declare(strict_types=1);

namespace Charlotte\Policy;

use FancyCms\Exceptions\PageSessionException;

/**
 * An agent may not write a `stack` or `grid` without a `layout`.
 *
 * fancy-cms emits `display:flex`/`grid` only from a node's `layout`, so a
 * stack or grid without one renders as a plain block and its direction, gap
 * and columns do nothing. 18 of 23 Charlotte pages in flabs runs 423-463
 * shipped that way. Refused with no default filled in (Prism maintainers):
 * the agent states the layout, and the error names the allowed values.
 *
 * Agent writes only (cms_apply, staged or not). Only nodes the write created
 * or changed are judged, so a person's layout-less stack stays editable by
 * the person; the cms_finish `layout_missing` gate still reports it.
 */
final class LayoutPolicy
{
    public const TYPES = ['stack', 'grid'];

    /** fancy-cms's own `layout` values. */
    public const ALLOWED = ['stack', 'grid', 'free'];

    public static function assert(array $before, array $after, ?string $revision = null): void
    {
        $old = json_decode(json_encode($before['nodes'] ?? [], JSON_THROW_ON_ERROR), true);
        $new = json_decode(json_encode($after['nodes'] ?? [], JSON_THROW_ON_ERROR), true);
        $errors = [];
        foreach (is_array($new) ? $new : [] as $id => $node) {
            if (! is_array($node) || ! in_array($node['type'] ?? null, self::TYPES, true)) {
                continue;
            }
            if (isset($old[$id]) && json_encode($old[$id]) === json_encode($node)) {
                continue;
            }
            if (in_array($node['layout'] ?? null, self::ALLOWED, true)) {
                continue;
            }
            $errors[] = ['index' => null, 'path' => '$.nodes.'.$id.'.layout',
                'expected' => 'a layout on every '.$node['type'].' node: "stack", "grid" or "free" (fancy-cms renders a '.$node['type'].' without one as a plain block)',
                'got' => array_key_exists('layout', $node) ? 'an unknown layout' : 'missing',
                'hint' => 'Set "layout" in the insert_node, or send {"t":"set_layout","id":"'.$id.'","layout":"'.($node['type'] === 'grid' ? 'grid' : 'stack').'"} in the same batch.'];
        }
        if ($errors !== []) {
            throw new PageSessionException('layout_required', $errors, $revision);
        }
    }
}
