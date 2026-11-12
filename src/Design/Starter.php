<?php

declare(strict_types=1);

namespace Charlotte\Design;

use Charlotte\SiteWorkspace;

/**
 * The responsive starting point the agent builds from, as fancy-cms data:
 * a page skeleton of ops, a type scale and a spacing scale, using only
 * fancy-cms's own StyleProps and breakpoints (base, md 768, lg 1024). No CSS.
 *
 * Every page the flabs Charlotte benchmark built (runs 423-463) started from
 * nothing and ended "bare default": no container had a `layout`, so nothing
 * was a row or a grid, and nothing changed at lg. This skeleton already
 * clears every layout gate in {@see \Charlotte\Checks\QualityChecks} once
 * copy is in it, and fancy-cms renders it as real flex and grid layouts
 * (FinishGateTest renders it with fancy-cms's CssEmitter to prove it).
 *
 * Sections are plain blocks (no layout) holding one centred container, so a
 * child never shrinks to its content and nothing is positioned absolutely.
 */
final class Starter
{
    public const BREAKPOINTS = ['base' => 0, 'md' => 768, 'lg' => 1024];

    /** Styles per role, one object per breakpoint: use as a node's `style`. */
    public const TYPE_SCALE = [
        'display' => ['base' => ['fontSize' => ['value' => 40, 'unit' => 'px'], 'lineHeight' => 1.1, 'fontWeight' => 700, 'letterSpacing' => ['value' => -0.5, 'unit' => 'px']],
            'md' => ['fontSize' => ['value' => 52, 'unit' => 'px']], 'lg' => ['fontSize' => ['value' => 60, 'unit' => 'px']]],
        'h2' => ['base' => ['fontSize' => ['value' => 28, 'unit' => 'px'], 'lineHeight' => 1.2, 'fontWeight' => 700],
            'md' => ['fontSize' => ['value' => 34, 'unit' => 'px']], 'lg' => ['fontSize' => ['value' => 40, 'unit' => 'px']]],
        'h3' => ['base' => ['fontSize' => ['value' => 20, 'unit' => 'px'], 'lineHeight' => 1.3, 'fontWeight' => 600],
            'md' => ['fontSize' => ['value' => 22, 'unit' => 'px']]],
        'lead' => ['base' => ['fontSize' => ['value' => 18, 'unit' => 'px'], 'lineHeight' => 1.6],
            'md' => ['fontSize' => ['value' => 20, 'unit' => 'px']]],
        'body' => ['base' => ['fontSize' => ['value' => 17, 'unit' => 'px'], 'lineHeight' => 1.6],
            'md' => ['fontSize' => ['value' => 18, 'unit' => 'px']]],
        'small' => ['base' => ['fontSize' => ['value' => 14, 'unit' => 'px'], 'lineHeight' => 1.5]],
        'eyebrow' => ['base' => ['fontSize' => ['value' => 14, 'unit' => 'px'], 'lineHeight' => 1.4, 'fontWeight' => 600,
            'textTransform' => 'uppercase', 'letterSpacing' => ['value' => 1, 'unit' => 'px']]],
    ];

    /** A root section: vertical rhythm that grows at md and lg. */
    public const SECTION = [
        'base' => ['paddingY' => ['value' => 48, 'unit' => 'px'], 'paddingX' => ['value' => 20, 'unit' => 'px']],
        'md' => ['paddingY' => ['value' => 72, 'unit' => 'px'], 'paddingX' => ['value' => 32, 'unit' => 'px']],
        'lg' => ['paddingY' => ['value' => 96, 'unit' => 'px']],
    ];

    /** A card in a grid: padded, rounded, a hairline border, its own stack. */
    public const CARD = [
        'base' => ['padding' => ['value' => 24, 'unit' => 'px'], 'radius' => ['value' => 12, 'unit' => 'px'], 'gap' => ['value' => 12, 'unit' => 'px'],
            'border' => '1px solid var(--cms-color-line, #e5e7eb)'],
        'md' => ['padding' => ['value' => 28, 'unit' => 'px']],
    ];

    private const CONTAINER = ['maxWidth' => ['value' => 1120, 'unit' => 'px'], 'marginX' => 'auto'];

    public const SPACING = [
        'scale' => [4, 8, 12, 16, 24, 32, 48, 64, 96],
        'section' => self::SECTION,
        'container' => ['base' => self::CONTAINER],
        'textColumn' => ['base' => ['maxWidth' => ['value' => 680, 'unit' => 'px']]],
        'stackGap' => 'inside a card 12, between a heading and its text 12-16, between blocks 24-32, between a section head and its grid 32-40',
    ];

