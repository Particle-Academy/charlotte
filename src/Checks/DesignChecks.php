<?php

declare(strict_types=1);

namespace Charlotte\Checks;

/**
 * Design checks on a fancy-cms document, each a cms_finish gate except
 * `hero_visual` (a warning). Code-derived and free, like QualityChecks, whose
 * walking and style helpers they share.
 *
 * Built from the flabs Charlotte runs 504-512 (charlotte 45b503c): every page
 * passed structure and failed the reviewer's "professional" bar on the same
 * faults: a 2+1 card grid at 768 px in every run, a hero that was a bare
 * heading, nav and footer links 19-21 px tall, and long plain bullet lists.
 *
 * - `grid_orphan`: a grid whose column count at a breakpoint does not divide
 *   its visible items (a short last row), or that has more explicit columns
 *   than items (empty cells). A columnMin grid's tracks are those that fit
 *   the breakpoint's width; auto-fit collapses tracks it has no items for.
 * - `hero_thin`: the content section holding the h1 has no supporting copy,
 *   or neither a visual nor a call to action.
 * - `hero_visual` (warning): that hero has no image, frame, shape or island.
 *   A lone icon is not a visual (run 507: "a bare title with a single small
 *   icon").
 * - `tap_target`: a link or button under 44 px tall at a breakpoint, from
 *   font size x line height + vertical padding (+ a button's 1 px border),
 *   the way fancy-cms's base CSS lays it out: a button defaults to 10 px
 *   padding and line-height 1.2; a link is inline with no padding unless its
 *   parent is a stack or grid, so minHeight counts only there or on a button.
 * - `bare_list`: a list of 4 or more items. fancy-cms renders it as default
 *   bullets it cannot restyle per item, so it reads as unstyled.
 */
final class DesignChecks
{
    public const TAP_MIN = 44;

    public const LIST_MAX = 3;

    /** fancy-cms base CSS: `[data-cms-button] { padding: 10px 18px; line-height: 1.2; border: 1px }`. */
    private const BUTTON = ['padding' => 10.0, 'lineHeight' => 1.2, 'border' => 2.0];

    /** The browser default font size and a `normal` line height, when nothing sets them. */
    private const DEFAULT_FONT = 16.0;

    private const DEFAULT_LINE = 1.2;

    private const BREAKPOINTS = ['base', 'md', 'lg'];

    /** What makes a hero visual. Islands (any non-native type) count too. */
    private const VISUAL = ['image', 'frame', 'shape'];

    private const NATIVE = ['section', 'stack', 'grid', 'frame', 'shape', 'card', 'text', 'heading', 'link', 'list', 'divider', 'callout',
        'code', 'button', 'image', 'icon', 'repeater', 'input'];

    private const COPY = ['text', 'list', 'callout'];

