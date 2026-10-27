<?php

declare(strict_types=1);

namespace Charlotte\Checks;

use stdClass;

/**
 * Structural checks on a built page, computed from the fancy-cms document.
 *
 * Extracted from the flabs Sites lab (app/Lab/Sites/PageChecks.php) with the
 * checks unchanged; {@see facts()} is new and exposes what they are computed
 * from.
 * No model and no render, so these are exact and cost nothing; what needs the
 * render (overflow at 375 px, tap target size) comes from the capture's
 * measured facts instead.
 *
 * A defect is `['code', 'severity', 'message', 'node'?, ...]`:
 * - `error`: the page fails the brief's structural bar.
 * - `info`: the check could not be made. Reported so that "not checked" is
 *   never read as "passed".
 */
final class PageChecks
{
    public const TITLE_MAX = 60;

    public const DESCRIPTION_MAX = 155;

    private const TEXT_TYPES = ['heading', 'text', 'button', 'link', 'list'];

    private const LINK_TYPES = ['button', 'link'];

    private const SAFE_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    private const PLACEHOLDERS = [
        '/\blorem ipsum\b/i',
        '/^(feature|item|card|section|step|plan|title|heading)\s*\d+$/i',
        '/\byour (headline|title|text|tagline|company) here\b/i',
        '/\b(todo|tbd|placeholder)\b/i',
        '/\bexample text\b/i',
    ];

    /**
     * @param  array<string,mixed>  $doc  a fancy-cms PageDoc as decoded JSON
     * @return list<array<string,mixed>>
     */
    public static function check(array $doc): array
    {
        $nodes = $doc['nodes'] ?? null;
        if (! is_array($nodes) || array_is_list($nodes) && $nodes !== []) {
            return [self::defect('document', 'error', 'The page has no node map.')];
        }

        $defects = [];
        $order = self::documentOrder($nodes);
        $theme = is_array($doc['theme'] ?? null) ? $doc['theme'] : [];

        // Anchors the page declares (fancy-cms-ui renders `props.anchor` as the id).
        $anchors = [];
        foreach ($nodes as $node) {
            $anchor = is_array($node) ? ($node['props']['anchor'] ?? null) : null;
            if (is_string($anchor) && preg_match('/^[a-z][a-z0-9-]{0,63}$/', $anchor) === 1) {
                $anchors[$anchor] = true;
            }
        }

        // Headings: one h1, and levels that never skip going down the page.
        $levels = [];
        foreach ($order as $id) {
            $node = $nodes[$id];
            if (($node['type'] ?? null) === 'heading') {
                $levels[] = [$id, self::headingLevel($node)];
            }
        }
        $h1 = count(array_filter($levels, fn ($l) => $l[1] === 1));
        if ($h1 !== 1) {
            $defects[] = self::defect('h1_count', 'error', "The page has {$h1} h1 headings; it needs exactly one.");
        }
        $prev = 0;
        foreach ($levels as [$id, $level]) {
            if ($prev > 0 && $level > $prev + 1) {
                $defects[] = self::defect('heading_skip', 'error', "An h{$level} follows an h{$prev}; heading levels skip.", $id);
            }
            $prev = $level;
        }

        foreach ($order as $id) {
            $node = $nodes[$id];
            $type = (string) ($node['type'] ?? '');
            $props = is_array($node['props'] ?? null) ? $node['props'] : [];

            if ($type === 'image' && trim((string) ($props['alt'] ?? '')) === '' && ($props['decorative'] ?? false) !== true) {
                $defects[] = self::defect('image_alt', 'error', 'An image has no alt text.', $id);
            }

            if (in_array($type, self::LINK_TYPES, true)) {
                $href = trim((string) ($props['href'] ?? ''));
                if (! self::isRealHref($href)) {
                    $defects[] = self::defect('link_target', 'error', $href === '' || $href === '#'
                        ? "A {$type} links nowhere."
                        : "A {$type} has an unsafe link ({$href}).", $id);
                } elseif (str_starts_with($href, '#') && ! isset($anchors[substr($href, 1)])) {
                    $defects[] = self::defect('anchor_target', 'error', "A {$type} links to {$href}, and no section declares that anchor.", $id);
                }
            }

            foreach (self::copyOf($type, $props) as $copy) {
                foreach (self::PLACEHOLDERS as $pattern) {
                    if (preg_match($pattern, $copy) === 1) {
                        $defects[] = self::defect('placeholder_copy', 'error', "Placeholder copy: \"{$copy}\".", $id);
                        break 2;
                    }
                }
            }

            if (in_array($type, self::TEXT_TYPES, true)) {
                $defects = [...$defects, ...self::contrast($nodes, $id, $theme)];
            }
        }

        $meta = is_array($doc['meta'] ?? null) ? $doc['meta'] : [];
        $title = trim((string) ($meta['title'] ?? ''));
        $description = trim((string) ($meta['seo']['description'] ?? ''));
        if ($title === '' || $title === 'Untitled' || mb_strlen($title) > self::TITLE_MAX) {
            $defects[] = self::defect('meta_title', 'error', $title === '' || $title === 'Untitled'
                ? 'The page has no meta title.'
                : 'The meta title is '.mb_strlen($title).' characters; keep it under '.self::TITLE_MAX.'.');
        }
        if ($description === '' || mb_strlen($description) > self::DESCRIPTION_MAX) {
            $defects[] = self::defect('meta_description', 'error', $description === ''
                ? 'The page has no meta description.'
                : 'The meta description is '.mb_strlen($description).' characters; keep it under '.self::DESCRIPTION_MAX.'.');
        }

        return $defects;
    }

