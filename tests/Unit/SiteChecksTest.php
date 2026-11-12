<?php

declare(strict_types=1);

use Charlotte\Checks\SiteChecks;
use Charlotte\Site\ReservedRoutes;
use Charlotte\Site\Site;

/*
 * Site-level checks: each one is shown to FIRE on a site that breaks it and
 * to stay QUIET on a site that does not.
 */

function siteNode(string $id, string $type, ?string $parent, string $order, array $props = []): array
{
    return [$id => ['id' => $id, 'type' => $type, 'parent' => $parent, 'order' => $order, 'props' => $props, 'style' => ['base' => []]]];
}

function siteDoc(string $title, array ...$nodes): array
{
    return ['id' => $title, 'seq' => 1, 'meta' => ['title' => $title, 'slug' => '/'], 'theme' => [], 'breakpoints' => ['base', 'md', 'lg'],
        'nodes' => $nodes === [] ? [] : array_merge(...$nodes)];
}

/** A clean three-page site: home, pricing, a 404, nav in the header. */
function cleanSite(): array
{
    $site = Site::fromArray(['key' => 's', 'version' => 3, 'pages' => [
        ['id' => 'home', 'kind' => 'page', 'title' => 'Home', 'route' => '/'],
        ['id' => 'pricing', 'kind' => 'page', 'title' => 'Pricing', 'route' => '/pricing'],
        ['id' => 'missing', 'kind' => 'not_found', 'title' => 'Not found', 'route' => null],
    ]]);
    $docs = [
        '@header' => siteDoc('Header',
            siteNode('hdr', 'section', null, 'a0', ['as' => 'header']),
            siteNode('nav', 'stack', 'hdr', 'a0', ['as' => 'nav']),
            siteNode('n1', 'link', 'nav', 'a0', ['content' => 'Home', 'href' => '/']),
            siteNode('n2', 'link', 'nav', 'a1', ['content' => 'Pricing', 'href' => '/pricing'])),
        '@footer' => siteDoc('Footer', siteNode('ftr', 'section', null, 'a0', ['as' => 'footer']),
            siteNode('f1', 'link', 'ftr', 'a0', ['content' => 'Sign in', 'href' => '/login'])),
        'home' => siteDoc('Acme home', siteNode('s', 'section', null, 'a0'), siteNode('h', 'heading', 's', 'a0', ['content' => 'Acme', 'level' => 1]),
            siteNode('c', 'button', 's', 'a1', ['label' => 'See plans', 'href' => '/pricing#plans'])),
        'pricing' => siteDoc('Acme pricing', siteNode('s', 'section', null, 'a0', ['anchor' => 'plans']), siteNode('h', 'heading', 's', 'a0', ['content' => 'Plans', 'level' => 1])),
        'missing' => siteDoc('Not found', siteNode('s', 'section', null, 'a0'), siteNode('h', 'heading', 's', 'a0', ['content' => 'Not found', 'level' => 1]),
            siteNode('l', 'link', 's', 'a1', ['content' => 'Home', 'href' => '/'])),
    ];

    return [$site, $docs];
}

function siteCodes(Site $site, array $docs): array
{
    return array_values(array_unique(array_column(SiteChecks::check($site, $docs, new ReservedRoutes(['/login'])), 'code')));
}

test('a clean site passes every site check', function (): void {
    [$site, $docs] = cleanSite();

    expect(SiteChecks::check($site, $docs, new ReservedRoutes(['/login'])))->toBe([]);
});

test('a link to a route no page has is broken, and names the page it is on', function (): void {
    [$site, $docs] = cleanSite();
    $docs['home']['nodes']['c']['props']['href'] = '/pricng';

    $defects = SiteChecks::check($site, $docs, new ReservedRoutes(['/login']));
    $broken = array_values(array_filter($defects, fn ($d) => $d['code'] === 'site_link_broken'));

    expect($broken)->toHaveCount(1)
        ->and($broken[0])->toMatchArray(['severity' => 'error', 'page' => 'home', 'node' => 'c', 'href' => '/pricng']);
});

test('a link to an anchor the target page does not declare is broken', function (): void {
    [$site, $docs] = cleanSite();
    $docs['home']['nodes']['c']['props']['href'] = '/pricing#faq';

    expect(siteCodes($site, $docs))->toContain('site_anchor_broken');
});

test('links to a reserved host route are not broken: the host serves them', function (): void {
    [$site, $docs] = cleanSite();

    expect(siteCodes($site, $docs))->not->toContain('site_link_broken');

    $unreserved = SiteChecks::check($site, $docs, new ReservedRoutes([]));
    expect(array_column($unreserved, 'code'))->toContain('site_link_broken');
});

