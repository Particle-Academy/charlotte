<?php

declare(strict_types=1);

use Charlotte\Checks\Checks;
use Charlotte\Checks\DesignChecks;

/*
 * The second round of finish gates, from the flabs Charlotte runs 504-512
 * (charlotte 45b503c). Every page passed structure and every page failed the
 * reviewer's "professional" bar, on the same faults each time: an orphaned card
 * at 768 px (8 of 8 runs had a 2+1 grid), a hero that was only a heading,
 * nav links 19-21 px tall, and long plain bullet lists.
 */

function dpx(int $v): array
{
    return ['value' => $v, 'unit' => 'px'];
}

function dnode(string $id, string $type, ?string $parent, string $order, array $props = [], array $style = [], ?string $layout = null): array
{
    $node = ['id' => $id, 'type' => $type, 'parent' => $parent, 'order' => $order, 'props' => $props, 'style' => $style + ['base' => []]];
    if ($layout !== null) {
        $node['layout'] = $layout;
    }

    return $node;
}

/** @param list<array> $nodes */
function ddoc(array $nodes): array
{
    $map = [];
    foreach ($nodes as $node) {
        $map[$node['id']] = $node;
    }

    return ['id' => 'p', 'seq' => 0, 'meta' => ['title' => 'T', 'seo' => ['description' => 'D']], 'theme' => [], 'breakpoints' => ['base', 'md', 'lg'], 'nodes' => $map];
}

/** A grid with $count cards and the given style. */
function dgrid(int $count, array $style, string $id = 'g'): array
{
    $nodes = [dnode('s', 'section', null, 'a1'), dnode($id, 'grid', 's', 'a0', [], $style, 'grid')];
    for ($i = 0; $i < $count; $i++) {
        $nodes[] = dnode($id.'-c'.$i, 'card', $id, 'a'.$i, [], [], 'stack');
    }

    return $nodes;
}

function dof(array $defects, string $code): array
{
    return array_values(array_filter($defects, fn ($d) => $d['code'] === $code));
}

/** A hero section: an h1 plus whatever else is given. */
function dhero(array $extra = []): array
{
    return [dnode('hero', 'section', null, 'a0'), dnode('hero-h', 'heading', 'hero', 'a0', ['content' => 'Crumb & Co.', 'level' => 1]), ...$extra];
}

function dbutton(string $id, string $parent, string $order = 'a5', array $style = []): array
{
    return dnode($id, 'button', $parent, $order, ['label' => 'Order bread', 'href' => '/order'], $style + ['base' => ['paddingY' => dpx(14)]]);
}

// ── grid_orphan ────────────────────────────────────────────────────────────

test('three cards in a grid of 2 columns at md leave an orphan, and the defect names the grid, the breakpoint and the count to use', function (): void {
    $doc = ddoc(dgrid(3, ['base' => ['columns' => 1], 'md' => ['columns' => 2], 'lg' => ['columns' => 3]]));

    $orphans = dof(DesignChecks::check($doc), 'grid_orphan');

    expect($orphans)->toHaveCount(1)
        ->and($orphans[0])->toMatchArray(['severity' => 'error', 'node' => 'g', 'breakpoint' => 'md', 'columns' => 2, 'items' => 3])
        ->and($orphans[0]['message'])->toContain('"t":"set_style"')->toContain('"id":"g"')->toContain('"breakpoint":"md"')->toContain('"columns":3');
});

test('counts that divide the items pass: 3 cards at 1/3/3, 4 at 1/2/4, 6 at 1/2/3', function (): void {
    foreach ([[3, [1, 3, 3]], [4, [1, 2, 4]], [6, [1, 2, 3]], [1, [1, 2, 3]]] as [$count, [$base, $md, $lg]]) {
        $doc = ddoc(dgrid($count, ['base' => ['columns' => $base], 'md' => ['columns' => $md], 'lg' => ['columns' => $lg]]));
        expect(dof(DesignChecks::check($doc), 'grid_orphan'))->toBe([], "{$count} items at {$base}/{$md}/{$lg}");
    }
});

test('fewer items than columns leaves empty cells, so two cards in three columns at lg are reported', function (): void {
    $doc = ddoc(dgrid(2, ['base' => ['columns' => 1], 'md' => ['columns' => 2], 'lg' => ['columns' => 3]]));

    $orphans = dof(DesignChecks::check($doc), 'grid_orphan');

    expect(array_column($orphans, 'breakpoint'))->toBe(['lg'])
        ->and($orphans[0]['message'])->toContain('"columns":2');
});

