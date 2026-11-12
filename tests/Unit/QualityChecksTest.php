<?php

declare(strict_types=1);

use Charlotte\Brief\Brief;
use Charlotte\Checks\Checks;
use Charlotte\Checks\PageChecks;
use Charlotte\Checks\QualityChecks;

/*
 * The finish gates the flabs Charlotte benchmark (runs 423-463) asked for: every
 * page there finished "bare default", because stacks and grids carried no
 * `layout` and so rendered as plain blocks, links sat in containers with no gap,
 * nothing changed at tablet or desktop width, and required images were absent.
 */

function qpx(int $v): array
{
    return ['value' => $v, 'unit' => 'px'];
}

function qnode(string $id, string $type, ?string $parent, string $order, array $props = [], array $style = [], ?string $layout = null): array
{
    $node = ['id' => $id, 'type' => $type, 'parent' => $parent, 'order' => $order, 'props' => $props, 'style' => $style + ['base' => []]];
    if ($layout !== null) {
        $node['layout'] = $layout;
    }

    return $node;
}

/** @param list<array> $nodes */
function qdoc(array $nodes): array
{
    $map = [];
    foreach ($nodes as $node) {
        $map[$node['id']] = $node;
    }

    return ['id' => 'p', 'seq' => 0, 'meta' => ['title' => 'T', 'slug' => '/', 'scrollMode' => 'smooth', 'seo' => ['description' => 'D']],
        'theme' => [], 'breakpoints' => ['base', 'md', 'lg'], 'nodes' => $map];
}

/** A content section whose grid holds two cards: 1 column, 2 at md, 3 at lg. */
function responsiveSection(string $id = 'features', string $order = 'a1'): array
{
    return [
        qnode($id, 'section', null, $order, [], ['base' => ['paddingY' => qpx(48)], 'md' => ['paddingY' => qpx(72)]], 'stack'),
        qnode($id.'-grid', 'grid', $id, 'a0', [], ['base' => ['columns' => 1, 'gap' => qpx(24)], 'md' => ['columns' => 2], 'lg' => ['columns' => 3]], 'grid'),
        qnode($id.'-c1', 'card', $id.'-grid', 'a0', [], [], 'stack'),
        qnode($id.'-c2', 'card', $id.'-grid', 'a1', [], [], 'stack'),
    ];
}

function qbrief(array $extra = []): Brief
{
    return Brief::fromArray($extra + ['summary' => 'Crumb & Co., a bakery.']);
}

function qcodes(array $defects, ?string $severity = 'error'): array
{
    return array_values(array_map(fn ($d) => $d['code'], array_filter($defects, fn ($d) => $severity === null || $d['severity'] === $severity)));
}

function qof(array $defects, string $code): array
{
    return array_values(array_filter($defects, fn ($d) => $d['code'] === $code));
}

// ── layout_missing ─────────────────────────────────────────────────────────

test('a grid or stack with no layout is an error that names the set_layout op to send', function (): void {
    $doc = qdoc([...responsiveSection(), qnode('loose', 'grid', 'features', 'a1', [], ['base' => ['columns' => 2]]), qnode('row', 'stack', 'features', 'a2')]);

    $missing = qof(QualityChecks::check($doc, qbrief()), 'layout_missing');

    expect(array_column($missing, 'node'))->toBe(['loose', 'row'])
        ->and($missing[0]['severity'])->toBe('error')
        ->and($missing[0]['message'])->toContain('"t":"set_layout"')->toContain('"layout":"grid"')
        ->and($missing[1]['message'])->toContain('"layout":"stack"');
});

test('a section or card using flex or grid styles with no layout is an error; one with no such styles is not', function (): void {
    $doc = qdoc([...responsiveSection(),
        qnode('band', 'section', null, 'a2', [], ['base' => ['gap' => qpx(16), 'paddingY' => qpx(48)], 'md' => ['direction' => 'row']]),
        qnode('plain', 'section', null, 'a3', [], ['base' => ['paddingY' => qpx(48)], 'md' => ['paddingY' => qpx(72)]]),
    ]);

    expect(array_column(qof(QualityChecks::check($doc, qbrief()), 'layout_missing'), 'node'))->toBe(['band']);
});

test('the real lab page from run 438 is reported: not one of its stacks or grids has a layout', function (): void {
    $fixture = json_decode((string) file_get_contents(__DIR__.'/../fixtures/lab-run-438-page.json'), true);
    $flex = array_keys(array_filter($fixture['doc']['nodes'], fn ($n) => in_array($n['type'], ['stack', 'grid'], true)));

    $defects = QualityChecks::check($fixture['doc'], Brief::fromArray($fixture['brief']), $fixture['assets']);

    expect(count($flex))->toBe(17)
        ->and(array_column(qof($defects, 'layout_missing'), 'node'))->toEqualCanonicalizing($flex)
        ->and(qcodes($defects))->toContain('link_spacing')
        ->and(qcodes($defects))->toContain('responsive_layout');
});

