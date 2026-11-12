<?php

declare(strict_types=1);

namespace Charlotte\Site;

use Closure;

/**
 * Where a site's STRUCTURE lives (pages, kinds, titles, routes). The pages'
 * documents live in the {@see \Charlotte\Drafts\PageRepository}. Bind your own
 * to keep sites in your schema; the default is
 * {@see \Charlotte\Storage\DatabaseSiteRepository}.
 */
interface SiteRepository
{
    /** Create the site, empty, if it does not exist. Never replaces one. */
    public function ensure(string $tenant, string $site): Site;

    public function find(string $tenant, string $site): ?Site;

    /**
     * Load the site under a lock, pass it to $edit, and save the Site it
     * returns. If $edit throws, nothing is saved. The lock must be held across
     * load -> edit -> save.
     *
     * @param  Closure(Site): Site  $edit
     */
    public function edit(string $tenant, string $site, Closure $edit): Site;
}