    /** Only the errors: what fails the structural bar. */
    public static function failures(array $defects): array
    {
        return array_values(array_filter($defects, fn (array $d) => $d['severity'] === 'error'));
    }

    /**
     * The page facts the checks read, in document order: the heading outline,
     * every image's alt and asset handle, and every link with its safety.
     *
     * @return array{outline: list<array>, images: list<array>, links: list<array>}
     */
    public static function facts(array $doc): array
    {
        $nodes = $doc['nodes'] ?? null;
        $facts = ['outline' => [], 'images' => [], 'links' => []];
        if (! is_array($nodes) || array_is_list($nodes) && $nodes !== []) {
            return $facts;
        }
        foreach (self::documentOrder($nodes) as $id) {
            $node = $nodes[$id];
            $type = (string) ($node['type'] ?? '');
            $props = is_array($node['props'] ?? null) ? $node['props'] : [];
            if ($type === 'heading') {
                $facts['outline'][] = ['node' => $id, 'level' => self::headingLevel($node), 'text' => trim((string) ($props['content'] ?? ''))];
            } elseif ($type === 'image') {
                $facts['images'][] = ['node' => $id, 'alt' => trim((string) ($props['alt'] ?? '')), 'asset' => is_string($props['asset'] ?? null) ? $props['asset'] : null];
            } elseif (in_array($type, self::LINK_TYPES, true)) {
                $href = trim(is_string($props['href'] ?? null) ? $props['href'] : '');
                $origin = self::externalOrigin($href);
                $facts['links'][] = ['node' => $id, 'href' => $href, 'safe' => self::isRealHref($href), 'external' => $origin !== null, 'origin' => $origin];
            }
        }

        return $facts;
    }

    /**
     * Visible page copy in document order, with the same walker the checks
     * use: `content`, `label` and list `items`.
     *
     * @return list<array{node: string, text: string}>
     */
    public static function copy(array $doc): array
    {
        $nodes = $doc['nodes'] ?? null;
        if (! is_array($nodes) || array_is_list($nodes) && $nodes !== []) {
            return [];
        }
        $out = [];
        foreach (self::documentOrder($nodes) as $id) {
            $node = $nodes[$id];
            foreach (self::copyOf((string) ($node['type'] ?? ''), is_array($node['props'] ?? null) ? $node['props'] : []) as $text) {
                $out[] = ['node' => $id, 'text' => $text];
            }
        }

        return $out;
    }

