<?php

declare(strict_types=1);

namespace Charlotte\Checks;

use FancyCms\Render\CssEmitter;
use FancyCms\Render\JsValue;
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
 * - `warning`: `theme_token_unset`, a token the nodes use that the theme
 *   never sets (fancy-cms then emits nothing for it).
 * - `info`: the check could not be made. Reported so that "not checked" is
 *   never read as "passed".
 *
 * Contrast resolves theme tokens exactly as fancy-cms's CssEmitter does
 * ({@see themeTokens()}): an unset token with no fallback is `unset` in the
 * browser, so the ancestor decides, never "unrecognised" (flabs run 514).
 */
final class PageChecks
{
    private const UNSET = 'unset';

    private const TRANSPARENT = 'transparent';

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
        $tokens = self::themeTokens($doc['theme'] ?? []);

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
                $defects = [...$defects, ...self::contrast($nodes, $id, $tokens)];
            }
        }

        $defects = [...$defects, ...self::unsetTokens($nodes, $order, $tokens)];

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
                ? 'The page has no meta description. '.(is_string($meta['description'] ?? null) && trim($meta['description']) !== ''
                    ? 'It was written to meta.description, which nothing reads; the description is meta.seo.description. '
                    : '').'Set it with {"t":"set_meta","patch":{"seo":{"description":"<one sentence from the brief, under '.self::DESCRIPTION_MAX.' characters>"}}}.'
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
    private static function contrast(array $nodes, string $id, array $tokens): array
    {
        $fgRgb = self::effectiveColour($nodes, $id, 'color', $tokens, [0, 0, 0]);             // browser default ink
        $bgRgb = self::effectiveColour($nodes, $id, 'background', $tokens, [255, 255, 255]); // browser default canvas

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

    /**
     * The colour a property ends up with at base, walking the node and its
     * ancestors the way the browser does with what fancy-cms emits:
     * - a value the emitter would drop (CssEmitter::safeCssValue) is absent;
     * - a value that resolves to UNSET (a `var(--cms-…)` the theme never set,
     *   with no fallback) is invalid at computed-value time, so the property is
     *   `unset`: `color` inherits and `background` is transparent. Either way
     *   the ancestor decides, so the walk continues. `transparent` likewise;
     * - nothing on any ancestor is the browser default.
     * Null when the colour cannot be known (gradient, image, partial alpha,
     * an unknown name or a non-theme custom property).
     *
     * @param  array<string,string>  $tokens
     * @param  array{0:int,1:int,2:int}  $default
     * @return array{0:int,1:int,2:int}|null
     */
    private static function effectiveColour(array $nodes, string $id, string $prop, array $tokens, array $default): ?array
    {
        $guard = 0;
        while ($id !== '' && isset($nodes[$id]) && $guard++ < 256) {
            $value = $nodes[$id]['style']['base'][$prop] ?? null;
            if (is_string($value) && trim($value) !== '' && CssEmitter::safeCssValue($value) !== null) {
                $resolved = self::resolve($value, $tokens);
                if ($resolved === self::TRANSPARENT) {
                    if ($prop !== 'background') {
                        return null; // transparent text: nothing to measure
                    }
                } elseif ($resolved !== self::UNSET) {
                    return $resolved;
                }
            }
            $id = (string) ($nodes[$id]['parent'] ?? '');
        }

        return $default;
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
     * The `--cms-*` custom properties fancy-cms's CssEmitter writes into
     * `:root` for a theme, keyed without the `--cms-` prefix: a twin of its
     * private `themeDecls()` (no font aliases; colours never read them).
     * Only a token the theme SETS is emitted, nothing has a default, a later
     * key that flattens to the same name wins, and a value the emitter judges
     * unsafe is dropped. `ThemeContrastTest` holds this to the emitter's real
     * output, so a change in fancy-cms fails a test rather than a page.
     *
     * @return array<string,string>
     */
    public static function themeTokens(mixed $theme): array
    {
        if (! is_array($theme) && ! $theme instanceof stdClass) {
            return [];
        }
        $tokens = [];
        $walk = static function (mixed $value, array $path, int $depth) use (&$walk, &$tokens): void {
            if ($depth > 64) {
                return;
            }
            if (is_string($value) || ((is_int($value) || is_float($value)) && is_finite((float) $value))) {
                $parts = [];
                foreach ($path as $p) {
                    $key = preg_replace('/[^a-z0-9-]/', '', strtolower(str_replace(["\u{0130}", "\u{212A}"], ['i', 'k'], (string) $p)));
                    if ($key !== '' && $key !== null) {
                        $parts[] = $key;
                    }
                }
                $safe = CssEmitter::safeCssValue(JsValue::string($value));
                if ($parts !== [] && $safe !== null) {
                    $tokens[implode('-', $parts)] = $safe;
                }
            } elseif ($value instanceof stdClass || (is_array($value) && ! array_is_list($value))) {
                foreach (JsValue::entries($value) as $key => $child) {
                    $walk($child, [...$path, $key], $depth + 1);
                }
            }
        };
        foreach (JsValue::entries($theme) as $key => $value) {
            if ($key !== 'name') {
                $walk($value, [$key], 0);
            }
        }

        return $tokens;
    }

    /**
     * Resolve a style value the way the browser resolves what fancy-cms
     * emitted: [r,g,b]; UNSET when it is invalid at computed-value time (a
     * `var(--cms-…)` the theme never set, with no fallback); TRANSPARENT; or
     * null when it cannot be known (gradient, url(), partial alpha, unknown
     * names, a custom property that is not the theme's).
     *
     * @param  array<string,string>  $tokens  from themeTokens()
     * @param  list<string>  $seen  tokens being resolved (a cycle is invalid)
     * @return array{0:int,1:int,2:int}|string|null
     */
    private static function resolve(string $value, array $tokens, int $depth = 0, array $seen = []): array|string|null
    {
        $value = trim($value);
        if ($depth > 8) {
            return null;
        }

        // `var` is case-insensitive; the custom property name is not.
        if (preg_match('/^var\(\s*--([A-Za-z0-9_-]+)\s*(?:,(.*))?\)$/is', $value, $m) === 1) {
            $fallback = isset($m[2]) && trim($m[2]) !== '' ? $m[2] : null;
            if (! str_starts_with($m[1], 'cms-') || self::emitterInternal($m[1])) {
                return null; // the host's own property, or one only some fancy-cms selectors define
            }
            $name = substr($m[1], 4);
            if (array_key_exists($name, $tokens) && ! in_array($name, $seen, true)) {
                $resolved = self::resolve($tokens[$name], $tokens, $depth + 1, [...$seen, $name]);
                if ($resolved !== self::UNSET) {
                    return $resolved;
                }
                // The token itself is invalid (it names an unset token): var() takes its fallback.
            }

            return $fallback === null ? self::UNSET : self::resolve($fallback, $tokens, $depth + 1, $seen);
        }

        return self::rgb(strtolower($value));
    }

    /** `--cms-ig-*` and `--cms-chart-*` are set by fancy-cms's infographic CSS on its own selectors, not by the theme. */
    private static function emitterInternal(string $property): bool
    {
        return str_starts_with($property, 'cms-ig-') || str_starts_with($property, 'cms-chart-');
    }

    /**
     * A warning per `var(--cms-…)` the nodes use with no fallback that the
     * theme never sets: fancy-cms emits nothing for it, so the property is
     * unset (a background goes transparent, a colour inherits). Run 514: the
     * theme set `accent` and not `primary`, and a panel on `primary` vanished.
     *
     * @param  list<string>  $order
     * @param  array<string,string>  $tokens
     * @return list<array<string,mixed>>
     */
    private static function unsetTokens(array $nodes, array $order, array $tokens): array
    {
        $found = [];
        foreach ($order as $id) {
            $style = $nodes[$id]['style'] ?? null;
            if (! is_array($style)) {
                continue;
            }
            foreach ($style as $values) {
                if (! is_array($values)) {
                    continue;
                }
                foreach ($values as $value) {
                    if (! is_string($value) || preg_match_all('/var\(\s*--(cms-[A-Za-z0-9_-]+)\s*\)/i', $value, $m) < 1) {
                        continue;
                    }
                    foreach ($m[1] as $property) {
                        $name = substr($property, 4);
                        if (self::emitterInternal($property) || array_key_exists($name, $tokens)) {
                            continue;
                        }
                        $found[$name] ??= ['node' => $id, 'nodes' => []];
                        $found[$name]['nodes'][$id] = true;
                    }
                }
            }
        }

        $defects = [];
        foreach (array_slice($found, 0, 20, true) as $name => $use) {
            $message = "The page uses var(--cms-{$name}) with no fallback, and the theme never sets it, so fancy-cms emits nothing for it: "
                .'the property is unset (a background goes transparent, a colour is inherited). Set it in the theme or give the var() a fallback.';
            if ($name === 'color-primary' && array_key_exists('color-accent', $tokens)) {
                $message .= ' The theme sets color.accent but not color.primary; accent does not stand in for primary.';
            }
            $defects[] = self::defect('theme_token_unset', 'warning', $message, $use['node'])
                + ['token' => (string) $name, 'nodes' => count($use['nodes'])];
        }

        return $defects;
    }

    /**
     * A literal colour as [r,g,b]; TRANSPARENT; or null when it cannot be
     * known: gradients, url(), partial alpha, unknown names.
     *
     * @return array{0:int,1:int,2:int}|string|null
     */
    private static function rgb(string $value): array|string|null
    {
        if ($value === 'transparent') {
            return self::TRANSPARENT;
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
