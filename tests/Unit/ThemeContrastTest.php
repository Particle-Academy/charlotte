<?php

declare(strict_types=1);

use Charlotte\Checks\PageChecks;
use FancyCms\Document\PageJson;
use FancyCms\Render\CssEmitter;

/*
 * Contrast resolves theme tokens exactly as fancy-cms's CssEmitter does.
 *
 * The emitter writes a `--cms-*` custom property ONLY for a token the theme
 * sets. A node style of `var(--cms-color-primary)` with no fallback, on a theme
 * that never set `primary`, is invalid at computed-value time in the browser:
 * the property behaves as `unset`, so a background is transparent (the
 * ancestor shows through) and a colour is inherited. Charlotte used to call
 * that "unrecognised" and report it as `contrast_unchecked` (info), which is
 * how flabs run 514 passed white text on the near-white hero that its reviewer
 * gated as `unreadable_contrast`.
 */

function run514(): array
{
    $fixture = json_decode((string) file_get_contents(__DIR__.'/../fixtures/lab-run-514-page.json'), true);

    return $fixture['document'];
}

/** @return array<string,array> contrast defects keyed by node */
function contrastByNode(array $doc, string $code = 'contrast'): array
{
    $out = [];
    foreach (PageChecks::check($doc) as $d) {
        if ($d['code'] === $code) {
            $out[$d['node'] ?? ''] = $d;
        }
    }

    return $out;
}

/** The `:root` declarations fancy-cms emits for a theme, parsed from its real output. */
function emittedTokens(array $theme): array
{
    $css = CssEmitter::emit(['nodes' => [], 'theme' => $theme]);
    if (preg_match('/^:root \{\n(.*?)\n\}/ms', $css, $m) !== 1) {
        return [];
    }
    $out = [];
    foreach (explode("\n", $m[1]) as $line) {
        if (preg_match('/^  --cms-([^:]+): (.*);$/', $line, $d) === 1) {
            $out[$d[1]] = $d[2];
        }
    }

    return $out;
}

/** A page with one hero, one panel inside it and one text inside the panel. */
function panelPage(array $theme, array $hero, array $panel, array $text): array
{
    return [
        'id' => 'p', 'seq' => 1,
        'meta' => ['title' => 'Panel page', 'slug' => '/', 'seo' => ['description' => 'A panel.']],
        'theme' => $theme,
        'breakpoints' => ['base'],
        'nodes' => [
            'hero' => ['id' => 'hero', 'type' => 'section', 'parent' => null, 'order' => 'a0', 'props' => [], 'style' => ['base' => $hero]],
            'h1' => ['id' => 'h1', 'type' => 'heading', 'parent' => 'hero', 'order' => 'a0', 'props' => ['content' => 'Panel page', 'level' => 1], 'style' => ['base' => []]],
            'panel' => ['id' => 'panel', 'type' => 'frame', 'parent' => 'hero', 'order' => 'a1', 'props' => [], 'style' => ['base' => $panel]],
            'fact' => ['id' => 'fact', 'type' => 'text', 'parent' => 'panel', 'order' => 'a0', 'props' => ['content' => 'Practising since 2009'], 'style' => ['base' => $text]],
        ],
    ];
}

test('run 514 replay: the hero panel text fails contrast instead of passing as unchecked', function (): void {
    $doc = run514();
    $failed = contrastByNode($doc);

    expect($failed)->toHaveKeys(['hero-media-fact', 'hero-media-sub'])
        ->and($failed['hero-media-fact']['severity'])->toBe('error')
        ->and($failed['hero-media-fact']['ratio'])->toBeLessThan(1.5)
        ->and($failed['hero-media-sub']['ratio'])->toBeLessThan(1.5)
        ->and(contrastByNode($doc, 'contrast_unchecked'))->not->toHaveKeys(['hero-media-fact', 'hero-media-sub']);
});

test('run 514 replay: only the text a browser renders unreadable fails', function (): void {
    // Unset ink/muted inherit the browser's black on the hero tint or white;
    // the CTA's primary has a fallback (#1c3d5a) under white text. All readable.
    expect(array_keys(contrastByNode(run514())))->toEqualCanonicalizing(['hero-media-fact', 'hero-media-sub']);
});

test('an unset token with no fallback makes a background transparent over its ancestor', function (): void {
    $doc = panelPage(['color' => ['accent' => '#7c2d12']], ['background' => '#f5f3ef'], ['background' => 'var(--cms-color-primary)'], ['color' => '#ffffff']);

    expect(contrastByNode($doc))->toHaveKey('fact');
});

