<?php

declare(strict_types=1);

namespace Charlotte\Site;

use Charlotte\Policy\Refused;
use InvalidArgumentException;

/**
 * A site's structure: its pages, each with an id, a kind, a title and a
 * route, plus two shared regions (`@header`, `@footer`) rendered on every
 * page. Immutable; every `with*` returns a new version with `version + 1`.
 *
 * Documents live in the {@see \Charlotte\Drafts\PageRepository} under page
 * keys derived here: `<site>/<page id>` and `<site>/@header`. A site key
 * cannot contain a slash and a page id cannot either, so one site's keys can
 * never name another site's documents.
 */
final readonly class Site
{
    public const PAGE_ID = '/\A[a-z][a-z0-9-]{0,47}\z/';

    public const KEY = '/\A[\x21-\x2e\x30-\x7e]{1,128}\z/';

    public const REGIONS = ['@header', '@footer'];

    public const KINDS = ['page', 'not_found'];

    public const ROUTE = '/\A\/(?:[a-z0-9]+(?:-[a-z0-9]+)*(?:\/[a-z0-9]+(?:-[a-z0-9]+)*){0,7})?\z/';

    public const ROUTE_MAX = 128;

    public const TITLE_MAX = 120;

    /** @param list<array{id: string, kind: string, title: string, route: ?string}> $pages */
    public function __construct(public string $key, public int $version, public array $pages)
    {
        if (preg_match(self::KEY, $key) !== 1) {
            throw new InvalidArgumentException('A site key is 1-128 printable ASCII characters with no slash.');
        }
    }

    public static function fromArray(array $data): self
    {
        $pages = [];
        foreach ((array) ($data['pages'] ?? []) as $page) {
            $pages[] = ['id' => (string) $page['id'], 'kind' => (string) $page['kind'], 'title' => (string) $page['title'],
                'route' => isset($page['route']) && $page['route'] !== '' ? (string) $page['route'] : null];
        }

        return new self((string) $data['key'], (int) ($data['version'] ?? 0), $pages);
    }

    /** @return array{key: string, version: int, pages: list<array>} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'version' => $this->version, 'pages' => $this->pages];
    }

    public static function validRoute(string $route): bool
    {
        return strlen($route) <= self::ROUTE_MAX && preg_match(self::ROUTE, $route) === 1;
    }

    /** The PageRepository key for a page id or region. */
    public function documentKey(string $id): string
    {
        return $this->key.'/'.$id;
    }

    /** @return list<string> every document id: regions first, then pages in creation order */
    public function documentIds(): array
    {
        return [...self::REGIONS, ...array_column($this->pages, 'id')];
    }

    /** The id a document key names in this site, or null when it is not one of this site's. */
    public function idFor(string $documentKey): ?string
    {
        $prefix = $this->key.'/';
        if (! str_starts_with($documentKey, $prefix)) {
            return null;
        }
        $id = substr($documentKey, strlen($prefix));

        return in_array($id, $this->documentIds(), true) ? $id : null;
    }

    /** @return array{id: string, kind: string, title: string, route: ?string}|null */
    public function page(string $id): ?array
    {
        foreach ($this->pages as $page) {
            if ($page['id'] === $id) {
                return $page;
            }
        }

        return null;
    }

    public function pageWithRoute(string $route): ?array
    {
        foreach ($this->pages as $page) {
            if ($page['route'] === $route) {
                return $page;
            }
        }

        return null;
    }

    public function notFound(): ?array
    {
        foreach ($this->pages as $page) {
            if ($page['kind'] === 'not_found') {
                return $page;
            }
        }

        return null;
    }

    /**
     * Add a page after validating it against this site. Refusals carry
     * stable codes the agent can act on.
     */
    public function withPage(array $page, ?ReservedRoutes $reserved = null, ?int $maxPages = null): self
    {
        $page = ['id' => (string) $page['id'], 'kind' => (string) $page['kind'], 'title' => (string) $page['title'],
            'route' => isset($page['route']) && $page['route'] !== '' ? (string) $page['route'] : null];
        if ($this->page($page['id']) !== null) {
            throw new Refused('site_page_exists', null, ['page' => $page['id']]);
        }
        if ($maxPages !== null && count($this->pages) >= $maxPages) {
            throw new Refused('site_full', null, ['maxPages' => $maxPages]);
        }
        if ($page['kind'] === 'not_found' && $this->notFound() !== null) {
            throw new Refused('site_not_found_exists', null, ['page' => $this->notFound()['id']]);
        }
        $this->assertRoute($page, $page['route'], $reserved);

        return new self($this->key, $this->version + 1, [...$this->pages, $page]);
    }

    public function withRoute(string $id, ?string $route, ?ReservedRoutes $reserved = null): self
    {
        $page = $this->page($id) ?? throw new Refused('site_page_missing', null, ['page' => $id]);
        $this->assertRoute($page, $route, $reserved);
        $pages = array_map(fn (array $p) => $p['id'] === $id ? ['route' => $route] + $p : $p, $this->pages);

        return new self($this->key, $this->version + 1, $pages);
    }

    private function assertRoute(array $page, ?string $route, ?ReservedRoutes $reserved): void
    {
        if ($route === null) {
            return;
        }
        if ($page['kind'] === 'not_found' || ! self::validRoute($route)) {
            throw new Refused('route_invalid', null, ['route' => $route]);
        }
        $match = $reserved?->match($route);
        if ($match !== null) {
            throw new Refused('route_reserved', null, ['route' => $route, 'reserved' => $match]);
        }
        $owner = $this->pageWithRoute($route);
        if ($owner !== null && $owner['id'] !== $page['id']) {
            throw new Refused('route_taken', null, ['route' => $route, 'page' => $owner['id']]);
        }
    }
}