test('a columnMin grid is judged by the tracks that fit at each width, and auto-fit with fewer items than tracks is not an orphan', function (): void {
    // 280 px tracks with a 24 px gap: 2 fit in 704 px (md), 3 in 960 px (lg).
    $three = ddoc(dgrid(3, ['base' => ['columnMin' => dpx(280), 'gap' => dpx(24)]]));
    $two = ddoc(dgrid(2, ['base' => ['columnMin' => dpx(280), 'gap' => dpx(24)]]));

    expect(array_column(dof(DesignChecks::check($three), 'grid_orphan'), 'breakpoint'))->toBe(['md'])
        ->and(dof(DesignChecks::check($two), 'grid_orphan'))->toBe([]);
});

test('an orphan at phone width is reported too, and a card hidden at a breakpoint does not count there', function (): void {
    $phone = ddoc(dgrid(3, ['base' => ['columns' => 2]]));
    $hidden = dgrid(4, ['base' => ['columns' => 1], 'md' => ['columns' => 3]]);
    $hidden[5]['style']['md'] = ['hidden' => true]; // g-c3

    expect(array_column(dof(DesignChecks::check($phone), 'grid_orphan'), 'breakpoint'))->toBe(['base', 'md', 'lg'])
        ->and(dof(DesignChecks::check(ddoc($hidden)), 'grid_orphan'))->toBe([]);
});

// ── hero_thin ──────────────────────────────────────────────────────────────

test('a hero that is only a heading is thin, and the defect names the hero section', function (): void {
    $thin = dof(DesignChecks::check(ddoc(dhero())), 'hero_thin');

    expect($thin)->toHaveCount(1)
        ->and($thin[0])->toMatchArray(['severity' => 'error', 'node' => 'hero'])
        ->and($thin[0]['message'])->toContain('supporting copy')->toContain('call to action');
});

test('a hero needs supporting copy AND a visual or a call to action', function (): void {
    $copy = dnode('hero-t', 'text', 'hero', 'a1', ['content' => 'Sourdough baked every morning in Leith.']);
    $image = dnode('hero-img', 'image', 'hero', 'a2', ['asset' => 'loaf', 'alt' => 'A loaf']);

    expect(dof(DesignChecks::check(ddoc(dhero([$copy]))), 'hero_thin'))->toHaveCount(1)
        ->and(dof(DesignChecks::check(ddoc(dhero([dbutton('hero-b', 'hero')]))), 'hero_thin'))->toHaveCount(1)
        ->and(dof(DesignChecks::check(ddoc(dhero([$copy, dbutton('hero-b', 'hero')]))), 'hero_thin'))->toBe([])
        ->and(dof(DesignChecks::check(ddoc(dhero([$copy, $image]))), 'hero_thin'))->toBe([]);
});

test('the hero is the content section holding the h1, never the header, and a page with no h1 has none to judge', function (): void {
    $doc = ddoc([dnode('top', 'section', null, 'a0', ['as' => 'header']), dnode('brand', 'text', 'top', 'a0', ['content' => 'Crumb & Co.']),
        dnode('intro', 'section', null, 'a1'), dnode('intro-wrap', 'stack', 'intro', 'a0', [], [], 'stack'),
        dnode('intro-h', 'heading', 'intro-wrap', 'a0', ['content' => 'Crumb & Co.', 'level' => 1])]);
    $none = ddoc([dnode('s', 'section', null, 'a0'), dnode('s-h', 'heading', 's', 'a0', ['content' => 'Menu', 'level' => 2])]);

    expect(array_column(dof(DesignChecks::check($doc), 'hero_thin'), 'node'))->toBe(['intro'])
        ->and(dof(DesignChecks::check($none), 'hero_thin'))->toBe([]);
});