// ── link_spacing ───────────────────────────────────────────────────────────

test('two links in a container with no gap run together, and that is an error', function (): void {
    $doc = qdoc([...responsiveSection(),
        qnode('header', 'section', null, 'a0', ['as' => 'header'], [], 'stack'),
        qnode('nav', 'stack', 'header', 'a0', ['as' => 'nav'], ['base' => ['direction' => 'row']], 'stack'),
        qnode('nav-1', 'link', 'nav', 'a0', ['content' => 'Menu', 'href' => '#menu']),
        qnode('nav-2', 'link', 'nav', 'a1', ['content' => 'Visit', 'href' => '#visit']),
    ]);

    $spacing = qof(QualityChecks::check($doc, qbrief()), 'link_spacing');

    expect(array_column($spacing, 'node'))->toBe(['nav'])
        ->and($spacing[0]['severity'])->toBe('error')
        ->and($spacing[0]['message'])->toContain('gap');
});

test('links in a stack with a gap pass; links straight inside a block are reported even when it has a gap', function (): void {
    $spaced = qdoc([...responsiveSection(),
        qnode('nav', 'stack', null, 'a0', ['as' => 'nav'], ['base' => ['direction' => 'row', 'wrap' => true, 'gap' => qpx(16)]], 'stack'),
        qnode('nav-1', 'link', 'nav', 'a0', ['content' => 'Menu', 'href' => '#menu']),
        qnode('nav-2', 'button', 'nav', 'a1', ['label' => 'Order', 'href' => '#order']),
    ]);
    $block = qdoc([...responsiveSection(),
        qnode('footer', 'section', null, 'a5', ['as' => 'footer'], ['base' => ['gap' => qpx(16)]]),
        qnode('f-1', 'link', 'footer', 'a0', ['content' => 'Menu', 'href' => '#menu']),
        qnode('f-2', 'link', 'footer', 'a1', ['content' => 'Visit', 'href' => '#visit']),
    ]);

    expect(qof(QualityChecks::check($spaced, qbrief()), 'link_spacing'))->toBe([])
        ->and(array_column(qof(QualityChecks::check($block, qbrief()), 'link_spacing'), 'node'))->toBe(['footer']);
});

// ── responsive_layout ──────────────────────────────────────────────────────

test('a page that is one column at every width fails at md and lg', function (): void {
    $doc = qdoc([
        qnode('hero', 'section', null, 'a0', [], ['base' => ['paddingY' => qpx(48)], 'md' => ['paddingY' => qpx(72)]], 'stack'),
        qnode('hero-h', 'heading', 'hero', 'a0', ['content' => 'Crumb & Co.', 'level' => 1]),
        qnode('hero-p', 'text', 'hero', 'a1', ['content' => 'Bread.']),
    ]);

    $responsive = qof(QualityChecks::check($doc, qbrief()), 'responsive_layout');

    expect(array_column($responsive, 'breakpoint'))->toBe(['md', 'lg'])
        ->and($responsive[0]['severity'])->toBe('error');
});

test('a grid of 1, 2 at md and 3 at lg makes the page adapt at both widths', function (): void {
    expect(qof(QualityChecks::check(qdoc(responsiveSection()), qbrief()), 'responsive_layout'))->toBe([]);
});

test('a columnMin grid counts at the widths where its track fits twice', function (): void {
    $grid = fn (int $min) => qdoc([
        qnode('s', 'section', null, 'a0', [], ['base' => ['paddingY' => qpx(48)], 'md' => ['paddingY' => qpx(72)]], 'stack'),
        qnode('g', 'grid', 's', 'a0', [], ['base' => ['columnMin' => qpx($min), 'gap' => qpx(24)]], 'grid'),
        qnode('c1', 'card', 'g', 'a0', [], [], 'stack'), qnode('c2', 'card', 'g', 'a1', [], [], 'stack'), qnode('c3', 'card', 'g', 'a2', [], [], 'stack'),
    ]);

    expect(qof(QualityChecks::check($grid(260), qbrief()), 'responsive_layout'))->toBe([])
        ->and(array_column(qof(QualityChecks::check($grid(400), qbrief()), 'responsive_layout'), 'breakpoint'))->toBe(['md']);
});

test('a header row and a row of buttons do not count as a desktop layout', function (): void {
    $doc = qdoc([
        qnode('header', 'section', null, 'a0', ['as' => 'header'], [], 'stack'),
        qnode('bar', 'stack', 'header', 'a0', [], ['base' => ['direction' => 'row', 'gap' => qpx(16)]], 'stack'),
        qnode('brand', 'text', 'bar', 'a0', ['content' => 'Crumb']), qnode('brand2', 'stack', 'bar', 'a1', [], [], 'stack'),
        qnode('hero', 'section', null, 'a1', [], ['base' => ['paddingY' => qpx(48)], 'md' => ['paddingY' => qpx(72)]], 'stack'),
        qnode('ctas', 'stack', 'hero', 'a0', [], ['base' => ['direction' => 'column', 'gap' => qpx(12)], 'md' => ['direction' => 'row']], 'stack'),
        qnode('b1', 'button', 'ctas', 'a0', ['label' => 'Order', 'href' => '#order']),
        qnode('b2', 'button', 'ctas', 'a1', ['label' => 'Visit', 'href' => '#visit']),
    ]);

    expect(array_column(qof(QualityChecks::check($doc, qbrief()), 'responsive_layout'), 'breakpoint'))->toBe(['md', 'lg']);
});