    /** `scheme://host[:port]` for an http(s) or protocol-relative href; null otherwise. */
    public static function externalOrigin(string $href): ?string
    {
        $probe = preg_replace('/[\x00-\x20\x7f]+/', '', $href) ?? '';
        if (str_starts_with($probe, '//')) {
            $probe = 'https:'.$probe;
        }
        if (preg_match('/^(https?):\/\/([^\/?#]+)/i', $probe, $m) !== 1) {
            return null;
        }
        $host = strtolower(preg_replace('/^[^@]*@/', '', $m[2]) ?? '');

        return strtolower($m[1]).'://'.$host;
    }

    /** Whether an href is a real, safe target (the link_target rule). */
    public static function isSafeHref(string $href): bool
    {
        return self::isRealHref(trim($href));
    }

    // ── walking ────────────────────────────────────────────────────────────

    /**
     * Node ids depth-first in page order: roots by their order key, then each
     * node's children by theirs. Fractional keys compare as strings.
     *
     * @return list<string>
     */
    private static function documentOrder(array $nodes): array
    {
        $children = [];
        foreach ($nodes as $id => $node) {
            if (! is_array($node)) {
                continue;
            }
            $parent = $node['parent'] ?? null;
            $children[$parent === null ? "\0root" : (string) $parent][] = (string) $id;
        }
        foreach ($children as &$list) {
            usort($list, fn ($a, $b) => strcmp((string) ($nodes[$a]['order'] ?? ''), (string) ($nodes[$b]['order'] ?? '')) ?: strcmp($a, $b));
        }
        unset($list);

        $out = [];
        $seen = [];
        $walk = function (string $key) use (&$walk, &$out, &$seen, $children): void {
            foreach ($children[$key] ?? [] as $id) {
                if (isset($seen[$id])) {
                    continue; // a cycle; the document validator reports those
                }
                $seen[$id] = true;
                $out[] = $id;
                $walk($id);
            }
        };
        $walk("\0root");

        return $out;
    }

    private static function headingLevel(array $node): int
    {
        $level = $node['props']['level'] ?? 2;

        return is_numeric($level) && (int) $level >= 1 && (int) $level <= 6 ? (int) $level : 2;
    }

    /** @return list<string> */
    private static function copyOf(string $type, array $props): array
    {
        $copy = [];
        foreach (['content', 'label'] as $key) {
            if (is_string($props[$key] ?? null) && trim($props[$key]) !== '') {
                $copy[] = trim($props[$key]);
            }
        }
        if ($type === 'list' && is_array($props['items'] ?? null)) {
            foreach ($props['items'] as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $copy[] = trim($item);
                }
            }
        }

