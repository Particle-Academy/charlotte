<?php

declare(strict_types=1);

use Charlotte\Design\Starter;
use Charlotte\Policy\ArrayAssetCatalogue;
use Charlotte\Policy\AssetCatalogue;
use Charlotte\SiteWorkspace;
use Charlotte\Tools\PageTools;
use Charlotte\Workspace;
use FancyCms\Render\CssEmitter;

/*
 * cms_finish refuses a page that fails a quality gate (flabs runs 423-463:
 * every finished page scored "bare default"), with the reasons, and marks
 * nothing ready until they pass. Other errors stay what they were: reported.
 */

beforeEach(function (): void {
    charlotteApp(['charlotte.autonomy' => 'full']);
});

function rev(array $result): string
{
    return $result['revision'];
}

test('cms_finish on a bare page is refused with every gate it fails, and nothing is marked ready', function (): void {
    $session = openSession();
    $rev = rev(tool($session, 'cms_apply', ['ops' => sectionOps(), 'basedOn' => $session->state()['revision']]));

    $result = tool($session, 'cms_finish', ['basedOn' => $rev]);

    expect($result['isError'])->toBeTrue()
        ->and($result['revision'])->toBe($rev)
        ->and($result['error']['code'])->toBe('not_ready')
        ->and($result['error']['message'])->toContain('cms_finish')
        ->and(array_unique(array_column($result['error']['defects'], 'code')))->toEqualCanonicalizing(['meta_description', 'responsive_layout'])
        ->and($session->state()['readyRevision'])->toBeNull()
        ->and(eventsOf($session, 'draft.ready'))->toBe([]);
});

test('the same page finishes once the gates pass', function (): void {
    $session = openSession();
    $rev = rev(tool($session, 'cms_apply', ['ops' => finishablePageOps(), 'basedOn' => $session->state()['revision']]));

    $result = tool($session, 'cms_finish', ['basedOn' => $rev]);

    expect($result['ready'] ?? null)->toBeTrue()
        ->and($session->state()['readyRevision'])->toBe($rev);
});

test('a gate-free error such as a second h1 is still only reported, as before', function (): void {
    $session = openSession();
    $ops = [...finishablePageOps(), ['t' => 'insert_node', 'node' => ['id' => 'h1-again', 'type' => 'heading', 'parent' => 'plan-a', 'order' => 'b0',
        'props' => ['content' => 'Acme again', 'level' => 1], 'style' => ['base' => []]]]];
    $rev = rev(tool($session, 'cms_apply', ['ops' => $ops, 'basedOn' => $session->state()['revision']]));

    $result = tool($session, 'cms_finish', ['basedOn' => $rev]);

    expect($result['ready'] ?? null)->toBeTrue()
        ->and($result['checks']['errors'])->toBeGreaterThan(0);
});

test('run 427, replayed: the real page is refused for the meta description it wrote to the wrong key, and for nothing else', function (): void {
    $fixture = json_decode((string) file_get_contents(__DIR__.'/../fixtures/lab-run-427-page.json'), true);
    preg_match_all('~https?://[a-z0-9.-]+~', $fixture['brief']['summary'], $origins); // what the lab allowlists from its brief
    app()->instance(\Charlotte\Policy\LinkPolicy::class, new \Charlotte\Policy\LinkPolicy(array_unique($origins[0])));
    $session = openSession(['brief' => $fixture['brief']]);
    $ops = [['t' => 'set_meta', 'patch' => $fixture['doc']['meta']], ['t' => 'set_theme', 'patch' => $fixture['doc']['theme']]];
    $placed = [];
    while (count($placed) < count($fixture['doc']['nodes'])) {
        foreach ($fixture['doc']['nodes'] as $id => $node) {
            if (! isset($placed[$id]) && ($node['parent'] === null || isset($placed[$node['parent']]))) {
                $ops[] = ['t' => 'insert_node', 'node' => $node];
                $placed[$id] = true;
            }
        }
    }
    $applied = tool($session, 'cms_apply', ['ops' => $ops, 'basedOn' => $session->state()['revision']]);
    expect($applied['isError'] ?? false)->toBeFalse();

    $defects = tool($session, 'cms_finish', ['basedOn' => rev($applied)])['error']['defects'];

    // Its stacks and grids DO carry layouts (11 of 24 containers), so the layout gates pass:
    // the gates discriminate rather than fire on every lab page.
    expect(array_column($defects, 'code'))->toBe(['meta_description'])
        ->and($defects[0]['message'])->toContain('meta.seo.description');
});

