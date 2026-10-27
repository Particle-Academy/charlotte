<?php

declare(strict_types=1);

namespace Charlotte\Drafts;

use Closure;
use FancyCms\PageSession;

/**
 * Where a page's DRAFT and its published copy live. Bind your own to store
 * pages in your schema; the default is {@see \Charlotte\Storage\DatabasePageRepository}.
 *
 * The draft is a fancy-cms PageSession: the document plus its complete,
 * attributed history. Charlotte only ever edits the draft. `publish()` is
 * called by {@see \Charlotte\CharlotteSession::publish()}, which only host code
 * calls; no tool reaches it.
 */
interface PageRepository
{
    /** Create the draft from $document if this page has none. Never replaces an existing draft. */
    public function ensure(string $tenant, string $page, array $document): void;

    /** The current draft. */
    public function read(string $tenant, string $page): PageSession;

    /**
     * Load the draft under a lock, pass it to $edit, then save it. If $edit
     * throws, nothing is saved. Implementations must hold the lock across the
     * whole load -> edit -> save, so two writers never both start from the same
     * old state.
     *
     * @template T
     *
     * @param  Closure(PageSession): T  $edit
     * @return T
     */
    public function edit(string $tenant, string $page, Closure $edit): mixed;

    /** @return array{doc: array, revision: string, by: ?string, at: ?string}|null */
    public function published(string $tenant, string $page): ?array;

    public function publish(string $tenant, string $page, array $doc, string $revision, string $by): void;
}
