<?php

declare(strict_types=1);

namespace Charlotte\Checks;

use Charlotte\Brief\Brief;
use Charlotte\Site\ReservedRoutes;
use Charlotte\Site\Site;

/**
 * Checks across a whole site, from its structure and every document. Each
 * defect names the `page` (a page id or `@header` / `@footer`) it is on.
 *
 * | code | severity | fires when |
 * |---|---|---|
 * | `site_link_broken` | error | an internal link's path is no page's route and no reserved host route |
 * | `site_anchor_broken` | error | `/route#anchor` where that page declares no such anchor |
 * | `site_orphan_page` | error | a routed page (other than `/`) that nothing else links to |
 * | `site_page_unrouted` | error | a page with no route: nothing can reach it |
 * | `site_missing_home` | error | no page at `/` |
 * | `site_missing_404` | error | no `not_found` page |
 * | `site_404_no_home_link` | warning | the 404 page has no link to `/` |
 * | `site_nav_missing` | error | the header has no `as: nav` element with links in it |
 * | `site_nav_incomplete` | warning | a routed page the header nav does not link to |
 * | `site_nav_duplicate` | warning | two header nav links to the same route |
 * | `site_nav_in_page` | warning | a page declares its own nav, which drifts from the shared one |
 * | `site_region_h1` | error | an h1 in a shared region: every page would have two |
 * | `site_region_anchor` | warning | `#anchor` in a shared region resolves differently on each page |
 * | `site_title_duplicate` | warning | two pages share a meta title |
 * | `missing_required_fact` | error | a required brief fact appears on no page of the site |
 */
final class SiteChecks
{
    /**
     * @param  array<string, array>  $docs  document id (page id or region) => PageDoc
     * @return list<array<string, mixed>>
     */
    public static function check(Site $site, array $docs, ReservedRoutes $reserved): array
    {
        $defects = [];
        $routes = [];
        foreach ($site->pages as $page) {
            if ($page['route'] !== null) {
                $routes[$page['route']] = $page['id'];
            }
        }
        $inbound = [];

        foreach ($site->documentIds() as $id) {
            $doc = $docs[$id] ?? null;
            if (! is_array($doc)) {
                continue;
            }
            $isRegion = in_array($id, Site::REGIONS, true);
            foreach (PageChecks::facts($doc)['links'] as $link) {
                $href = $link['href'];
                if (str_starts_with($href, '#')) {
                    if ($isRegion) {
                        $defects[] = self::defect('site_region_anchor', 'warning', $id, "A link to {$href} in the shared {$id} points at a different place on every page; use /route{$href}.", $link['node']);
                    }

                    continue;
                }
                $target = self::internal($href);
                if ($target === null) {
                    continue;
                }
                [$path, $fragment] = $target;
                $owner = $routes[$path] ?? null;
                if ($owner === null) {
                    if ($reserved->match($path) === null) {
                        $defects[] = self::defect('site_link_broken', 'error', $id, "Links to {$href}, and no page in this site has the route {$path}.", $link['node']) + ['href' => $href];
                    }

                    continue;
                }
                if ($owner !== $id) {
                    $inbound[$owner] = true;
                }
                if ($fragment !== null && $fragment !== '' && ! in_array($fragment, self::anchors($docs[$owner] ?? []), true)) {
                    $defects[] = self::defect('site_anchor_broken', 'error', $id, "Links to {$href}, and that page declares no anchor \"{$fragment}\".", $link['node']) + ['href' => $href];
                }
            }

            $nodes = self::nodes($doc);
            foreach ($nodes as $nodeId => $node) {
                $props = is_array($node['props'] ?? null) ? $node['props'] : [];
                if ($isRegion && ($node['type'] ?? '') === 'heading' && (int) ($props['level'] ?? 2) === 1) {
                    $defects[] = self::defect('site_region_h1', 'error', $id, "The shared {$id} has an h1, so every page would have two. Use text or an h2 there.", (string) $nodeId);
                }
                if (! $isRegion && ($props['as'] ?? null) === 'nav') {
                    $defects[] = self::defect('site_nav_in_page', 'warning', $id, 'This page declares its own nav; keep site navigation in the shared @header so it stays the same on every page.', (string) $nodeId);
                }
            }
        }

        $titles = [];
        foreach ($site->pages as $page) {
            if ($page['kind'] === 'page' && $page['route'] === null) {
                $defects[] = self::defect('site_page_unrouted', 'error', $page['id'], "The page {$page['id']} has no route, so nothing can reach it. Give it one with site_page_route.");
            } elseif ($page['kind'] === 'page' && $page['route'] !== '/' && ! isset($inbound[$page['id']])) {
                $defects[] = self::defect('site_orphan_page', 'error', $page['id'], "Nothing in the site links to {$page['route']}. Add it to the nav or link it from another page.");
            }
            $title = trim((string) ($docs[$page['id']]['meta']['title'] ?? ''));
            if ($page['kind'] === 'page' && $title !== '') {
                $titles[mb_strtolower($title)][] = $page['id'];
            }
        }
        foreach ($titles as $ids) {
            if (count($ids) > 1) {
                $defects[] = self::defect('site_title_duplicate', 'warning', $ids[1], 'Pages '.implode(', ', $ids).' share one meta title; give each page its own.');
            }
        }

        if (! isset($routes['/'])) {
            $defects[] = self::defect('site_missing_home', 'error', null, 'No page has the route "/": the site has no home page.');
        }
        $notFound = $site->notFound();
        if ($notFound === null) {
            $defects[] = self::defect('site_missing_404', 'error', null, 'The site has no not_found page. Create one with kind "not_found" and route "".');
        } else {
            $home = false;
            foreach (PageChecks::facts($docs[$notFound['id']] ?? [])['links'] as $link) {
                if ((self::internal($link['href'])[0] ?? null) === '/') {
                    $home = true;
                }
            }
            if (! $home) {
                $defects[] = self::defect('site_404_no_home_link', 'warning', $notFound['id'], 'The 404 page has no link back to the home page ("/").');
            }
        }

        return [...$defects, ...self::nav($site, $docs['@header'] ?? [], $routes)];
    }

