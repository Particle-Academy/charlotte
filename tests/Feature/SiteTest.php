<?php

declare(strict_types=1);

use Charlotte\Guide\BuilderMode;

/*
 * A site session: several pages with real routes, a shared header and footer
 * edited once, a 404 page, and a nav that links the pages. Page, site and
 * tenant are host-owned; tool arguments only ever name a page id INSIDE the
 * bound site.
 */

beforeEach(fn () => charlotteApp([
    'charlotte.autonomy' => 'full',
    'charlotte.site.reserved_routes' => FORTIFY_ROUTES,
]));

function createPage(\Charlotte\CharlotteSession $site, string $id, string $route, string $title = 'Acme page', string $kind = 'page'): array
{
    return tool($site, 'site_page_create', ['page' => $id, 'title' => $title, 'kind' => $kind, 'route' => $route]);
}

test('a site session offers the orchestrator tools (the page sub-agent only during a turn); a page session keeps exactly nine', function (): void {
    $site = array_map(fn ($t) => $t->name(), openSite()->tools()->prismTools());
    $page = array_map(fn ($t) => $t->name(), openSession()->tools()->prismTools());

    expect($site)->toBe(array_values(array_diff(BuilderMode::SITE_TOOLS, [BuilderMode::SITE_SUBAGENT])))
        ->and($page)->toBe(BuilderMode::TOOLS)
        ->and($site)->not->toContain('cms_apply');
});

test('a page tool on a page session refuses the site tools as unknown', function (): void {
    openSession()->tools()->call('site_get');
})->throws(InvalidArgumentException::class);

test('a site opens with shared header and footer regions and no pages', function (): void {
    $result = tool(openSite(), 'site_get');

    expect($result['site']['pages'])->toBe([])
        ->and(array_column($result['site']['regions'], 'id'))->toBe(['@header', '@footer'])
        ->and($result['site']['selected'])->toBe('@header')
        ->and($result['site']['reservedRoutes'])->toBe(FORTIFY_ROUTES)
        ->and($result['site']['revision'])->toMatch('/^[0-9a-f]{40}$/');
});

test('creating pages gives each its own document, route and selection', function (): void {
    $site = openSite();
    $home = createPage($site, 'home', '/', 'Acme Ledger');
    $rev = tool($site, 'cms_get')['revision'];
    tool($site, 'cms_apply', ['ops' => sectionOps(), 'basedOn' => $rev]);
    $pricing = createPage($site, 'pricing', '/pricing', 'Pricing');

    $get = tool($site, 'site_get');
    $pages = array_column($get['site']['pages'], null, 'id');

    expect($home['selected'])->toBe('home')
        ->and($pricing['selected'])->toBe('pricing')
        ->and($pages['home']['route'])->toBe('/')
        ->and($pages['pricing']['route'])->toBe('/pricing')
        ->and(tool($site, 'cms_get')['doc']['nodes'])->toBe([])
        ->and(tool($site, 'cms_get')['doc']['meta']['title'])->toBe('Pricing');

    tool($site, 'site_page_select', ['page' => 'home']);
    expect(array_keys(tool($site, 'cms_get')['doc']['nodes']))->toBe(['hero', 'hero-h']);
});

test('a reserved host route is refused with a clear error, and nothing changes', function (string $route): void {
    $site = openSite();
    $before = tool($site, 'site_get')['site'];

    $result = createPage($site, 'signup', $route, 'Sign up');

    expect($result['isError'])->toBeTrue()
        ->and($result['error']['code'])->toBe('route_reserved')
        ->and($result['error']['message'])->toContain('host')
        ->and($result['error']['route'])->toBe($route)
        ->and(tool($site, 'site_get')['site'])->toBe($before);
})->with(['/login', '/register', '/forgot-password', '/reset-password/abc', '/two-factor-challenge']);

test('setting an existing page to a reserved route is refused too', function (): void {
    $site = openSite();
    createPage($site, 'signup', '/registration', 'Sign up');

    $result = tool($site, 'site_page_route', ['page' => 'signup', 'route' => '/register']);
    $pages = array_column(tool($site, 'site_get')['site']['pages'], null, 'id');

    expect($result['error']['code'])->toBe('route_reserved')
        ->and($pages['signup']['route'])->toBe('/registration');
});

