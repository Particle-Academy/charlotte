<?php

declare(strict_types=1);

namespace Charlotte;

use Charlotte\Checks\Checks;
use Charlotte\Checks\SiteChecks;
use Charlotte\Drafts\NodeTypes;
use Charlotte\Events\EventTypes;
use Charlotte\Policy\Refused;
use Charlotte\Sessions\SessionRecord;
use Charlotte\Site\ReservedRoutes;
use Charlotte\Site\Site;
use Charlotte\Site\SiteRepository;
use Charlotte\Storage\SessionStore;
use FancyCms\Document\Ordering;
use Illuminate\Database\ConnectionInterface;

/**
 * Whole-site operations for a site session: pages, routes, selection, the
 * shared nav, the site revision, site-wide checks, finishing and publishing.
 *
 * Every document edit still goes through {@see Workspace} (same policies,
 * autonomy and events), addressed with {@see SessionRecord::onPage()}. The
 * site, tenant and actor come from the stored session; a tool argument only
 * ever names a page id, resolved inside the bound site.
 */
final class SiteWorkspace
{
    public const NAV_ID = 'site-nav';

    public const MAX_NAV_ITEMS = 12;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Workspace $workspace,
        private readonly SessionStore $sessions,
        private readonly SiteRepository $sites,
        private readonly ReservedRoutes $reserved,
        private readonly int $maxPages = 12,
    ) {}

    /** Create the site and its two regions if they do not exist. Host code, at open. */
    public function ensure(string $tenant, string $siteKey): Site
    {
        $site = $this->sites->ensure($tenant, $siteKey);
        $this->workspace->pages()->ensure($tenant, $site->documentKey('@header'), NodeTypes::emptyDocument($site->documentKey('@header'), 'Header', '/_header'));
        $this->workspace->pages()->ensure($tenant, $site->documentKey('@footer'), NodeTypes::emptyDocument($site->documentKey('@footer'), 'Footer', '/_footer'));

        return $site;
    }

    public function site(SessionRecord $session): Site
    {
        if (! $session->isSite()) {
            throw new Refused('not_a_site');
        }

        return $this->sites->find($session->tenant, (string) $session->site) ?? throw new Refused('page_missing');
    }

    /** The id (page id or region) the session has selected. */
    public function selected(SessionRecord $session): string
    {
        return $this->site($session)->idFor($session->page) ?? '@header';
    }

    /** What `site_get` returns. */
    public function describe(SessionRecord $session): array
    {
        $site = $this->site($session);
        $selected = $site->idFor($session->page);
        $pages = [];
        foreach ($site->pages as $page) {
            $pages[] = $page + ['selected' => $page['id'] === $selected, 'revision' => $this->workspace->view($session->onPage($site->documentKey($page['id'])))['revision']];
        }
        $regions = [];
        foreach (Site::REGIONS as $id) {
            $regions[] = ['id' => $id, 'selected' => $id === $selected, 'revision' => $this->workspace->view($session->onPage($site->documentKey($id)))['revision']];
        }

        return [
            'key' => $site->key, 'version' => $site->version, 'revision' => $this->revision($session),
            'selected' => $selected, 'pages' => $pages, 'regions' => $regions,
            'reservedRoutes' => $this->reserved->all(), 'maxPages' => $this->maxPages,
        ];
    }

    /** Create a page, give it its document, and select it. */
    public function createPage(SessionRecord $session, string $id, string $title, string $kind, ?string $route): array
    {
        return $this->db->transaction(function () use ($session, $id, $title, $kind, $route): array {
            $session = $this->fresh($session);
            $site = $this->sites->edit($session->tenant, (string) $session->site,
                fn (Site $site): Site => $site->withPage(['id' => $id, 'kind' => $kind, 'title' => $title, 'route' => $route], $this->reserved, $this->maxPages));
            $key = $site->documentKey($id);
            $this->workspace->pages()->ensure($session->tenant, $key,
                NodeTypes::emptyDocument($key, $title, $route ?? ($kind === 'not_found' ? '/404' : '/')));
            $this->sessions->update($session->id, ['page_key' => $key]);
            $this->changed($session, $site, 'page_created', $id, ['kind' => $kind, 'route' => $route]);

            return ['page' => $id, 'selected' => $id, 'route' => $route, 'kind' => $kind,
                'revision' => $this->workspace->view($session->onPage($key))['revision'], 'siteRevision' => $this->revision($session->onPage($key))];
        });
    }

    public function routePage(SessionRecord $session, string $id, ?string $route): array
    {
        return $this->db->transaction(function () use ($session, $id, $route): array {
            $session = $this->fresh($session);
            $site = $this->sites->edit($session->tenant, (string) $session->site, fn (Site $site): Site => $site->withRoute($id, $route, $this->reserved));
            $this->changed($session, $site, 'page_routed', $id, ['route' => $route]);

            return ['page' => $id, 'route' => $route, 'revision' => $this->workspace->view($session)['revision'], 'siteRevision' => $this->revision($session)];
        });
    }

    public function select(SessionRecord $session, string $id): array
    {
        return $this->db->transaction(function () use ($session, $id): array {
            $session = $this->fresh($session);
            $site = $this->site($session);
            if (! in_array($id, $site->documentIds(), true)) {
                throw new Refused('site_page_missing', null, ['page' => $id]);
            }
            $key = $site->documentKey($id);
            $this->sessions->update($session->id, ['page_key' => $key]);
            $this->changed($session, $site, 'page_selected', $id);

            return ['selected' => $id, 'revision' => $this->workspace->view($session->onPage($key))['revision']];
        });
    }

    /**
     * Replace the shared header nav with links to these pages, by route. The
     * nav is the `site-nav` element (`as: nav`), created inside the header's
     * `as: header` section when missing; its children are replaced, so style
     * the links after setting them, not before.
     *
     * @param  list<array{page: string, label: string}>  $items
     */
    public function setNav(SessionRecord $session, array $items, string $basedOn): array
    {
        $session = $this->fresh($session);
        $site = $this->site($session);
        $links = [];
        foreach ($items as $item) {
            $page = $site->page($item['page']) ?? throw new Refused('site_page_missing', null, ['page' => $item['page']]);
            if ($page['route'] === null) {
                throw new Refused('site_page_unrouted', null, ['page' => $page['id']]);
            }
            $links[] = ['label' => $item['label'], 'href' => $page['route']];
        }
        $header = $session->onPage($site->documentKey('@header'));
        $doc = json_decode(json_encode($this->workspace->view($header)['doc']['nodes'] ?? []) ?: '[]', true) ?: [];

        $ops = [];
        if (isset($doc[self::NAV_ID])) {
            foreach ($doc as $id => $node) {
                if (($node['parent'] ?? null) === self::NAV_ID) {
                    $ops[] = ['t' => 'remove_node', 'id' => (string) $id];
                }
            }
        } else {
            $parent = null;
            foreach ($doc as $id => $node) {
                if (($node['parent'] ?? null) === null && ($node['props']['as'] ?? null) === 'header') {
                    $parent = (string) $id;
                    break;
                }
            }
            $last = null;
            foreach ($doc as $node) {
                if (($node['parent'] ?? null) === $parent && ($last === null || strcmp((string) $node['order'], $last) > 0)) {
                    $last = (string) $node['order'];
                }
            }
            $ops[] = ['t' => 'insert_node', 'node' => ['id' => self::NAV_ID, 'type' => 'stack', 'parent' => $parent, 'order' => Ordering::keyBetween($last, null),
                'props' => ['as' => 'nav'], 'layout' => 'stack',
                'style' => ['base' => ['direction' => 'row', 'wrap' => true, 'gap' => ['value' => 4, 'unit' => 'px']]]]];
        }
        $order = null;
        foreach ($links as $i => $link) {
            $order = Ordering::keyBetween($order, null);
            $ops[] = ['t' => 'insert_node', 'node' => ['id' => self::NAV_ID.'-'.$i, 'type' => 'link', 'parent' => self::NAV_ID, 'order' => $order,
                'props' => ['content' => $link['label'], 'href' => $link['href']],
                'style' => ['base' => ['fontSize' => ['value' => 16, 'unit' => 'px'], 'fontWeight' => 600,
                    'paddingY' => ['value' => 10, 'unit' => 'px'], 'paddingX' => ['value' => 10, 'unit' => 'px'], 'minHeight' => ['value' => 44, 'unit' => 'px']]]]];
        }

        return $this->workspace->applyAgent($header, $ops, $basedOn) + ['nav' => $links];
    }

    /**
     * One revision for the whole site: its structure version plus every
     * document's revision. `$staged` reads what the agent sees (working
     * copies of open change sets); false reads the drafts a person publishes.
     */
    public function revision(SessionRecord $session, bool $staged = true): string
    {
        $site = $this->site($session);
        $revisions = [];
        foreach ($site->documentIds() as $id) {
            $key = $site->documentKey($id);
            $revisions[$id] = $staged
                ? $this->workspace->view($session->onPage($key))['revision']
                : $this->workspace->pages()->read($session->tenant, $key)->get()['revision'];
        }

        return sha1(json_encode([$site->key, $site->version, $site->pages, $revisions], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, array> document id => doc */
    public function documents(SessionRecord $session, bool $staged = true): array
    {
        $site = $this->site($session);
        $docs = [];
        foreach ($site->documentIds() as $id) {
            $key = $site->documentKey($id);
            $docs[$id] = $staged ? $this->workspace->view($session->onPage($key))['doc'] : $this->workspace->pages()->read($session->tenant, $key)->get()['doc'];
        }

        return $docs;
    }

    /** `cms_check` on a site: the selected document, plus everything site-wide. */
    public function check(SessionRecord $session, string $basedOn): array
    {
        $site = $this->site($session);
        $selected = $site->idFor($session->page) ?? '@header';
        $siteRevision = $this->revision($session);
        $result = $this->workspace->checkResult($session, $basedOn, self::skip($selected), [$siteRevision]);
        $result['defects'] = array_map(fn (array $d) => ['page' => $selected] + $d, $result['defects']);
        $siteDefects = $this->siteDefects($session, $site);
        $result['defects'] = [...$result['defects'], ...$siteDefects];
        $result['summary'] = Checks::summary($result['defects']);
        $result['page'] = $selected;
        $result['site'] = ['revision' => $siteRevision, 'defects' => count($siteDefects)];
        $this->workspace->record($session->id, EventTypes::CHECKS_RESULT, Workspace::SYSTEM, $result);

        return $result;
    }

    /** `cms_finish` on a site: pinned to the SITE revision; checks every document. */
    public function finish(SessionRecord $session, string $basedOn): array
    {
        return $this->db->transaction(function () use ($session, $basedOn): array {
            $session = $this->fresh($session);
            $site = $this->site($session);
            $revision = $this->revision($session);
            $this->workspace->assertCurrent($revision, $basedOn, 'Pass the site revision from site_get; it changes with every page, region or route edit.');

            $defects = [];
            $contrast = [];
            $staged = false;
            foreach ($site->documentIds() as $id) {
                $doc = $session->onPage($site->documentKey($id));
                $staged = $staged || $this->workspace->view($doc)['staged'];
                $result = $this->workspace->checkResult($doc, null, self::skip($id), [$revision]);
                $contrast[$id] = $result['contrast'];
                foreach ($result['defects'] as $defect) {
                    $defects[] = ['page' => $id] + $defect;
                }
            }
            $defects = [...$defects, ...$this->siteDefects($session, $site)];
            $summary = Checks::summary($defects);
            $this->workspace->record($session->id, EventTypes::CHECKS_RESULT, Workspace::SYSTEM,
                ['revision' => $revision, 'site' => $site->key, 'summary' => $summary, 'defects' => $defects, 'contrast' => $contrast]);
            $this->sessions->update($session->id, ['ready_revision' => $revision]);
            $this->workspace->record($session->id, EventTypes::DRAFT_READY, Workspace::AGENT, ['revision' => $revision, 'staged' => $staged, 'site' => $site->key]);

            return ['revision' => $revision, 'ready' => true, 'staged' => $staged, 'checks' => $summary];
        });
    }

    /** Whether the site was marked ready at the revision it is at now. */
    public function ready(SessionRecord $session): bool
    {
        return $session->readyRevision !== null && hash_equals($session->readyRevision, $this->revision($session));
    }

    /**
     * Publish every page and region at the site revision the person
     * reviewed (computed from the DRAFTS). Host code only.
     */
    public function publish(SessionRecord $session, string $basedOn, string $by): array
    {
        return $this->db->transaction(function () use ($session, $basedOn, $by): array {
            $site = $this->site($session);
            $revision = $this->revision($session, staged: false);
            $this->workspace->assertCurrent($revision, $basedOn, 'Read the site again; it changed after the revision you reviewed.');
            foreach ($site->documentIds() as $id) {
                $doc = $session->onPage($site->documentKey($id));
                $this->workspace->publish($doc, $this->workspace->pages()->read($session->tenant, $doc->page)->get()['revision'], $by, record: false);
            }
            $this->workspace->record($session->id, EventTypes::DRAFT_PUBLISHED, ['kind' => 'human', 'id' => $by],
                ['revision' => $revision, 'site' => $site->key, 'documents' => count($site->documentIds())]);

            return ['revision' => $revision, 'published' => true];
        });
    }

    /**
     * The site as a host serves it: the same shape as the lab's kit-site
     * `site.json` (`pages[{route,title,description,doc}]`, `header`,
     * `footer`, `notFound`, `islands`). Drafts by default; `published` reads
     * the published copies (null where nothing is published).
     */
    public function export(SessionRecord $session, bool $published = false): array
    {
        $site = $this->site($session);
        $doc = function (string $id) use ($session, $site, $published): ?array {
            $key = $site->documentKey($id);

            return $published
                ? ($this->workspace->pages()->published($session->tenant, $key)['doc'] ?? null)
                : $this->workspace->pages()->read($session->tenant, $key)->get()['doc'];
        };
        $plain = fn (?array $d): ?array => $d === null ? null : json_decode(json_encode($d, JSON_THROW_ON_ERROR), true);

        $pages = [];
        $notFound = null;
        $used = [];
        foreach ($site->pages as $page) {
            $d = $plain($doc($page['id']));
            foreach ((array) ($d['nodes'] ?? []) as $node) {
                if (($node['island'] ?? false) === true) {
                    $used[] = (string) $node['type'];
                }
            }
            if ($page['kind'] === 'not_found') {
                $notFound = $d;

                continue;
            }
            if ($page['route'] === null) {
                continue;
            }
            $title = trim((string) ($d['meta']['title'] ?? ''));
            $pages[] = ['route' => $page['route'], 'title' => $title !== '' ? $title : $page['title'],
                'description' => is_string($d['meta']['seo']['description'] ?? null) ? $d['meta']['seo']['description'] : null, 'doc' => $d];
        }

        return ['pages' => $pages, 'header' => $plain($doc('@header')), 'footer' => $plain($doc('@footer')), 'notFound' => $notFound,
            'islands' => array_values(array_unique([...$this->workspace->islands()->types(), ...$used]))];
    }

    private function siteDefects(SessionRecord $session, Site $site): array
    {
        $docs = $this->documents($session);

        return [...SiteChecks::check($site, $docs, $this->reserved), ...SiteChecks::facts($site, $docs, $session->brief)];
    }

    /** @return list<string> */
    private static function skip(string $id): array
    {
        return in_array($id, Site::REGIONS, true) ? Checks::REGION_SKIP : ['missing_required_fact'];
    }

    private function fresh(SessionRecord $session): SessionRecord
    {
        $fresh = $this->sessions->find($session->id, true) ?? throw new Refused('session_missing');
        if ($fresh->stopped()) {
            throw new Refused('stopped');
        }
        if (! $fresh->isSite()) {
            throw new Refused('not_a_site');
        }

        return $fresh;
    }

    private function changed(SessionRecord $session, Site $site, string $change, string $page, array $extra = []): void
    {
        $this->workspace->record($session->id, EventTypes::SITE_CHANGED, Workspace::AGENT,
            ['site' => $site->key, 'version' => $site->version, 'change' => $change, 'page' => $page, ...$extra]);
    }
}