    /** `missing_required_fact` across the whole site: a fact may live on any page. */
    public static function facts(Site $site, array $docs, Brief $brief): array
    {
        $defects = array_values(array_filter(FactChecks::check(self::combined($site, $docs), $brief), fn (array $d) => $d['code'] === 'missing_required_fact'));

        return array_map(function (array $d): array {
            $d['message'] = "No page in the site carries the required fact \"{$d['fact']}\".";

            return $d;
        }, $defects);
    }

    /** Every document's nodes in one document, ids prefixed `<doc>::`, regions first. */
    public static function combined(Site $site, array $docs): array
    {
        $nodes = [];
        $order = 0;
        foreach ($site->documentIds() as $id) {
            foreach (self::nodes($docs[$id] ?? []) as $nodeId => $node) {
                $parent = $node['parent'] ?? null;
                $node['id'] = $id.'::'.$nodeId;
                $node['parent'] = $parent === null ? null : $id.'::'.$parent;
                if ($parent === null) {
                    $node['order'] = sprintf('%04d', $order).(string) ($node['order'] ?? '');
                }
                $nodes[$node['id']] = $node;
            }
            $order++;
        }

        return ['id' => $site->key, 'seq' => 0, 'meta' => [], 'theme' => [], 'breakpoints' => ['base', 'md', 'lg'], 'nodes' => $nodes];
    }

    /** @return list<array<string, mixed>> */
    private static function nav(Site $site, array $header, array $routes): array
    {
        $nodes = self::nodes($header);
        $navs = [];
        foreach ($nodes as $nodeId => $node) {
            if (is_array($node['props'] ?? null) && ($node['props']['as'] ?? null) === 'nav') {
                $navs[(string) $nodeId] = true;
            }
        }
        $paths = [];
        foreach (PageChecks::facts($header)['links'] as $link) {
            if (! self::within($nodes, $link['node'], $navs)) {
                continue;
            }
            $target = self::internal($link['href']);
            if ($target !== null) {
                $paths[] = [$target[0], $link['node']];
            }
        }
        if ($paths === []) {
            return [self::defect('site_nav_missing', 'error', '@header', 'The shared @header has no nav with links. Set one with site_nav_set.')];
        }

        $defects = [];
        $seen = [];
        foreach ($paths as [$path, $node]) {
            if (isset($seen[$path])) {
                $defects[] = self::defect('site_nav_duplicate', 'warning', '@header', "The nav links to {$path} twice.", $node);
            }
            $seen[$path] = true;
        }
        foreach ($site->pages as $page) {
            if ($page['kind'] === 'page' && $page['route'] !== null && ! isset($seen[$page['route']])) {
                $defects[] = self::defect('site_nav_incomplete', 'warning', '@header', "The nav does not link to {$page['route']} ({$page['id']}).");
            }
        }

        return $defects;
    }

    /** Whether a node is, or sits inside, one of $ancestors. */
    private static function within(array $nodes, string $id, array $ancestors): bool
    {
        $guard = 0;
        while ($id !== '' && isset($nodes[$id]) && $guard++ < 256) {
            if (isset($ancestors[$id])) {
                return true;
            }
            $id = (string) ($nodes[$id]['parent'] ?? '');
        }

        return false;
    }

    /** @return array{0: string, 1: ?string}|null [path, fragment] for a same-site absolute path */
    private static function internal(string $href): ?array
    {
        $href = trim($href);
        if (! str_starts_with($href, '/') || str_starts_with($href, '//') || ! PageChecks::isSafeHref($href)) {
            return null;
        }
        $fragment = null;
        if (($hash = strpos($href, '#')) !== false) {
            $fragment = substr($href, $hash + 1);
            $href = substr($href, 0, $hash);
        }
        if (($query = strpos($href, '?')) !== false) {
            $href = substr($href, 0, $query);
        }
        $path = strlen($href) > 1 ? rtrim($href, '/') : $href;

        return [$path === '' ? '/' : $path, $fragment];
    }

    /** @return list<string> */
    private static function anchors(array $doc): array
    {
        $out = [];
        foreach (self::nodes($doc) as $node) {
            $anchor = is_array($node['props'] ?? null) ? ($node['props']['anchor'] ?? null) : null;
            if (is_string($anchor)) {
                $out[] = $anchor;
            }
        }

        return $out;
    }

    /** @return array<string, array> */
    private static function nodes(array $doc): array
    {
        $nodes = $doc['nodes'] ?? [];
        $nodes = json_decode(json_encode($nodes) ?: '[]', true) ?: [];

        return is_array($nodes) ? $nodes : [];
    }

    private static function defect(string $code, string $severity, ?string $page, string $message, ?string $node = null): array
    {
        return array_filter(['code' => $code, 'severity' => $severity, 'page' => $page, 'node' => $node, 'message' => $message], fn ($v) => $v !== null);
    }
}