        return $copy;
    }

    private static function isRealHref(string $href): bool
    {
        if ($href === '' || $href === '#') {
            return false;
        }
        $probe = preg_replace('/[\x00-\x20\x7f]+/', '', $href) ?? '';
        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $probe, $m) === 1) {
            return in_array(strtolower($m[1]), self::SAFE_SCHEMES, true);
        }

        return true; // relative, absolute path, or #anchor
    }

    // ── contrast (WCAG 2.x relative luminance) ──────────────────────────────

    /** @return list<array<string,mixed>> */
    private static function contrast(array $nodes, string $id, array $theme): array
    {
        $fg = self::inherited($nodes, $id, 'color');
        $bg = self::inherited($nodes, $id, 'background');

        $fgRgb = $fg === null ? [0, 0, 0] : self::rgb($fg, $theme);       // browser default ink
        $bgRgb = $bg === null ? [255, 255, 255] : self::rgb($bg, $theme); // browser default canvas

        if ($fgRgb === null || $bgRgb === null) {
            return [self::defect('contrast_unchecked', 'info', 'Contrast could not be computed (a gradient, image, transparent or unrecognised colour).', $id)];
        }

        $ratio = self::ratio($fgRgb, $bgRgb);
        $node = $nodes[$id];
        $needed = self::isLarge($node) ? 3.0 : 4.5;

        return $ratio + 1e-9 < $needed
            ? [self::defect('contrast', 'error', sprintf('Text contrast is %.2f:1; it needs %.1f:1.', $ratio, $needed), $id) + ['ratio' => round($ratio, 2)]]
            : [];
    }

    /** The nearest value of a style property on the node or an ancestor, at base. */
    private static function inherited(array $nodes, string $id, string $prop): ?string
    {
        $guard = 0;
        while ($id !== '' && isset($nodes[$id]) && $guard++ < 256) {
            $value = $nodes[$id]['style']['base'][$prop] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            $id = (string) ($nodes[$id]['parent'] ?? '');
        }

        return null;
    }

    private static function isLarge(array $node): bool
    {
        $size = $node['style']['base']['fontSize'] ?? null;
        if ($size instanceof stdClass) {
            $size = (array) $size;
        }
        $weight = (int) ($node['style']['base']['fontWeight'] ?? (($node['type'] ?? '') === 'heading' ? 700 : 400));
        if (is_array($size) && ($size['unit'] ?? null) === 'px' && is_numeric($size['value'] ?? null)) {
            $px = (float) $size['value'];

            return $px >= 24 || ($px >= 18.66 && $weight >= 700);
        }

        // No explicit size: browser defaults make h1-h3 large (2em, 1.5em, 1.17em bold).
        return ($node['type'] ?? '') === 'heading' && in_array(self::headingLevel($node), [1, 2, 3], true);
    }

    /**
     * A colour as [r,g,b], resolving `var(--cms-…)` through the theme, or null
     * when it cannot be known: gradients, url(), transparency, unknown names.
     *
     * @return array{0:int,1:int,2:int}|null
     */
    private static function rgb(string $value, array $theme, int $depth = 0): ?array
    {
        $value = strtolower(trim($value));

        if ($depth < 4 && preg_match('/^var\(\s*--cms-([a-z0-9-]+)\s*(?:,\s*(.+))?\)$/', $value, $m) === 1) {
            $token = self::token($theme, $m[1]);

            return $token !== null ? self::rgb($token, $theme, $depth + 1) : (isset($m[2]) ? self::rgb($m[2], $theme, $depth + 1) : null);
        }
        if (preg_match('/^#([0-9a-f]{3})$/', $value, $m) === 1) {
            return [hexdec($m[1][0].$m[1][0]), hexdec($m[1][1].$m[1][1]), hexdec($m[1][2].$m[1][2])];
        }
        if (preg_match('/^#([0-9a-f]{6})$/', $value, $m) === 1) {
            return [hexdec(substr($m[1], 0, 2)), hexdec(substr($m[1], 2, 2)), hexdec(substr($m[1], 4, 2))];
        }
        if (preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*([0-9.]+)\s*)?\)$/', $value, $m) === 1) {
            if (isset($m[4]) && (float) $m[4] < 1.0) {
                return null;
            }

            return [min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3])];
        }

        return match ($value) {
            'white' => [255, 255, 255],
            'black' => [0, 0, 0],
            default => null,
        };
    }

    /** `color-primary` -> theme['color']['primary'], matching the emitter's flattening. */
    private static function token(array $theme, string $key): ?string
    {
        $walk = function (mixed $value, string $path) use (&$walk, $key): ?string {
            if ($value instanceof stdClass) {
                $value = (array) $value;
            }
            if (is_string($value) || is_int($value) || is_float($value)) {
                return $path === $key ? (string) $value : null;
            }
            if (is_array($value) && ! array_is_list($value)) {
                foreach ($value as $k => $v) {
                    $part = preg_replace('/[^a-z0-9-]/', '', strtolower((string) $k)) ?? '';
                    $found = $walk($v, $path === '' ? $part : ($part === '' ? $path : $path.'-'.$part));
                    if ($found !== null) {
                        return $found;
                    }
                }
            }

            return null;
        };

        foreach ($theme as $k => $v) {
            if ($k === 'name') {
                continue;
            }
            $found = $walk($v, preg_replace('/[^a-z0-9-]/', '', strtolower((string) $k)) ?? '');
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private static function ratio(array $a, array $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function luminance(array $rgb): float
    {
        $c = array_map(function (int $v): float {
            $s = $v / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    /** @return array<string,mixed> */
    private static function defect(string $code, string $severity, string $message, ?string $node = null): array
    {
        return array_filter(['code' => $code, 'severity' => $severity, 'message' => $message, 'node' => $node], fn ($v) => $v !== null);
    }
}