test('a content section with no tablet or desktop style anywhere in it is named', function (): void {
    $doc = qdoc([...responsiveSection(),
        qnode('flat', 'section', null, 'a2', [], ['base' => ['paddingY' => qpx(48)]], 'stack'),
        qnode('flat-p', 'text', 'flat', 'a0', ['content' => 'Bread.']),
    ]);

    $flat = qof(QualityChecks::check($doc, qbrief()), 'responsive_layout');

    expect(array_column($flat, 'node'))->toBe(['flat'])
        ->and($flat[0]['message'])->toContain('md');
});

// ── required images ────────────────────────────────────────────────────────

test('an image the brief names by path is required when the catalogue has it, and missing it is an error', function (): void {
    $brief = qbrief(['summary' => 'Crumb & Co. Photos of bread are available at /images/sourdough.jpg and /images/buns.jpg.']);
    $assets = [['handle' => 'hero'], ['handle' => 'sourdough'], ['handle' => 'buns']];
    $doc = qdoc([...responsiveSection(), qnode('img', 'image', 'features-c1', 'a0', ['asset' => 'sourdough', 'alt' => 'A sourdough loaf'])]);

    $missing = qof(QualityChecks::check($doc, $brief, $assets), 'required_image_missing');

    expect(array_column($missing, 'asset'))->toBe(['buns'])
        ->and($missing[0]['severity'])->toBe('error')
        ->and($missing[0]['message'])->toContain('"asset":"buns"');
});

test('the brief\'s images list is required too, and an asset inside an island\'s props counts as placed', function (): void {
    $brief = qbrief(['images' => ['hero', 'kiln']]);
    $doc = qdoc([...responsiveSection(),
        qnode('img', 'image', 'features-c1', 'a0', ['asset' => 'hero', 'alt' => 'Studio desk']),
        ['id' => 'gal', 'type' => 'kit:Gallery', 'island' => true, 'parent' => 'features-c2', 'order' => 'a0', 'props' => ['items' => [['asset' => 'kiln']]], 'style' => ['base' => []]],
    ]);

    expect(qof(QualityChecks::check($doc, $brief, [['handle' => 'hero'], ['handle' => 'kiln']]), 'required_image_missing'))->toBe([]);
});

test('an image the brief names that the catalogue cannot supply is a warning, never a gate the agent cannot clear', function (): void {
    $brief = qbrief(['summary' => 'Tidepool. Screenshots: /images/tidepool-today.png and /images/tidepool-week.png.']);

    $defects = QualityChecks::check(qdoc(responsiveSection()), $brief, [['handle' => 'hero']]);

    expect(qof($defects, 'required_image_missing'))->toBe([])
        ->and(array_column(qof($defects, 'required_image_unavailable'), 'image'))->toBe(['tidepool-today.png', 'tidepool-week.png'])
        ->and(qof($defects, 'required_image_unavailable')[0]['severity'])->toBe('warning');
});

// ── meta description ───────────────────────────────────────────────────────

test('a description written to meta.description instead of meta.seo.description says where it belongs', function (): void {
    $doc = qdoc(responsiveSection());
    unset($doc['meta']['seo']);
    $doc['meta']['description'] = 'Daily bread.';

    $meta = qof(PageChecks::check($doc), 'meta_description');

    expect($meta[0]['message'])->toContain('meta.seo.description')->toContain('"seo"');
});

// ── the gates ──────────────────────────────────────────────────────────────

test('the finish gates are exactly the quality codes, and only at error severity', function (): void {
    $defects = [
        ['code' => 'layout_missing', 'severity' => 'error'], ['code' => 'link_spacing', 'severity' => 'error'],
        ['code' => 'responsive_layout', 'severity' => 'error'], ['code' => 'required_image_missing', 'severity' => 'error'],
        ['code' => 'meta_description', 'severity' => 'error'], ['code' => 'unsupported_claim', 'severity' => 'error'],
        ['code' => 'unsupported_claim', 'severity' => 'warning'], ['code' => 'required_image_unavailable', 'severity' => 'warning'],
        ['code' => 'h1_count', 'severity' => 'error'], ['code' => 'contrast', 'severity' => 'error'],
    ];

    expect(array_column(Checks::gates($defects), 'code'))->toBe(['layout_missing', 'link_spacing', 'responsive_layout', 'required_image_missing', 'meta_description', 'unsupported_claim']);
});