test('a hero with copy and a button but no image or composition gets a hero_visual warning; a lone icon is not a visual', function (): void {
    $copy = dnode('hero-t', 'text', 'hero', 'a1', ['content' => 'Classes six days a week.']);
    $icon = dnode('hero-i', 'icon', 'hero', 'a3', ['name' => 'dumbbell', 'decorative' => true]);
    $image = dnode('hero-img', 'image', 'hero', 'a2', ['asset' => 'gym', 'alt' => 'The gym floor']);

    $bare = dof(DesignChecks::check(ddoc(dhero([$copy, dbutton('hero-b', 'hero'), $icon]))), 'hero_visual');

    expect($bare)->toHaveCount(1)
        ->and($bare[0])->toMatchArray(['severity' => 'warning', 'node' => 'hero'])
        ->and(dof(DesignChecks::check(ddoc(dhero([$copy, dbutton('hero-b', 'hero'), $image]))), 'hero_visual'))->toBe([])
        ->and(Checks::FINISH_GATES)->not->toContain('hero_visual');
});

// ── tap_target ─────────────────────────────────────────────────────────────

test('an unstyled nav link is about 19 px tall: reported with its node, the breakpoint and the padding that fixes it', function (): void {
    $doc = ddoc([dnode('nav', 'stack', null, 'a0', ['as' => 'nav'], ['base' => ['direction' => 'row', 'gap' => dpx(16)]], 'stack'),
        dnode('nav-1', 'link', 'nav', 'a0', ['content' => 'Menu', 'href' => '#menu'])]);

    $small = dof(DesignChecks::check($doc), 'tap_target');

    expect($small)->toHaveCount(1)
        ->and($small[0])->toMatchArray(['severity' => 'error', 'node' => 'nav-1', 'breakpoint' => 'base'])
        ->and($small[0]['height'])->toBeLessThan(44)
        ->and($small[0]['message'])->toContain('"t":"set_style"')->toContain('"id":"nav-1"')->toContain('paddingY');
});

test('a link with enough padding and line height passes, measured from its own style and its ancestors\' font size', function (): void {
    $padded = ddoc([dnode('nav', 'stack', null, 'a0', [], ['base' => ['gap' => dpx(8)]], 'stack'),
        dnode('nav-1', 'link', 'nav', 'a0', ['content' => 'Menu', 'href' => '#menu'], ['base' => ['paddingY' => dpx(12), 'lineHeight' => 1.5, 'fontSize' => dpx(16)]])]);
    // 20 px inherited x 1.2 + 2 x 10 px = 44.
    $inherited = ddoc([dnode('nav', 'stack', null, 'a0', [], ['base' => ['gap' => dpx(8), 'fontSize' => dpx(20)]], 'stack'),
        dnode('nav-1', 'link', 'nav', 'a0', ['content' => 'Menu', 'href' => '#menu'], ['base' => ['paddingY' => dpx(10)]])]);

    expect(dof(DesignChecks::check($padded), 'tap_target'))->toBe([])
        ->and(dof(DesignChecks::check($inherited), 'tap_target'))->toBe([]);
});

test('fancy-cms\'s default button is 41 px tall, so a button needs paddingY 12 or more', function (): void {
    $plain = ddoc([dnode('s', 'section', null, 'a0'), dnode('b', 'button', 's', 'a0', ['label' => 'Book', 'href' => '/book'])]);
    $padded = ddoc([dnode('s', 'section', null, 'a0'), dnode('b', 'button', 's', 'a0', ['label' => 'Book', 'href' => '/book'], ['base' => ['paddingY' => dpx(12)]])]);

    expect(array_column(dof(DesignChecks::check($plain), 'tap_target'), 'node'))->toBe(['b'])
        ->and(dof(DesignChecks::check($padded), 'tap_target'))->toBe([]);
});

test('minHeight counts for a button or a link in a stack, never for an inline link in a plain block', function (): void {
    $min = ['base' => ['minHeight' => dpx(44)]];
    $flex = ddoc([dnode('row', 'stack', null, 'a0', [], ['base' => ['gap' => dpx(8)]], 'stack'), dnode('l', 'link', 'row', 'a0', ['content' => 'Call', 'href' => 'tel:1'], $min)]);
    $inline = ddoc([dnode('s', 'section', null, 'a0'), dnode('l', 'link', 's', 'a0', ['content' => 'Call', 'href' => 'tel:1'], $min)]);

    expect(dof(DesignChecks::check($flex), 'tap_target'))->toBe([])
        ->and(array_column(dof(DesignChecks::check($inline), 'tap_target'), 'node'))->toBe(['l']);
});