test('malformed routes are refused as route_invalid', function (string $route): void {
    $result = createPage(openSite(), 'x', $route);

    expect($result['error']['code'])->toBe('route_invalid');
})->with(['pricing', '/Pricing', '/pricing/', '//evil.example', '/../admin', '/a b', '/login?next=x', '/a#b', 'https://evil.example/']);

test('two pages cannot share a route', function (): void {
    $site = openSite();
    createPage($site, 'pricing', '/pricing');

    expect(createPage($site, 'plans', '/pricing')['error']['code'])->toBe('route_taken');
});

test('a 404 page takes no route, and a site has at most one', function (): void {
    $site = openSite();

    expect(createPage($site, 'missing', '/404', 'Not found', 'not_found')['error']['code'])->toBe('route_invalid');
    expect(createPage($site, 'missing', '', 'Not found', 'not_found'))->not->toHaveKey('isError');
    expect(createPage($site, 'missing-2', '', 'Not found', 'not_found')['error']['code'])->toBe('site_not_found_exists');
});

test('a page id that already exists is refused', function (): void {
    $site = openSite();
    createPage($site, 'home', '/');

    expect(createPage($site, 'home', '/home')['error']['code'])->toBe('site_page_exists');
});

test('the page count is bounded by configuration', function (): void {
    config()->set('charlotte.site.max_pages', 2);
    $site = openSite();
    createPage($site, 'a', '/a');
    createPage($site, 'b', '/b');

    expect(createPage($site, 'c', '/c')['error']['code'])->toBe('site_full');
});

test('the shared header is edited once and is the same document for every page', function (): void {
    $site = openSite();
    createPage($site, 'home', '/');
    createPage($site, 'about', '/about');
    tool($site, 'site_page_select', ['page' => '@header']);
    $rev = tool($site, 'cms_get')['revision'];
    tool($site, 'cms_apply', ['ops' => [['t' => 'insert_node', 'node' => ['id' => 'hdr', 'type' => 'section', 'parent' => null, 'order' => 'a0', 'props' => ['as' => 'header'], 'style' => ['base' => []]]]], 'basedOn' => $rev]);

    $export = $site->siteExport();

    expect(array_keys($export['header']['nodes']))->toBe(['hdr'])
        ->and(array_column($export['pages'], 'route'))->toBe(['/', '/about'])
        ->and($export['pages'][0]['doc']['nodes'])->toBe([]);
});

test('site_nav_set links the pages from the header nav by route', function (): void {
    $site = openSite();
    createPage($site, 'home', '/');
    createPage($site, 'pricing', '/pricing');
    $header = tool($site, 'site_page_select', ['page' => '@header'])['revision'];

    $result = tool($site, 'site_nav_set', ['items' => [['page' => 'home', 'label' => 'Home'], ['page' => 'pricing', 'label' => 'Pricing']], 'basedOn' => $header]);
    $doc = tool($site, 'cms_get')['doc'];
    $links = array_values(array_filter($doc['nodes'], fn ($n) => $n['type'] === 'link'));

    expect($result['revision'])->not->toBe($header)
        ->and(array_column(array_column($links, 'props'), 'href'))->toBe(['/', '/pricing'])
        ->and(array_column(array_column($links, 'props'), 'content'))->toBe(['Home', 'Pricing'])
        ->and($doc['nodes']['site-nav']['props']['as'])->toBe('nav');

    // Setting it again replaces the links rather than appending.
    tool($site, 'site_nav_set', ['items' => [['page' => 'pricing', 'label' => 'Plans']], 'basedOn' => $result['revision']]);
    $links = array_values(array_filter(tool($site, 'cms_get')['doc']['nodes'], fn ($n) => $n['type'] === 'link'));
    expect(array_column(array_column($links, 'props'), 'href'))->toBe(['/pricing']);
});

test('a nav link to a page that does not exist, or has no route, is refused', function (): void {
    $site = openSite();
    createPage($site, 'home', '/');
    createPage($site, 'missing', '', 'Not found', 'not_found');
    $header = tool($site, 'site_page_select', ['page' => '@header'])['revision'];

    $ghost = tool($site, 'site_nav_set', ['items' => [['page' => 'ghost', 'label' => 'Ghost']], 'basedOn' => $header]);
    $unrouted = tool($site, 'site_nav_set', ['items' => [['page' => 'missing', 'label' => 'Lost']], 'basedOn' => $header]);

    expect($ghost['error']['code'])->toBe('site_page_missing')
        ->and($unrouted['error']['code'])->toBe('site_page_unrouted')
        ->and(tool($site, 'cms_get')['revision'])->toBe($header);
});