    /** What cms_describe returns under `starter`. */
    public static function describe(): array
    {
        return [
            'use' => 'Start from these. Apply skeleton.header, skeleton.page and skeleton.footer (on a site: skeleton.page on each page, header and footer once in @header and @footer), '
                .'then insert your copy into them: the brand into "header-bar" and nav links into "site-nav" (on a site, site_nav_set fills it), the h1, lead and CTA buttons into "hero-copy", the image into "hero-media", '
                .'a section heading into "features-head" and one card per item into "features-grid" (type card, layout stack, style card), the closing call to action into "cta-inner", '
                .'footer links into "footer-nav". Rename, repeat or remove sections to fit the brief. Copy a typeScale entry as a text or heading node\'s style. '
                .'Every stack and grid needs its layout; keep it when you add your own.',
            'breakpoints' => self::BREAKPOINTS,
            'typeScale' => self::TYPE_SCALE,
            'spacing' => self::SPACING,
            'card' => self::CARD,
            'skeleton' => ['header' => self::skeleton('header'), 'page' => self::skeleton('page'), 'footer' => self::skeleton('footer')],
        ];
    }

    /**
     * Insert ops for one part: `header`, `page` (hero, features grid, CTA)
     * or `footer`. Ids are fixed so the copy the agent adds can name them.
     *
     * @return list<array<string, mixed>>
     */
    public static function skeleton(string $part): array
    {
        $px = fn (int $v): array => ['value' => $v, 'unit' => 'px'];

        return match ($part) {
            'header' => [
                self::node('header', 'section', null, 'a0', ['as' => 'header'], ['base' => ['paddingY' => $px(16), 'paddingX' => $px(20)], 'md' => ['paddingX' => $px(32)]]),
                self::node('header-bar', 'stack', 'header', 'a0', [], ['base' => self::CONTAINER + ['direction' => 'column', 'gap' => $px(12), 'align' => 'start'],
                    'md' => ['direction' => 'row', 'justify' => 'between', 'align' => 'center']], 'stack'),
                // SiteWorkspace::NAV_ID, so site_nav_set fills this row instead of adding its own below it.
                self::node(SiteWorkspace::NAV_ID, 'stack', 'header-bar', 'a1', ['as' => 'nav'], ['base' => ['direction' => 'row', 'wrap' => true, 'gap' => $px(16), 'align' => 'center'],
                    'md' => ['gap' => $px(24)]], 'stack'),
            ],
            'page' => [
                self::node('hero', 'section', null, 'a1', [], self::SECTION),
                self::node('hero-grid', 'grid', 'hero', 'a0', [], ['base' => self::CONTAINER + ['columns' => 1, 'gap' => $px(32), 'align' => 'center'],
                    'lg' => ['columns' => 2, 'gap' => $px(48)]], 'grid'),
                self::node('hero-copy', 'stack', 'hero-grid', 'a0', [], ['base' => ['gap' => $px(20), 'align' => 'start']], 'stack'),
                self::node('hero-media', 'stack', 'hero-grid', 'a1', [], ['base' => ['gap' => $px(12)]], 'stack'),
                self::node('features', 'section', null, 'a2', [], self::SECTION),
                self::node('features-inner', 'stack', 'features', 'a0', [], ['base' => self::CONTAINER + ['gap' => $px(32)], 'md' => ['gap' => $px(40)]], 'stack'),
                self::node('features-head', 'stack', 'features-inner', 'a0', [], ['base' => ['gap' => $px(12), 'maxWidth' => $px(680)]], 'stack'),
                self::node('features-grid', 'grid', 'features-inner', 'a1', [], ['base' => ['columns' => 1, 'gap' => $px(24)],
                    'md' => ['columns' => 2], 'lg' => ['columns' => 3, 'gap' => $px(32)]], 'grid'),
                self::node('cta', 'section', null, 'a3', [], self::SECTION),
                self::node('cta-inner', 'stack', 'cta', 'a0', [], ['base' => ['maxWidth' => $px(720), 'marginX' => 'auto', 'gap' => $px(16), 'align' => 'center', 'textAlign' => 'center']], 'stack'),
            ],
            'footer' => [
                self::node('footer', 'section', null, 'a4', ['as' => 'footer'], ['base' => ['paddingY' => $px(40), 'paddingX' => $px(20)], 'md' => ['paddingX' => $px(32)]]),
                self::node('footer-inner', 'stack', 'footer', 'a0', [], ['base' => self::CONTAINER + ['direction' => 'column', 'gap' => $px(24), 'align' => 'start'],
                    'md' => ['direction' => 'row', 'justify' => 'between', 'align' => 'center']], 'stack'),
                self::node('footer-nav', 'stack', 'footer-inner', 'a1', ['as' => 'nav'], ['base' => ['direction' => 'row', 'wrap' => true, 'gap' => $px(16)],
                    'md' => ['gap' => $px(24)]], 'stack'),
            ],
            default => throw new \InvalidArgumentException('A skeleton part is header, page or footer.'),
        };
    }

    private static function node(string $id, string $type, ?string $parent, string $order, array $props, array $style, ?string $layout = null): array
    {
        $node = ['id' => $id, 'type' => $type, 'parent' => $parent, 'order' => $order, 'props' => $props, 'style' => $style];
        if ($layout !== null) {
            $node['layout'] = $layout;
        }

        return ['t' => 'insert_node', 'node' => $node];
    }
}