test('a routed page nothing links to is an orphan', function (): void {
    [$site, $docs] = cleanSite();
    unset($docs['@header']['nodes']['n2']);
    unset($docs['home']['nodes']['c']);

    expect(siteCodes($site, $docs))->toContain('site_orphan_page');
});

test('a page with no route is unreachable', function (): void {
    [$site, $docs] = cleanSite();
    $site = $site->withPage(['id' => 'about', 'kind' => 'page', 'title' => 'About', 'route' => null]);
    $docs['about'] = siteDoc('About');

    expect(siteCodes($site, $docs))->toContain('site_page_unrouted');
});

test('a site without a 404 page, or without a home page, fails', function (): void {
    [$site, $docs] = cleanSite();
    $noHome = Site::fromArray(['key' => 's', 'version' => 1, 'pages' => [
        ['id' => 'pricing', 'kind' => 'page', 'title' => 'Pricing', 'route' => '/pricing'],
    ]]);

    expect(siteCodes($noHome, $docs))->toContain('site_missing_404')
        ->and(siteCodes($noHome, $docs))->toContain('site_missing_home');
});

test('a 404 page with no way home is warned about', function (): void {
    [$site, $docs] = cleanSite();
    unset($docs['missing']['nodes']['l']);

    expect(siteCodes($site, $docs))->toContain('site_404_no_home_link');
});

test('nav consistency: no nav, a page missing from the nav, a duplicate entry, a page with its own nav', function (): void {
    [$site, $docs] = cleanSite();
    $noNav = $docs;
    $noNav['@header']['nodes']['nav']['props'] = [];
    expect(siteCodes($site, $noNav))->toContain('site_nav_missing');

    $incomplete = $docs;
    unset($incomplete['@header']['nodes']['n2']);
    expect(siteCodes($site, $incomplete))->toContain('site_nav_incomplete');

    $duplicate = $docs;
    $duplicate['@header']['nodes'] += siteNode('n3', 'link', 'nav', 'a2', ['content' => 'Pricing again', 'href' => '/pricing']);
    expect(siteCodes($site, $duplicate))->toContain('site_nav_duplicate');

    $ownNav = $docs;
    $ownNav['pricing']['nodes'] += siteNode('pn', 'stack', 's', 'a1', ['as' => 'nav']);
    expect(siteCodes($site, $ownNav))->toContain('site_nav_in_page');
});

test('an h1 in a shared region repeats on every page and fails', function (): void {
    [$site, $docs] = cleanSite();
    $docs['@header']['nodes'] += siteNode('brand', 'heading', 'hdr', 'a1', ['content' => 'Acme', 'level' => 1]);

    expect(siteCodes($site, $docs))->toContain('site_region_h1');
});

test('an in-page anchor link inside a shared region is ambiguous', function (): void {
    [$site, $docs] = cleanSite();
    $docs['@footer']['nodes'] += siteNode('f2', 'link', 'ftr', 'a1', ['content' => 'Plans', 'href' => '#plans']);

    expect(siteCodes($site, $docs))->toContain('site_region_anchor');
});

test('two pages with the same title are warned about', function (): void {
    [$site, $docs] = cleanSite();
    $docs['pricing']['meta']['title'] = 'Acme home';

    expect(siteCodes($site, $docs))->toContain('site_title_duplicate');
});

test('external, mailto and tel links are not site links', function (): void {
    [$site, $docs] = cleanSite();
    $docs['home']['nodes'] += siteNode('e', 'link', 's', 'a2', ['content' => 'Mail', 'href' => 'mailto:a@example.com']);
    $docs['home']['nodes'] += siteNode('x', 'link', 's', 'a3', ['content' => 'Docs', 'href' => 'https://example.com/x']);

    expect(SiteChecks::check($site, $docs, new ReservedRoutes(['/login'])))->toBe([]);
});

test('reserved routes match exactly, or by an explicit /* prefix', function (): void {
    $reserved = new ReservedRoutes(['/login', '/reset-password/*']);

    expect($reserved->match('/login'))->toBe('/login')
        ->and($reserved->match('/login/extra'))->toBeNull()
        ->and($reserved->match('/loginx'))->toBeNull()
        ->and($reserved->match('/reset-password/tok'))->toBe('/reset-password/*')
        ->and($reserved->match('/reset-password'))->toBe('/reset-password/*');
});

test('a reserved route list entry that is not a route is a host configuration error', function (): void {
    new ReservedRoutes(['login']);
})->throws(InvalidArgumentException::class);