test('a set token is used, so the same panel passes once primary exists', function (): void {
    $doc = panelPage(['color' => ['accent' => '#7c2d12', 'primary' => '#1c3d5a']], ['background' => '#f5f3ef'], ['background' => 'var(--cms-color-primary)'], ['color' => '#ffffff']);

    expect(contrastByNode($doc))->toBe([])
        ->and(contrastByNode($doc, 'contrast_unchecked'))->toBe([]);
});

test('an unset token with a fallback uses the fallback', function (): void {
    $doc = panelPage([], ['background' => '#f5f3ef'], ['background' => 'var(--cms-color-primary, #1c3d5a)'], ['color' => '#ffffff']);

    expect(contrastByNode($doc))->toBe([]);
});

test('an unset colour token inherits the ancestor colour', function (): void {
    $doc = panelPage([], ['background' => '#ffffff', 'color' => '#eeeeee'], [], ['color' => 'var(--cms-color-ink)']);

    expect(contrastByNode($doc))->toHaveKey('fact');
});

test('a token that points at another token resolves through it, and at an unset one falls back', function (): void {
    $chained = panelPage(['color' => ['primary' => 'var(--cms-color-brand)', 'brand' => '#f5f3ef']], ['background' => '#1c3d5a'], ['background' => 'var(--cms-color-primary)'], ['color' => '#ffffff']);
    $broken = panelPage(['color' => ['primary' => 'var(--cms-color-missing)']], ['background' => '#f5f3ef'], ['background' => 'var(--cms-color-primary, #1c3d5a)'], ['color' => '#ffffff']);

    expect(contrastByNode($chained))->toHaveKey('fact')
        ->and(contrastByNode($broken))->toBe([]);
});

test('custom property names are case-sensitive, as in the browser', function (): void {
    $doc = panelPage(['color' => ['primary' => '#1c3d5a']], ['background' => '#f5f3ef'], ['background' => 'var(--cms-Color-Primary)'], ['color' => '#ffffff']);

    expect(contrastByNode($doc))->toHaveKey('fact');
});

test('a stored document (stdClass theme) resolves the same tokens', function (): void {
    $doc = panelPage(['color' => ['primary' => '#1c3d5a']], ['background' => '#f5f3ef'], ['background' => 'var(--cms-color-primary)'], ['color' => '#ffffff']);
    $stored = PageJson::decodeDocument(PageJson::encodeDocument($doc));

    expect(contrastByNode($stored))->toBe([]);
});

test('theme tokens match the :root block fancy-cms emits', function (array $theme): void {
    $ours = PageChecks::themeTokens($theme);
    ksort($ours, SORT_STRING); // the emitter sorts its declarations

    expect($ours)->toBe(emittedTokens($theme))->and($ours)->not->toBe([]);
})->with([
    'flat colour group' => [['color' => ['accent' => '#7c2d12', 'ink' => '#0f172a']]],
    'name is skipped at the top only' => [['name' => 'Harbour', 'color' => ['name' => '#123456']]],
    'later duplicate wins' => [['color' => ['primary' => '#111111'], 'color-primary' => '#222222']],
    'keys are cleaned and empty parts dropped' => [['Color_Group' => ['Primary!' => '#333333', '***' => '#444444']]],
    'unsafe values are not emitted' => [['color' => ['primary' => '#fff; background: red', 'ink' => '#000000']]],
    'numbers and nesting' => [['space' => ['md' => 16], 'color' => ['brand' => ['dark' => '#0b1220']]]],
    'string fonts' => [['font' => ['body' => 'system-ui, sans-serif']]],
]);

test('a referenced token the theme never sets is a warning, and names accent-without-primary', function (): void {
    $warnings = contrastByNode(run514(), 'theme_token_unset');
    $all = array_values(array_filter(PageChecks::check(run514()), fn ($d) => $d['code'] === 'theme_token_unset'));
    $primary = array_values(array_filter($all, fn ($d) => $d['token'] === 'color-primary'));

    expect($warnings)->not->toBe([])
        ->and(array_unique(array_column($all, 'severity')))->toBe(['warning'])
        ->and($primary)->toHaveCount(1)
        ->and($primary[0]['message'])->toContain('accent')
        ->and(array_column($all, 'token'))->not->toContain('color-accent');
});

test('a token referenced only with a fallback, or set by the theme, is not warned about', function (): void {
    $doc = panelPage(['color' => ['primary' => '#1c3d5a']], ['background' => 'var(--cms-color-tint, #f5f3ef)'], ['background' => 'var(--cms-color-primary)'], ['color' => '#ffffff']);
    $codes = array_column(PageChecks::check($doc), 'code');

    expect($codes)->not->toContain('theme_token_unset');
});