test('a required image is a gate: refused until the page places it', function (): void {
    app()->instance(AssetCatalogue::class, new ArrayAssetCatalogue(['loaf' => ['alt' => 'A sourdough loaf', 'width' => 1200, 'height' => 800]]));
    $session = openSession(['brief' => [...briefArray(), 'images' => ['loaf']]]);
    $rev = rev(tool($session, 'cms_apply', ['ops' => finishablePageOps(), 'basedOn' => $session->state()['revision']]));

    $refused = tool($session, 'cms_finish', ['basedOn' => $rev]);
    $rev = rev(tool($session, 'cms_apply', ['ops' => [['t' => 'insert_node', 'node' => ['id' => 'loaf', 'type' => 'image', 'parent' => 'plan-a', 'order' => 'b0',
        'props' => ['asset' => 'loaf', 'alt' => 'A sourdough loaf on a board'], 'style' => ['base' => []]]]], 'basedOn' => $rev]));
    $done = tool($session, 'cms_finish', ['basedOn' => $rev]);

    expect(array_column($refused['error']['defects'], 'code'))->toBe(['required_image_missing'])
        ->and($refused['error']['defects'][0]['asset'])->toBe('loaf')
        ->and($done['ready'] ?? null)->toBeTrue();
});

test('a page sub-agent\'s cms_finish is gated the same way', function (): void {
    $site = openSite();
    tool($site, 'site_page_create', ['page' => 'home', 'title' => 'Acme Ledger', 'kind' => 'page', 'route' => '/']);
    $child = new PageTools(app(Workspace::class), $site->id(), app(SiteWorkspace::class), child: true);
    $revision = json_decode($child->call('cms_get'), true)['revision'];

    $result = json_decode($child->call('cms_finish', ['basedOn' => $revision]), true);

    expect($result['error']['code'])->toBe('not_ready')
        ->and(eventsOf($site, 'draft.ready'))->toBe([]);
});

test('a site finishes only when every page clears the gates; regions and the 404 page are not held to a page layout', function (): void {
    $site = openSite();
    tool($site, 'site_page_create', ['page' => 'home', 'title' => 'Acme Ledger', 'kind' => 'page', 'route' => '/']);
    tool($site, 'site_page_create', ['page' => 'missing', 'title' => 'Not found', 'kind' => 'not_found', 'route' => '']);
    $child = fn () => new PageTools(app(Workspace::class), $site->id(), app(SiteWorkspace::class), child: true);
    $px = ['value' => 48, 'unit' => 'px'];
    tool($site, 'site_page_select', ['page' => 'missing']);
    $child()->call('cms_apply', ['ops' => [
        ['t' => 'set_meta', 'patch' => ['seo' => ['description' => 'That page is not here.']]],
        ['t' => 'insert_node', 'node' => ['id' => 'nf', 'type' => 'section', 'parent' => null, 'order' => 'a0', 'layout' => 'stack', 'props' => [], 'style' => ['base' => ['paddingY' => $px]]]],
        ['t' => 'insert_node', 'node' => ['id' => 'nf-h', 'type' => 'heading', 'parent' => 'nf', 'order' => 'a0', 'props' => ['content' => 'Page not found', 'level' => 1], 'style' => ['base' => []]]],
        ['t' => 'insert_node', 'node' => ['id' => 'nf-a', 'type' => 'link', 'parent' => 'nf', 'order' => 'a1', 'props' => ['content' => 'Home', 'href' => '/'], 'style' => ['base' => []]]],
    ], 'basedOn' => json_decode($child()->call('cms_get'), true)['revision']]);

    $refused = tool($site, 'cms_finish', ['basedOn' => tool($site, 'site_get')['site']['revision']]);
    tool($site, 'site_page_select', ['page' => 'home']);
    $child()->call('cms_apply', ['ops' => finishablePageOps(), 'basedOn' => json_decode($child()->call('cms_get'), true)['revision']]);
    $done = tool($site, 'cms_finish', ['basedOn' => tool($site, 'site_get')['site']['revision']]);

    expect($refused['error']['code'])->toBe('not_ready')
        ->and(array_unique(array_column($refused['error']['defects'], 'page')))->toBe(['home'])
        ->and($done['ready'] ?? null)->toBeTrue()
        ->and($site->state()['readyRevision'])->toBe($done['revision']);
});