test('a page in another tenant or another site is not reachable from this session', function (): void {
    $other = openSite(['tenant' => 'globex', 'site' => 'acme-site']);
    createPage($other, 'secret', '/secret');
    $sibling = openSite(['site' => 'other-site']);
    createPage($sibling, 'private', '/private');

    $site = openSite();

    expect(tool($site, 'site_page_select', ['page' => 'secret'])['error']['code'])->toBe('site_page_missing')
        ->and(tool($site, 'site_page_select', ['page' => 'private'])['error']['code'])->toBe('site_page_missing')
        ->and(tool($site, 'site_get')['site']['pages'])->toBe([]);
});

test('a page id that tries to escape the site is refused before any lookup', function (string $page): void {
    $result = tool(openSite(), 'site_page_select', ['page' => $page]);

    expect($result['error']['code'])->toBe('invalid_request')
        ->and($result['error']['errors'][0]['path'])->toBe('$.arguments.page');
})->with(['../acme-site/home', 'other-site/private', '@sidebar', 'Home', '', 'acme/@header']);

test('tenant, site and actor are never arguments', function (array $arguments): void {
    $result = tool(openSite(), 'site_page_create', $arguments);

    expect($result['error']['code'])->toBe('invalid_request');
})->with([
    'tenant smuggled in' => [['page' => 'a', 'title' => 'A', 'kind' => 'page', 'route' => '/a', 'tenant' => 'globex']],
    'site smuggled in' => [['page' => 'a', 'title' => 'A', 'kind' => 'page', 'route' => '/a', 'site' => 'other']],
    'unknown kind' => [['page' => 'a', 'title' => 'A', 'kind' => 'admin', 'route' => '/a']],
]);

test('cms_finish on a site pins the SITE revision and marks the whole site ready', function (): void {
    $site = openSite();
    createPage($site, 'home', '/');
    $child = new \Charlotte\Tools\PageTools(app(\Charlotte\Workspace::class), $site->id(), app(\Charlotte\SiteWorkspace::class), child: true);
    $bare = tool($site, 'cms_finish', ['basedOn' => tool($site, 'site_get')['site']['revision']]);
    $child->call('cms_apply', ['ops' => finishablePageOps(), 'basedOn' => tool($site, 'cms_get')['revision']]);
    $pageRev = tool($site, 'cms_get')['revision'];
    $siteRev = tool($site, 'site_get')['site']['revision'];

    $stale = tool($site, 'cms_finish', ['basedOn' => $pageRev]);
    $done = tool($site, 'cms_finish', ['basedOn' => $siteRev]);

    expect($bare['error']['code'])->toBe('not_ready')
        ->and($stale['error']['code'])->toBe('surface_changed_under_you')
        ->and($done['ready'])->toBeTrue()
        ->and($done['revision'])->toBe($siteRev)
        ->and($site->state()['readyRevision'])->toBe($siteRev)
        // Errors that are not finish gates (here: no 404 page) are still reported, not refused.
        ->and($done['checks']['errors'])->toBeGreaterThan(0);
});

test('every structural change records a site.changed event', function (): void {
    $site = openSite();
    createPage($site, 'home', '/');
    tool($site, 'site_page_route', ['page' => 'home', 'route' => '/start']);
    tool($site, 'site_page_select', ['page' => '@footer']);

    $changes = array_column(array_column(eventsOf($site, 'site.changed'), 'data'), 'change');

    expect($changes)->toBe(['page_created', 'page_routed', 'page_selected']);
});

test('approve_on_save keeps one change set per page and approval lands on that page', function (): void {
    config()->set('charlotte.autonomy', 'approve_on_save');
    $site = openSite(['autonomy' => 'approve_on_save']);
    createPage($site, 'home', '/');
    $home = tool($site, 'cms_apply', ['ops' => sectionOps(), 'basedOn' => tool($site, 'cms_get')['revision']]);
    createPage($site, 'about', '/about');
    $about = tool($site, 'cms_apply', ['ops' => sectionOps('about', 'a0', 'About us'), 'basedOn' => tool($site, 'cms_get')['revision']]);

    expect($home['proposalId'])->not->toBe($about['proposalId']);

    $site->approve($home['proposalId'], 'user-7');
    $site->approve($about['proposalId'], 'user-7');
    $export = $site->siteExport();
    $pages = array_column($export['pages'], 'doc', 'route');

    expect(array_keys($pages['/']['nodes']))->toBe(['hero', 'hero-h'])
        ->and(array_keys($pages['/about']['nodes']))->toBe(['about', 'about-h']);
});