test('a target that shrinks at lg is reported at lg, and one hidden at a breakpoint is not judged there', function (): void {
    $shrinks = ddoc([dnode('s', 'section', null, 'a0'), dnode('b', 'button', 's', 'a0', ['label' => 'Book', 'href' => '/book'],
        ['base' => ['paddingY' => dpx(14)], 'lg' => ['paddingY' => dpx(4)]])]);
    $hidden = ddoc([dnode('s', 'section', null, 'a0'), dnode('b', 'button', 's', 'a0', ['label' => 'Book', 'href' => '/book'],
        ['base' => ['hidden' => true], 'md' => ['hidden' => false, 'paddingY' => dpx(14)]])]);

    expect(array_column(dof(DesignChecks::check($shrinks), 'tap_target'), 'breakpoint'))->toBe(['lg'])
        ->and(dof(DesignChecks::check($hidden), 'tap_target'))->toBe([]);
});

// ── bare_list ──────────────────────────────────────────────────────────────

test('a plain list of four or more items is bare; three items, or items bound to data, are not judged', function (): void {
    $list = fn (mixed $items) => ddoc([dnode('s', 'section', null, 'a0'), dnode('hours', 'list', 's', 'a0', ['items' => $items])]);

    $bare = dof(DesignChecks::check($list(['Mon 8-4', 'Tue 8-4', 'Wed 8-4', 'Thu 8-4'])), 'bare_list');

    expect($bare)->toHaveCount(1)
        ->and($bare[0])->toMatchArray(['severity' => 'error', 'node' => 'hours', 'items' => 4])
        ->and($bare[0]['message'])->toContain('stack')->toContain('card')
        ->and(dof(DesignChecks::check($list(['Mon', 'Tue', 'Wed'])), 'bare_list'))->toBe([])
        ->and(dof(DesignChecks::check($list(['$bind' => 'hours.rows'])), 'bare_list'))->toBe([]);
});

// ── the gates ──────────────────────────────────────────────────────────────

test('the four design checks are finish gates with a stated rule, and every defect is located', function (): void {
    $doc = ddoc([...dhero([dnode('hours', 'list', 'hero', 'a1', ['items' => ['a', 'b', 'c', 'd']])]),
        ...dgrid(3, ['base' => ['columns' => 1], 'md' => ['columns' => 2]])]);

    $defects = Checks::locate(DesignChecks::check($doc), 'p');

    expect(Checks::FINISH_GATES)->toContain('grid_orphan', 'hero_thin', 'tap_target', 'bare_list');
    foreach (['grid_orphan', 'hero_thin', 'bare_list'] as $code) {
        $d = dof($defects, $code)[0];
        expect($d['rule'])->toBe(Checks::RULES[$code])->and($d['node'])->toBeString()->not->toStartWith('page:');
    }
    expect(Checks::REGION_SKIP)->toContain('hero_thin', 'hero_visual');
});

test('staged, the same four checks report as warnings marked staged, and none of them holds cms_finish back', function (): void {
    $doc = ddoc([...dhero([dnode('hours', 'list', 'hero', 'a1', ['items' => ['a', 'b', 'c', 'd']]), dnode('l', 'link', 'hero', 'a2', ['content' => 'Call'])]),
        ...dgrid(3, ['base' => ['columns' => 1], 'md' => ['columns' => 2]])]);

    $live = DesignChecks::check($doc);
    $staged = DesignChecks::check($doc, staged: true);

    expect(array_column($staged, 'code'))->toBe(array_column($live, 'code'))
        ->and(array_unique(array_column(array_filter($live, fn ($d) => in_array($d['code'], Checks::STAGED_GATES, true)), 'severity')))->toBe(['error'])
        ->and(array_values(array_unique(array_column(array_filter($staged, fn ($d) => in_array($d['code'], Checks::STAGED_GATES, true)), 'severity'))))->toBe(['warning'])
        ->and(array_values(array_filter(array_column($staged, 'staged'))))->toHaveCount(count(array_filter($staged, fn ($d) => in_array($d['code'], Checks::STAGED_GATES, true))))
        ->and(Checks::gates($staged))->toBe([])
        ->and(array_values(array_unique(array_column(Checks::gates($live), 'code'))))->toEqualCanonicalizing(['hero_thin', 'bare_list', 'tap_target', 'grid_orphan'])
        ->and(Checks::STAGED_GATES)->toBe(['grid_orphan', 'hero_thin', 'tap_target', 'bare_list']);
});