test('a site\'s required image may sit on any one of its pages', function (): void {
    app()->instance(AssetCatalogue::class, new ArrayAssetCatalogue(['loaf' => ['alt' => 'A sourdough loaf', 'width' => 1200, 'height' => 800]]));
    $site = openSite(['brief' => [...briefArray(), 'images' => ['loaf']]]);
    tool($site, 'site_page_create', ['page' => 'home', 'title' => 'Acme Ledger', 'kind' => 'page', 'route' => '/']);
    $child = fn () => new PageTools(app(Workspace::class), $site->id(), app(SiteWorkspace::class), child: true);
    $child()->call('cms_apply', ['ops' => finishablePageOps(), 'basedOn' => json_decode($child()->call('cms_get'), true)['revision']]);

    $refused = tool($site, 'cms_finish', ['basedOn' => tool($site, 'site_get')['site']['revision']]);
    tool($site, 'site_page_create', ['page' => 'about', 'title' => 'About Acme', 'kind' => 'page', 'route' => '/about']);
    $child()->call('cms_apply', ['ops' => [...finishablePageOps('About Acme', 'q'), ['t' => 'insert_node', 'node' => ['id' => 'loaf', 'type' => 'image', 'parent' => 'q-plan-a', 'order' => 'b0',
        'props' => ['asset' => 'loaf', 'alt' => 'A sourdough loaf on a board'], 'style' => ['base' => []]]]], 'basedOn' => json_decode($child()->call('cms_get'), true)['revision']]);
    $done = tool($site, 'cms_finish', ['basedOn' => tool($site, 'site_get')['site']['revision']]);

    expect(array_column($refused['error']['defects'], 'code'))->toBe(['required_image_missing'])
        ->and($done['ready'] ?? null)->toBeTrue();
});

// ── the starter the agent builds from ──────────────────────────────────────

test('the starter lives in every builder mode\'s prompt, once, and no longer in the cms_describe result', function (): void {
    $section = Starter::promptSection();
    $modes = app(\Prism\Harness\Modes\ModeRegistry::class);
    preg_match('/```json\n(.*)\n```/s', $section, $json);
    $starter = json_decode($json[1], true, 64, JSON_THROW_ON_ERROR);

    foreach ([\Charlotte\Guide\BuilderMode::NAME, \Charlotte\Guide\BuilderMode::SITE_NAME, \Charlotte\Guide\BuilderMode::PAGE_SUB_NAME] as $name) {
        expect(substr_count($modes->resolve($name)->systemPrompt, $section))->toBe(1);
    }
    expect(tool(openSession(), 'cms_describe'))->not->toHaveKey('starter')
        ->and($starter)->toHaveKeys(['typeScale', 'spacing', 'card', 'skeleton'])
        ->and($starter['skeleton'])->toHaveKeys(['header', 'page', 'footer'])
        ->and($starter['typeScale']['display'])->toHaveKeys(['base', 'md', 'lg'])
        ->and($starter['skeleton']['page'])->toBe(Starter::skeleton('page'));
});

test('the starter section stays small: it rides in every prompt', function (): void {
    expect(strlen(Starter::promptSection()))->toBeLessThan(7000);
});

test('the starter skeleton applies cleanly, and with copy in it the page clears every gate', function (): void {
    $session = openSession();
    $ops = [...Starter::skeleton('header'), ...Starter::skeleton('page'), ...Starter::skeleton('footer'),
        ['t' => 'set_meta', 'patch' => ['title' => 'Acme Ledger', 'seo' => ['description' => 'Bookkeeping for food trucks.']]]];
    $text = fn (string $id, string $parent, string $content, string $order = 'a0') => ['t' => 'insert_node',
        'node' => ['id' => $id, 'type' => 'text', 'parent' => $parent, 'order' => $order, 'props' => ['content' => $content], 'style' => ['base' => []]]];
    $link = fn (string $id, string $parent, string $label, string $order) => ['t' => 'insert_node',
        'node' => ['id' => $id, 'type' => 'link', 'parent' => $parent, 'order' => $order, 'props' => ['content' => $label, 'href' => '#plans'], 'style' => ['base' => []]]];
    array_push($ops,
        $text('brand', 'header-bar', 'Acme Ledger'), $link('nav-1', 'site-nav', 'Plans', 'a0'), $link('nav-2', 'site-nav', 'Contact', 'a1'),
        ['t' => 'insert_node', 'node' => ['id' => 'title', 'type' => 'heading', 'parent' => 'hero-copy', 'order' => 'a0', 'props' => ['content' => 'Acme Ledger', 'level' => 1], 'style' => Starter::TYPE_SCALE['display']]],
        $text('hero-media-t', 'hero-media', 'Starter plan: $19/month'),
        ['t' => 'insert_node', 'node' => ['id' => 'features-h', 'type' => 'heading', 'parent' => 'features-head', 'order' => 'a0', 'props' => ['content' => 'Plans', 'level' => 2], 'style' => Starter::TYPE_SCALE['h2']]],
        ['t' => 'insert_node', 'node' => ['id' => 'card-1', 'type' => 'card', 'parent' => 'features-grid', 'order' => 'a0', 'layout' => 'stack', 'props' => [], 'style' => Starter::CARD]],
        ['t' => 'insert_node', 'node' => ['id' => 'card-2', 'type' => 'card', 'parent' => 'features-grid', 'order' => 'a1', 'layout' => 'stack', 'props' => [], 'style' => Starter::CARD]],
        $text('card-1-t', 'card-1', 'Starter plan: $19/month'), $text('card-2-t', 'card-2', 'Write to hello@example.com'),
        $text('cta-t', 'cta-inner', 'Write to hello@example.com'),
        $text('footer-brand', 'footer-inner', 'Acme Ledger'), $link('footer-1', 'footer-nav', 'Plans', 'a0'), $link('footer-2', 'footer-nav', 'Contact', 'a1'),
    );
    $applied = tool($session, 'cms_apply', ['ops' => $ops, 'basedOn' => $session->state()['revision']]);

    $check = tool($session, 'cms_check', ['basedOn' => rev($applied)]);
    $gated = array_values(array_filter($check['defects'], fn ($d) => in_array($d['code'], \Charlotte\Checks\Checks::FINISH_GATES, true) && $d['severity'] === 'error'));

    expect($applied['isError'] ?? false)->toBeFalse()
        ->and($gated)->toBe([])
        ->and(tool($session, 'cms_finish', ['basedOn' => rev($applied)])['ready'] ?? null)->toBeTrue();
});