    /** @return list<array<string, mixed>> */
    public static function check(array $doc): array
    {
        $doc = json_decode(json_encode($doc, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $nodes = is_array($doc['nodes'] ?? null) ? $doc['nodes'] : [];
        [$order, $children] = QualityChecks::tree($nodes);

        return [
            ...self::orphans($nodes, $order, $children),
            ...self::hero($nodes, $children),
            ...self::tapTargets($nodes, $order),
            ...self::lists($nodes, $order),
        ];
    }

    // ── grid_orphan ────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private static function orphans(array $nodes, array $order, array $children): array
    {
        $defects = [];
        foreach ($order as $id) {
            $node = $nodes[$id];
            if (($node['layout'] ?? null) !== 'grid') {
                continue;
            }
            foreach (self::BREAKPOINTS as $bp) {
                $items = count(array_filter($children[$id] ?? [], fn (string $c) => (QualityChecks::effective($nodes[$c], $bp)['hidden'] ?? false) !== true));
                [$tracks, $auto] = self::tracks($nodes, $id, $bp);
                if ($tracks < 2 || $items < 2) {
                    continue;
                }
                $short = $items > $tracks && $items % $tracks !== 0;
                $empty = $items < $tracks && ! $auto;
                if (! $short && ! $empty) {
                    continue;
                }
                $fit = self::fit($items, $tracks);
                $op = json_encode(['t' => 'set_style', 'id' => $id, 'breakpoint' => $bp, 'patch' => $auto ? ['columnMin' => null, 'columns' => $fit] : ['columns' => $fit]], JSON_UNESCAPED_SLASHES);
                $what = $short
                    ? sprintf('%d items in %d columns leave %d alone on the last row', $items, $tracks, $items % $tracks)
                    : sprintf('%d items in %d columns leave %d empty cell%s', $items, $tracks, $tracks - $items, $tracks - $items === 1 ? '' : 's');
                $defects[] = ['code' => 'grid_orphan', 'severity' => 'error', 'node' => $id, 'breakpoint' => $bp, 'columns' => $tracks, 'items' => $items,
                    'message' => "The grid \"{$id}\" at {$bp}: {$what}, so the row looks unfinished. Use a column count that divides the items: {$op}"
                        .'; or move the extra item out of the grid (a featured card above it).'];
            }
        }

        return $defects;
    }

    /**
     * The tracks a grid lays out at a breakpoint, and whether they are
     * auto-fit (columnMin), which collapses tracks with no items.
     *
     * @return array{0: int, 1: bool}
     */
    private static function tracks(array $nodes, string $id, string $bp): array
    {
        $style = QualityChecks::effective($nodes[$id], $bp);
        $min = QualityChecks::length($style['columnMin'] ?? null);
        if ($min > 0) {
            $gap = QualityChecks::length($style['gap'] ?? null);

            return [max(1, (int) floor((QualityChecks::width($nodes, $id, $bp) + $gap) / ($min + $gap))), true];
        }
        $columns = $style['columns'] ?? null;

        return [is_int($columns) || is_float($columns) ? max(1, (int) floor((float) $columns)) : 1, false];
    }

    /**
     * The column count to suggest: the items themselves when they fit in one
     * row, else the divisor of the items (2 to 4) nearest the current count,
     * the larger on a tie; 1 when none divides.
     */
    private static function fit(int $items, int $tracks): int
    {
        if ($items <= $tracks) {
            return $items;
        }
        $best = 1;
        foreach ([2, 3, 4] as $n) {
            if ($n <= $items && $items % $n === 0 && ($best === 1 || abs($n - $tracks) <= abs($best - $tracks))) {
                $best = $n;
            }
        }

        return $best;
    }

    // ── hero_thin, hero_visual ─────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private static function hero(array $nodes, array $children): array
    {
        $hero = null;
        foreach ($children["\0root"] ?? [] as $root) {
            if (QualityChecks::isChrome($nodes[$root])) {
                continue;
            }
            foreach (QualityChecks::subtree($root, $children) as $id) {
                if (($nodes[$id]['type'] ?? null) === 'heading' && (string) ($nodes[$id]['props']['level'] ?? '') === '1') {
                    $hero = $root;
                    break 2;
                }
            }
        }
        if ($hero === null) {
            return [];
        }

        $copy = $action = $visual = false;
        foreach (QualityChecks::subtree($hero, $children) as $id) {
            $type = (string) ($nodes[$id]['type'] ?? '');
            $props = is_array($nodes[$id]['props'] ?? null) ? $nodes[$id]['props'] : [];
            $copy = $copy || (in_array($type, self::COPY, true) && self::hasContent($props));
            $action = $action || (in_array($type, ['button', 'link'], true) && is_string($props['href'] ?? null) && trim($props['href']) !== '');
            $visual = $visual || in_array($type, self::VISUAL, true) || ($type !== '' && ! in_array($type, self::NATIVE, true));
        }

        $defects = [];
        if (! $copy || (! $action && ! $visual)) {
            $missing = implode(' and ', array_filter([$copy ? null : 'supporting copy (a lead sentence)', $action || $visual ? null : 'a call to action or visual']));
            $defects[] = ['code' => 'hero_thin', 'severity' => 'error', 'node' => $hero,
                'message' => "The hero \"{$hero}\" is little more than its heading: it lacks {$missing}. A hero needs supporting copy under the h1 "
                    .'plus a call to action (a button) or a visual (an image from cms_assets, or a shape composition).'];
        }
        if (! $visual) {
            $defects[] = ['code' => 'hero_visual', 'severity' => 'warning', 'node' => $hero,
                'message' => "The hero \"{$hero}\" has no image, frame, shape or island, so its second column reads as empty. Place a cms_assets image, "
                    .'or compose one from shapes in the brand colours; a lone icon is not enough.'];
        }

        return $defects;
    }

    private static function hasContent(array $props): bool
    {
        foreach (['content', 'items'] as $key) {
            $value = $props[$key] ?? null;
            if ((is_string($value) && trim($value) !== '') || (is_array($value) && $value !== [])) {
                return true;
            }
        }

        return false;
    }

    // ── tap_target ─────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private static function tapTargets(array $nodes, array $order): array
    {
        $defects = [];
        foreach ($order as $id) {
            $type = $nodes[$id]['type'] ?? null;
            if (! in_array($type, ['link', 'button'], true)) {
                continue;
            }
            foreach (self::BREAKPOINTS as $bp) {
                if (self::hidden($nodes, $id, $bp)) {
                    continue;
                }
                $height = self::targetHeight($nodes, $id, $bp);
                if ($height >= self::TAP_MIN) {
                    continue;
                }
                $op = json_encode(['t' => 'set_style', 'id' => $id, 'breakpoint' => 'base', 'patch' => $type === 'button'
                    ? ['paddingY' => ['value' => 14, 'unit' => 'px']]
                    : ['paddingY' => ['value' => 12, 'unit' => 'px'], 'lineHeight' => 1.25]], JSON_UNESCAPED_SLASHES);
                $defects[] = ['code' => 'tap_target', 'severity' => 'error', 'node' => $id, 'breakpoint' => $bp, 'height' => round($height, 1),
                    'message' => sprintf('The %s "%s" is about %.0f px tall at %s, under the %d px tap target, so it is hard to hit on a phone. Pad it: %s.',
                        $type, $id, $height, $bp, self::TAP_MIN, $op)];
                break;
            }
        }

        return $defects;
    }

    private static function targetHeight(array $nodes, string $id, string $bp): float
    {
        $node = $nodes[$id];
        $style = QualityChecks::effective($node, $bp);
        $button = ($node['type'] ?? null) === 'button';
        $font = self::inherited($nodes, $id, $bp, 'fontSize');
        $font = $font > 0 ? $font : self::DEFAULT_FONT;
        $line = is_int($style['lineHeight'] ?? null) || is_float($style['lineHeight'] ?? null)
            ? (float) $style['lineHeight']
            : ($button ? self::BUTTON['lineHeight'] : self::inheritedLine($nodes, $id, $bp));
        // CSS order in fancy-cms: padding, then paddingY over its top and bottom.
        $padding = array_key_exists('paddingY', $style) && is_array($style['paddingY']) ? QualityChecks::length($style['paddingY'])
            : (array_key_exists('padding', $style) && is_array($style['padding']) ? QualityChecks::length($style['padding']) : ($button ? self::BUTTON['padding'] : 0.0));
        $height = $font * $line + 2 * $padding + ($button ? self::BUTTON['border'] : 0.0);
        $parent = (string) ($node['parent'] ?? '');
        $boxed = $button || in_array($nodes[$parent]['layout'] ?? null, ['stack', 'grid'], true);

        return $boxed ? max($height, QualityChecks::length($style['minHeight'] ?? null)) : $height;
    }

    /** A length style from the node or its nearest ancestor that sets it, in px; 0 when none does. */
    private static function inherited(array $nodes, string $id, string $bp, string $key): float
    {
        $guard = 0;
        while ($id !== '' && isset($nodes[$id]) && $guard++ < 256) {
            $value = QualityChecks::length(QualityChecks::effective($nodes[$id], $bp)[$key] ?? null);
            if ($value > 0) {
                return $value;
            }
            $id = (string) ($nodes[$id]['parent'] ?? '');
        }

        return 0.0;
    }

    private static function inheritedLine(array $nodes, string $id, string $bp): float
    {
        $guard = 0;
        while ($id !== '' && isset($nodes[$id]) && $guard++ < 256) {
            $value = QualityChecks::effective($nodes[$id], $bp)['lineHeight'] ?? null;
            if ((is_int($value) || is_float($value)) && $value > 0) {
                return (float) $value;
            }
            $id = (string) ($nodes[$id]['parent'] ?? '');
        }

        return self::DEFAULT_LINE;
    }

    private static function hidden(array $nodes, string $id, string $bp): bool
    {
        $guard = 0;
        while ($id !== '' && isset($nodes[$id]) && $guard++ < 256) {
            if ((QualityChecks::effective($nodes[$id], $bp)['hidden'] ?? false) === true) {
                return true;
            }
            $id = (string) ($nodes[$id]['parent'] ?? '');
        }

        return false;
    }

    // ── bare_list ──────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private static function lists(array $nodes, array $order): array
    {
        $defects = [];
        foreach ($order as $id) {
            $items = $nodes[$id]['props']['items'] ?? null;
            if (($nodes[$id]['type'] ?? null) !== 'list' || ! is_array($items) || ! array_is_list($items) || count($items) <= self::LIST_MAX) {
                continue;
            }
            $row = json_encode(['t' => 'insert_node', 'node' => ['id' => $id.'-1', 'type' => 'stack', 'layout' => 'stack', 'parent' => '<a stack replacing '.$id.'>', 'order' => 'a0', 'props' => [],
                'style' => ['base' => ['direction' => 'row', 'justify' => 'between', 'gap' => ['value' => 16, 'unit' => 'px'], 'paddingY' => ['value' => 12, 'unit' => 'px'],
                    'boxShadow' => 'inset 0 -1px 0 var(--cms-color-line, #e5e7eb)']]]], JSON_UNESCAPED_SLASHES);
            $defects[] = ['code' => 'bare_list', 'severity' => 'error', 'node' => $id, 'items' => count($items),
                'message' => sprintf('The list "%s" has %d items as plain bullets, which read as unstyled. Remove it and build a styled list: a stack of rows, '
                    .'one per item (a label and its value or a short description, divided by a hairline), for example %s; or one card per item in a grid.', $id, count($items), $row)];
        }

        return $defects;
    }
}
