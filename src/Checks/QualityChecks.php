<?php

declare(strict_types=1);

namespace Charlotte\Checks;

use Charlotte\Brief\Brief;

/**
 * Design-quality checks on a fancy-cms document, each a cms_finish gate
 * ({@see Checks::FINISH_GATES}). Code-derived and free, like PageChecks.
 *
 * Built from the flabs Charlotte benchmark (runs 423-463, charlotte 0.4.0):
 * 23 of 23 pages had every fact right and were judged "bare default". The
 * documents explain it. Their stacks and grids carried no `layout`, and
 * fancy-cms emits `display:flex`/`grid` only from `layout`, so `direction`,
 * `gap`, `justify` and `columns` did nothing: one column at every width, nav
 * and footer links run together. And none adapted at `lg`.
 *
 * - `layout_missing`: a stack or grid with no layout, or another container
 *   using flex/grid styles with none.
 * - `link_spacing`: two or more links or buttons side by side in a container
 *   that is not a stack or grid with a gap at base.
 * - `responsive_layout`: a content section with no md or lg style anywhere in
 *   it, or a page with no multi-column layout at md, or at lg.
 * - `required_image_missing`: an image the brief requires that no page
 *   places. Required = the brief's `images`, plus image files the brief's
 *   text names (`/images/sourdough.jpg`) that the host catalogue has under
 *   that name. One it names that the catalogue lacks is
 *   `required_image_unavailable`, a WARNING: the agent cannot place what the
 *   host never supplied, so it must never be a gate.
 */
final class QualityChecks
{
    /**
     * Content width at the narrowest viewport of each breakpoint (768 and
     * 1024) less 32 px of section padding a side: where a columnMin grid is
     * judged, conservatively.
     */
    public const WIDTHS = ['md' => 704, 'lg' => 960];

    public const PX = ['md' => 768, 'lg' => 1024];

    private const CONTAINERS = ['section', 'stack', 'grid', 'card', 'frame', 'shape'];

    private const CONTAINER_STYLE = ['direction', 'wrap', 'gap', 'columns', 'columnMin', 'justify', 'align'];

    private const LINKS = ['link', 'button'];

    /** Landmarks that are page chrome, not the content a desktop layout is judged on. */
    private const CHROME = ['header', 'footer', 'nav'];

    /** Children that do not make a column: a row of these is a button row, not a layout. */
    private const MINOR = ['link', 'button', 'icon', 'divider'];

    private const IMAGE_FILE = '~(?<![\w-])([A-Za-z0-9][\w-]*)\.(?:jpe?g|png|webp|gif|avif|svg)\b~i';