test('fancy-cms renders the starter as real flex and grid layouts that change at md and lg', function (): void {
    $session = openSession();
    $rev = rev(tool($session, 'cms_apply', ['ops' => [...Starter::skeleton('header'), ...Starter::skeleton('page'), ...Starter::skeleton('footer')],
        'basedOn' => $session->state()['revision']]));
    $doc = json_decode(json_encode($session->state()['doc']), true);

    $css = CssEmitter::emit($doc);

    expect($rev)->toBeString()
        ->and($css)->toMatch('/\[data-cms="site-nav"\] \{[^}]*display: flex;[^}]*gap: /s')
        ->and($css)->toMatch('/\[data-cms="features-grid"\] \{[^}]*display: grid;/s')
        ->and($css)->toMatch('/@media \(min-width: 768px\) \{\s*\[data-cms="features-grid"\] \{[^}]*grid-template-columns: repeat\(2, 1fr\)/s')
        ->and($css)->toMatch('/@media \(min-width: 1024px\) \{\s*\[data-cms="features-grid"\] \{[^}]*grid-template-columns: repeat\(3, 1fr\)/s')
        ->and($css)->toMatch('/@media \(min-width: 1024px\) \{\s*\[data-cms="hero-grid"\] \{[^}]*grid-template-columns: repeat\(2, 1fr\)/s');
});

test('on a site, site_nav_set fills the starter header\'s nav row instead of adding a second nav below it', function (): void {
    $site = openSite();
    createPageFor($site, 'home', '/');
    createPageFor($site, 'pricing', '/pricing');
    tool($site, 'site_page_select', ['page' => '@header']);
    $child = new PageTools(app(Workspace::class), $site->id(), app(SiteWorkspace::class), child: true);
    $header = json_decode($child->call('cms_apply', ['ops' => Starter::skeleton('header'), 'basedOn' => json_decode($child->call('cms_get'), true)['revision']]), true);

    tool($site, 'site_nav_set', ['items' => [['page' => 'home', 'label' => 'Home'], ['page' => 'pricing', 'label' => 'Pricing']], 'basedOn' => $header['revision']]);
    $nodes = json_decode(json_encode(tool($site, 'cms_get')['doc']['nodes']), true);

    expect(array_keys(array_filter($nodes, fn ($n) => ($n['props']['as'] ?? null) === 'nav')))->toBe([SiteWorkspace::NAV_ID])
        ->and($nodes[SiteWorkspace::NAV_ID]['parent'])->toBe('header-bar')
        ->and(array_values(array_map(fn ($n) => $n['props']['content'], array_filter($nodes, fn ($n) => ($n['parent'] ?? null) === SiteWorkspace::NAV_ID))))->toEqualCanonicalizing(['Home', 'Pricing'])
        ->and(array_column(array_filter(\Charlotte\Checks\QualityChecks::check(['nodes' => $nodes],\Charlotte\Brief\Brief::fromArray(briefArray())),
            fn ($d) => in_array($d['code'], ['layout_missing', 'link_spacing'], true)), 'code'))->toBe([]);
});

function createPageFor(\Charlotte\CharlotteSession $site, string $id, string $route): void
{
    tool($site, 'site_page_create', ['page' => $id, 'title' => ucfirst($id), 'kind' => 'page', 'route' => $route]);
}