test('publishing a site at its revision publishes every page and region', function (): void {
    $site = openSite();
    createPage($site, 'home', '/');
    tool($site, 'cms_apply', ['ops' => sectionOps(), 'basedOn' => tool($site, 'cms_get')['revision']]);
    $rev = tool($site, 'site_get')['site']['revision'];

    $result = $site->publish($rev, 'user-7');
    $published = $site->siteExport(published: true);

    expect($result['published'])->toBeTrue()
        ->and($result['revision'])->toBe($rev)
        ->and(array_keys($published['pages'][0]['doc']['nodes']))->toBe(['hero', 'hero-h'])
        ->and($published['header'])->not->toBeNull();
});

test('a site session is not found from another tenant', function (): void {
    $site = openSite();

    expect(\Charlotte\Facades\Charlotte::find($site->id(), 'globex'))->toBeNull()
        ->and(\Charlotte\Facades\Charlotte::find($site->id(), 'acme'))->not->toBeNull();
});

test('a site key with a slash is refused, so it cannot alias another site\'s documents', function (): void {
    openSite(['site' => 'acme-site/home']);
})->throws(InvalidArgumentException::class);

test('site export matches the shape the lab host serves', function (): void {
    $site = openSite();
    createPage($site, 'home', '/', 'Acme Ledger');
    createPage($site, 'missing', '', 'Page not found', 'not_found');

    $export = $site->siteExport();

    expect(array_keys($export))->toBe(['pages', 'header', 'footer', 'notFound', 'islands'])
        ->and($export['pages'][0])->toHaveKeys(['route', 'title', 'description', 'doc'])
        ->and($export['notFound']['meta']['title'])->toBe('Page not found')
        ->and(array_column($export['pages'], 'route'))->toBe(['/']);
});

test('a site turn runs in the site builder mode and reaches the site tools through the harness', function (): void {
    $provider = new \Charlotte\Tests\Support\ScriptedProvider([[
        ['tools' => [['name' => 'site_page_create', 'args' => ['page' => 'home', 'title' => 'Acme Ledger', 'kind' => 'page', 'route' => '/']]]],
        ['tools' => [['name' => BuilderMode::SITE_SUBAGENT, 'args' => ['task' => 'Build the home hero.']]]],
        ['text' => 'Home is in place.'],
    ], [
        // The page sub-agent builds the selected page.
        ['tools' => [['name' => 'cms_get', 'args' => []]]],
        ['tools' => [['name' => 'cms_apply', 'args' => fn (array $calls) => [
            'ops' => sectionOps(), 'basedOn' => json_decode(end($calls)['result'], true)['revision'],
        ]]]],
        ['text' => 'Done.'],
    ]]);
    charlotteApp(['charlotte.autonomy' => 'full', 'charlotte.site.reserved_routes' => FORTIFY_ROUTES], $provider);
    $site = openSite();

    $site->send('Build the site.', 'user-7');

    expect(eventsOf($site, 'run.started')[0]['data']['mode'])->toBe(BuilderMode::SITE_NAME)
        ->and(array_map(fn ($t) => $t->name(), $provider->requests[0]->tools()))->toBe(BuilderMode::SITE_TOOLS)
        ->and(array_keys($site->siteExport()['pages'][0]['doc']['nodes']))->toBe(['hero', 'hero-h'])
        ->and(eventsOf($site, 'doc.changed')[0]['data']['page'])->toBe('acme-site/home')
        ->and(array_column(eventsOf($site, 'session.status'), 'data'))->toContain(['status' => 'idle']);
});

test('the site builder mode embeds the guide once and names the site tools', function (): void {
    $mode = app(\Prism\Harness\Modes\ModeRegistry::class)->resolve(BuilderMode::SITE_NAME);

    expect($mode->tools)->toBe(BuilderMode::SITE_TOOLS)
        ->and(substr_count($mode->systemPrompt, \Charlotte\Facades\Charlotte::guides()->current()->guide))->toBe(1);
});