    /**
     * @param  list<array{handle: string}>  $assets  the host AssetCatalogue's catalogue()
     * @return list<array<string, mixed>>
     */
    public static function check(array $doc, Brief $brief, array $assets = []): array
    {
        $doc = json_decode(json_encode($doc, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $nodes = is_array($doc['nodes'] ?? null) ? $doc['nodes'] : [];
        [$order, $children] = self::tree($nodes);

        return [
            ...self::layout($nodes, $order),
            ...self::linkSpacing($nodes, $order, $children),
            ...self::responsive($nodes, $order, $children),
            ...self::images([$doc], $brief, $assets),
        ];
    }

    /**
     * The required-image check over several documents (a site): each
     * required image must be placed on at least one of them.
     *
     * @param  list<array>  $docs
     * @param  list<array{handle: string}>  $assets
     * @return list<array<string, mixed>>
     */
    public static function images(array $docs, Brief $brief, array $assets): array
    {
        $placed = [];
        foreach ($docs as $doc) {
            foreach (self::placed(json_decode(json_encode($doc, JSON_THROW_ON_ERROR), true)) as $handle) {
                $placed[$handle] = true;
            }
        }
        $required = self::required($brief, $assets);
        $defects = [];
        foreach ($required['handles'] as $handle) {
            if (! isset($placed[$handle])) {
                $op = json_encode(['t' => 'insert_node', 'node' => ['id' => 'img-'.$handle, 'type' => 'image', 'parent' => '<container id>', 'order' => '<key>',
                    'props' => ['asset' => $handle, 'alt' => '<what it shows>'], 'style' => ['base' => ['aspectRatio' => '4 / 3']]]], JSON_UNESCAPED_SLASHES);
                $defects[] = ['code' => 'required_image_missing', 'severity' => 'error', 'asset' => $handle,
                    'message' => "The brief requires the image \"{$handle}\" and the page does not place it. Add it where it belongs, for example {$op}."];
            }
        }
        foreach ($required['unavailable'] as $name) {
            $defects[] = ['code' => 'required_image_unavailable', 'severity' => 'warning', 'image' => $name,
                'message' => "The brief names the image {$name}, but cms_assets has no asset for it, so it cannot be placed. Do not write its path; tell the person it is missing."];
        }

        return $defects;
    }

    /**
     * What the brief requires: its `images`, then image files its text names,
     * matched by file name to the catalogue's handles.
     *
     * @param  list<array{handle: string}>  $assets
     * @return array{handles: list<string>, unavailable: list<string>}
     */
    public static function required(Brief $brief, array $assets): array
    {
        $catalogue = [];
        foreach ($assets as $asset) {
            if (is_string($asset['handle'] ?? null)) {
                $catalogue[$asset['handle']] = true;
            }
        }
        $handles = [];
        $unavailable = [];
        foreach ($brief->images as $handle) {
            if (isset($catalogue[$handle])) {
                $handles[$handle] = true;
            } else {
                $unavailable[$handle] = true;
            }
        }
        $text = implode("\n", [$brief->summary, ...array_map(fn ($f) => $f->value, $brief->facts)]);
        if (preg_match_all(self::IMAGE_FILE, $text, $m) > 0) {
            foreach ($m[0] as $i => $file) {
                $handle = trim(preg_replace('/[^a-z0-9-]+/', '-', strtolower($m[1][$i])) ?? '', '-');
                if (isset($catalogue[$handle])) {
                    $handles[$handle] = true;
                } else {
                    $unavailable[$file] = true;
                }
            }
        }

        return ['handles' => array_keys($handles), 'unavailable' => array_map('strval', array_keys($unavailable))];
    }

    /** @return list<string> every asset handle the document places: image nodes, and `asset` in any props (islands) */
    private static function placed(array $doc): array
    {
        $out = [];
        $walk = function (mixed $value) use (&$walk, &$out): void {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $key => $child) {
                if ($key === 'asset' && is_string($child)) {
                    $out[] = $child;
                } else {
                    $walk($child);
                }
            }
        };
        foreach ((array) ($doc['nodes'] ?? []) as $node) {
            if (is_array($node)) {
                $walk($node['props'] ?? []);
            }
        }

        return $out;
    }

    // ── layout_missing ─────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private static function layout(array $nodes, array $order): array
    {
        $defects = [];
        foreach ($order as $id) {
            $node = $nodes[$id];
            $type = (string) ($node['type'] ?? '');
            if (isset($node['layout']) || ! in_array($type, self::CONTAINERS, true)) {
                continue;
            }
            $used = [];
            foreach ((array) ($node['style'] ?? []) as $style) {
                foreach (array_keys(is_array($style) ? $style : []) as $key) {
                    if (in_array($key, self::CONTAINER_STYLE, true)) {
                        $used[$key] = true;
                    }
                }
            }
            if (! in_array($type, ['stack', 'grid'], true) && $used === []) {
                continue;
            }
            $layout = $type === 'grid' || isset($used['columns']) || isset($used['columnMin']) ? 'grid' : 'stack';
            $op = json_encode(['t' => 'set_layout', 'id' => $id, 'layout' => $layout], JSON_UNESCAPED_SLASHES);
            $defects[] = ['code' => 'layout_missing', 'severity' => 'error', 'node' => $id,
                'message' => "The {$type} \"{$id}\" has no layout, so it renders as a plain block"
                    .($used === [] ? '' : ' and its '.implode(', ', array_keys($used)).' do nothing')
                    .". Send {$op}, or set \"layout\" in its insert_node."];
        }

        return $defects;
    }

    // ── link_spacing ───────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private static function linkSpacing(array $nodes, array $order, array $children): array
    {
        $defects = [];
        foreach ($order as $id) {
            $links = array_filter($children[$id] ?? [], fn (string $c) => in_array($nodes[$c]['type'] ?? null, self::LINKS, true));
            if (count($links) < 2) {
                continue;
            }
            $node = $nodes[$id];
            $flex = in_array($node['layout'] ?? null, ['stack', 'grid'], true);
            if ($flex && self::length(self::effective($node, 'base')['gap'] ?? null) > 0) {
                continue;
            }
            $op = json_encode(['t' => 'set_style', 'id' => $id, 'breakpoint' => 'base', 'patch' => ['gap' => ['value' => 16, 'unit' => 'px']]], JSON_UNESCAPED_SLASHES);
            $defects[] = ['code' => 'link_spacing', 'severity' => 'error', 'node' => $id,
                'message' => count($links)." links or buttons sit side by side in \"{$id}\" with no space between them, so they run together. "
                    .($flex ? '' : 'Give it layout "stack" (direction "row", wrap true for a nav), then ')."give it a gap: {$op}."];
        }

        return $defects;
    }

    // ── responsive_layout ──────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private static function responsive(array $nodes, array $order, array $children): array
    {
        $defects = [];
        $content = array_values(array_filter($children["\0root"] ?? [], fn (string $id) => ! self::isChrome($nodes[$id])));

        foreach ($content as $root) {
            $adapts = false;
            foreach (self::subtree($root, $children) as $id) {
                $node = $nodes[$id];
                if (in_array($node['type'] ?? null, self::CONTAINERS, true)
                    && (! empty($node['style']['md']) || ! empty($node['style']['lg']))) {
                    $adapts = true;
                    break;
                }
            }
            if (! $adapts) {
                $defects[] = ['code' => 'responsive_layout', 'severity' => 'error', 'node' => $root,
                    'message' => "The section \"{$root}\" has no md or lg style on it or any container in it, so it looks the same on a tablet and a desktop as on a phone. "
                        .'Give it the section spacing scale (paddingY larger at md and lg) and, where its content suits, columns at md and lg.'];
            }
        }

        $candidates = [];
        foreach ($content as $root) {
            foreach (self::subtree($root, $children, skipChrome: $nodes) as $id) {
                $candidates[] = $id;
            }
        }
        foreach (self::PX as $bp => $px) {
            $multi = false;
            foreach ($candidates as $id) {
                if (self::columns($nodes, $id, $bp, $children) >= 2) {
                    $multi = true;
                    break;
                }
            }
            if (! $multi) {
                $op = json_encode(['t' => 'set_style', 'id' => '<grid id>', 'breakpoint' => $bp, 'patch' => ['columns' => $bp === 'md' ? 2 : 3]], JSON_UNESCAPED_SLASHES);
                $defects[] = ['code' => 'responsive_layout', 'severity' => 'error', 'breakpoint' => $bp,
                    'message' => "At {$px} px and wider every section is still one column, so the page does not use a ".($bp === 'md' ? 'tablet' : 'desktop').' width. '
                        ."Put repeated items (cards, plans, products, steps) in a grid with layout \"grid\" and {$op}, "
                        .'or set a hero or split section to a two-column grid (or a stack with direction "row") at this breakpoint.'];
            }
        }

        return $defects;
    }

    /** How many columns a container shows at a breakpoint, counting only children that make a column. */
    private static function columns(array $nodes, string $id, string $bp, array $children): int
    {
        $node = $nodes[$id];
        if (! in_array($node['type'] ?? null, self::CONTAINERS, true)) {
            return 1;
        }
        $style = self::effective($node, $bp);
        $tracks = 1;
        if (($node['layout'] ?? null) === 'grid') {
            $min = self::length($style['columnMin'] ?? null);
            if ($min > 0) {
                $gap = self::length($style['gap'] ?? null);
                $tracks = max(1, (int) floor((self::width($nodes, $id, $bp) + $gap) / ($min + $gap)));
            } elseif (is_int($style['columns'] ?? null) || is_float($style['columns'] ?? null)) {
                $tracks = max(1, (int) floor((float) $style['columns']));
            }
        } elseif (($node['layout'] ?? null) === 'stack' && ($style['direction'] ?? 'column') === 'row') {
            $tracks = PHP_INT_MAX;
        }
        $items = count(array_filter($children[$id] ?? [], fn (string $c) => ! in_array($nodes[$c]['type'] ?? null, self::MINOR, true)
            && (self::effective($nodes[$c], $bp)['hidden'] ?? false) !== true));

        return min($tracks, $items);
    }

    /** The content width a node has at a breakpoint: the breakpoint's width, capped by its own or an ancestor's px maxWidth. */
    private static function width(array $nodes, string $id, string $bp): float
    {
        $width = (float) self::WIDTHS[$bp];
        $guard = 0;
        while ($id !== '' && isset($nodes[$id]) && $guard++ < 256) {
            $value = self::effective($nodes[$id], $bp)['maxWidth'] ?? null;
            if (is_array($value) && ($value['unit'] ?? null) === 'px' && self::length($value) > 0) {
                $width = min($width, self::length($value));
            }
            $id = (string) ($nodes[$id]['parent'] ?? '');
        }

        return $width;
    }

    /** A node's style at a breakpoint: base, overlaid by md and then lg up to it. */
    private static function effective(array $node, string $bp): array
    {
        $style = [];
        foreach (['base', 'md', 'lg'] as $step) {
            if (is_array($node['style'][$step] ?? null)) {
                $style = array_replace($style, $node['style'][$step]);
            }
            if ($step === $bp) {
                break;
            }
        }

        return $style;
    }

    /** A Length's numeric value in px terms; 0 for anything else (a percentage gap is not counted). */
    private static function length(mixed $value): float
    {
        return is_array($value) && in_array($value['unit'] ?? null, ['px', 'rem'], true) && (is_int($value['value'] ?? null) || is_float($value['value'] ?? null))
            ? (float) $value['value'] * (($value['unit'] === 'rem') ? 16 : 1)
            : 0.0;
    }

    private static function isChrome(array $node): bool
    {
        return in_array($node['props']['as'] ?? null, self::CHROME, true);
    }

    // ── walking ────────────────────────────────────────────────────────────

    /**
     * Document order (roots by order key, then children) and the child lists,
     * with "\0root" holding the roots.
     *
     * @return array{0: list<string>, 1: array<string, list<string>>}
     */
    private static function tree(array $nodes): array
    {
        $children = [];
        foreach ($nodes as $id => $node) {
            if (is_array($node)) {
                $parent = $node['parent'] ?? null;
                $children[$parent === null || ! isset($nodes[$parent]) ? "\0root" : (string) $parent][] = (string) $id;
            }
        }
        foreach ($children as &$list) {
            usort($list, fn ($a, $b) => strcmp((string) ($nodes[$a]['order'] ?? ''), (string) ($nodes[$b]['order'] ?? '')) ?: strcmp($a, $b));
        }
        unset($list);

        return [self::subtree("\0root", $children, includeSelf: false), $children];
    }

    /**
     * A node and its descendants, depth-first. With $skipChrome (the node
     * map), a nav, header or footer landmark and everything in it is left out.
     *
     * @return list<string>
     */
    private static function subtree(string $root, array $children, ?array $skipChrome = null, bool $includeSelf = true): array
    {
        $out = $includeSelf ? [$root] : [];
        $seen = [$root => true];
        $stack = array_reverse($children[$root] ?? []);
        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($seen[$id]) || ($skipChrome !== null && self::isChrome($skipChrome[$id]))) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $id;
            foreach (array_reverse($children[$id] ?? []) as $child) {
                $stack[] = $child;
            }
        }

        return $out;
    }
}
