<?php

declare(strict_types=1);

use Charlotte\Checks\PageChecks;
use FancyCms\Document\PageJson;

/*
 * Lifted from flabs tests/Feature/SitePageChecksTest.php with the assertions
 * unchanged, which is what proves the extraction kept the behaviour. Each
 * check is shown to FIRE on a page that breaks it and to stay QUIET on one
 * that does not.
 */

function goodPage(): array
{
    return [
        'id' => 'p', 'seq' => 4,
        'meta' => ['title' => 'Ledgerline: accounting for freelancers', 'slug' => '/', 'scrollMode' => 'smooth',
            'seo' => ['description' => 'Invoices, expenses and taxes in one place, without an accountant.']],
        'theme' => ['color' => ['ink' => '#0f172a', 'surface' => '#ffffff', 'primary' => '#0f766e']],
        'breakpoints' => ['base', 'md', 'lg'],
        'nodes' => [
            'hero' => ['id' => 'hero', 'type' => 'section', 'parent' => null, 'order' => 'a0', 'props' => [],
                'style' => ['base' => ['background' => 'var(--cms-color-surface)', 'color' => 'var(--cms-color-ink)']]],
            'h1' => ['id' => 'h1', 'type' => 'heading', 'parent' => 'hero', 'order' => 'a0', 'props' => ['content' => 'Ledgerline', 'level' => 1], 'style' => ['base' => []]],
            'cta' => ['id' => 'cta', 'type' => 'button', 'parent' => 'hero', 'order' => 'a1',
                'props' => ['label' => 'Start your free trial', 'href' => 'https://app.example.com/signup'],
                'style' => ['base' => ['background' => 'var(--cms-color-primary)', 'color' => '#ffffff']]],
            'feat' => ['id' => 'feat', 'type' => 'section', 'parent' => null, 'order' => 'a1', 'props' => [], 'style' => ['base' => []]],
            'h2' => ['id' => 'h2', 'type' => 'heading', 'parent' => 'feat', 'order' => 'a0', 'props' => ['content' => 'Features'], 'style' => ['base' => []]],
            'img' => ['id' => 'img', 'type' => 'image', 'parent' => 'feat', 'order' => 'a1', 'props' => ['src' => '/a.jpg', 'alt' => 'A receipt being photographed'], 'style' => ['base' => []]],
        ],
    ];
}

function checkCodes(array $doc): array
{
    return array_values(array_unique(array_column(PageChecks::check($doc), 'code')));
}

test('a good page passes every check', function (): void {
    expect(PageChecks::check(goodPage()))->toBe([]);
});

test('no h1 and two h1s both fire', function (): void {
    $none = goodPage();
    $none['nodes']['h1']['props']['level'] = 2;
    expect(checkCodes($none))->toContain('h1_count');

    $two = goodPage();
    $two['nodes']['h2']['props']['level'] = 1;
    expect(checkCodes($two))->toContain('h1_count');
});

test('a heading level that skips fires in document order', function (): void {
    $doc = goodPage();
    $doc['nodes']['h2']['props']['level'] = 3;
    expect(checkCodes($doc))->toContain('heading_skip');
});

test('an image without alt fires', function (): void {
    $doc = goodPage();
    $doc['nodes']['img']['props']['alt'] = '  ';
    expect(checkCodes($doc))->toContain('image_alt');
});

test('a placeholder or unsafe link fires', function (string $href): void {
    $doc = goodPage();
    $doc['nodes']['cta']['props']['href'] = $href;
    expect(checkCodes($doc))->toContain('link_target');
})->with(['#', '', 'javascript:alert(1)']);

test('missing or overlong meta fires', function (): void {
    $doc = goodPage();
    unset($doc['meta']['seo']);
    expect(checkCodes($doc))->toContain('meta_description');

    $doc = goodPage();
    $doc['meta']['title'] = str_repeat('Long title ', 8);
    expect(checkCodes($doc))->toContain('meta_title');
});

test('placeholder copy fires', function (string $text): void {
    $doc = goodPage();
    $doc['nodes']['h2']['props']['content'] = $text;
    expect(checkCodes($doc))->toContain('placeholder_copy');
})->with(['Lorem ipsum dolor sit amet', 'Feature 1', 'Your headline here', 'TODO: copy']);

test('low contrast text fires and resolves theme tokens', function (): void {
    $doc = goodPage();
    $doc['theme']['color']['ink'] = '#d1d5db';
    $found = array_values(array_filter(PageChecks::check($doc), fn ($d) => $d['code'] === 'contrast'));
    expect($found)->not->toBe([])
        ->and($found[0]['node'])->toBe('h1')
        ->and($found[0]['ratio'])->toBeLessThan(3.0);
});

test('a button is judged on its own background, not the section', function (): void {
    expect(checkCodes(goodPage()))->not->toContain('contrast');
});

test('a stored explicit small heading keeps the normal-text contrast threshold', function (): void {
    $doc = goodPage();
    $doc['nodes']['h2']['style']['base'] = ['fontSize' => ['value' => 16, 'unit' => 'px'], 'fontWeight' => 400, 'color' => '#777777'];
    $stored = PageJson::decodeDocument(PageJson::encodeDocument($doc));
    $found = array_values(array_filter(PageChecks::check($stored), fn ($d) => $d['code'] === 'contrast' && $d['node'] === 'h2'));
    expect($found)->not->toBeEmpty();
});

test('an unresolvable colour is reported as unchecked, never as passing', function (): void {
    $doc = goodPage();
    $doc['nodes']['hero']['style']['base']['background'] = 'linear-gradient(90deg, #000, #fff)';
    expect(checkCodes($doc))->toContain('contrast_unchecked');
});

test('a broken document is reported, not thrown', function (): void {
    expect(checkCodes(['nodes' => 'nope']))->toContain('document')
        ->and(checkCodes([]))->toContain('document');
});

// New in Charlotte: the page facts the checks are computed from, exposed so a
// UI (or the agent) can see the outline it is being judged on.
test('page facts expose the heading outline, images and links in document order', function (): void {
    $facts = PageChecks::facts(goodPage());

    expect($facts['outline'])->toBe([
        ['node' => 'h1', 'level' => 1, 'text' => 'Ledgerline'],
        ['node' => 'h2', 'level' => 2, 'text' => 'Features'],
    ])->and($facts['images'])->toBe([
        ['node' => 'img', 'alt' => 'A receipt being photographed', 'asset' => null],
    ])->and($facts['links'])->toBe([
        ['node' => 'cta', 'href' => 'https://app.example.com/signup', 'safe' => true, 'external' => true, 'origin' => 'https://app.example.com'],
    ]);
});
