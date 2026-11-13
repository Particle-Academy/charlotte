<?php

declare(strict_types=1);

namespace Charlotte;

use Charlotte\Guide\BuilderMode;
use Charlotte\Runtime\PageSubagents;
use Charlotte\Sessions\SessionRecord;
use Charlotte\Tools\PageTools;
use InvalidArgumentException;
use Charlotte\Transport\LongPoll;

/**
 * A handle on one open session: the methods a host calls.
 *
 * Every method re-reads the stored session, so a handle held across requests
 * never acts on a stale status or stop flag.
 */
final class CharlotteSession
{
    public function __construct(private readonly Charlotte $charlotte, private readonly string $id) {}

    public function id(): string
    {
        return $this->id;
    }

    public function record(): SessionRecord
    {
        return $this->workspace()->session($this->id);
    }

    /** Everything a client renders, plus the event cursor to poll from. */
    public function state(): array
    {
        $record = $this->record();
        $state = $this->workspace()->state($record);

        // A site session's doc/revision/proposal are the SELECTED document's;
        // `site` is the structure and the site revision publish() takes.
        return $record->isSite() ? $state + ['site' => $this->sites()->describe($record)] : $state;
    }

    /**
     * The current page's document as it was at $revision, or null when its
     * history does not hold that revision. For a host lab judging a critique's
     * `before` and `after` revisions (`critique.revised`). On a site session,
     * the selected document's history.
     */
    public function documentAt(string $revision): ?array
    {
        return $this->workspace()->documentAt($this->record(), $revision);
    }

    /**
     * Events after a cursor; waits up to $wait seconds (at most 25) for one.
     *
     * @return array{events: list<array<string, mixed>>, cursor: int}
     */
    public function events(int $after = 0, int $wait = 0): array
    {
        return $this->charlotte->container()->make(LongPoll::class)->poll($this->id, $after, $wait);
    }

    /** The builder's tools bound to this session (what the model calls). */
    public function tools(): PageTools
    {
        return new PageTools($this->workspace(), $this->id, $this->sites());
    }

    /**
     * Bind this PAGE session as the `charlotte` sub-agent a parent agent at
     * `$parentScope` will spawn (address `<parentScope>::sub::<suffix>`), on
     * this session's participant, provider and model. Host code; see
     * {@see Charlotte::delegate()}. A site session's pages are built by its
     * own orchestrator, so a site cannot be delegated this way.
     */
    public function bindAsSubagent(string $parentScope, string $scopeSuffix = BuilderMode::PAGE_SUB_NAME): void
    {
        $record = $this->record();
        if ($record->isSite() || $parentScope === '' || $scopeSuffix === '') {
            throw new InvalidArgumentException('Only a page session binds as a sub-agent, under a non-empty parent scope and suffix.');
        }
        $this->charlotte->container()->make(PageSubagents::class)
            ->bind($record, PageSubagents::participant($record), $parentScope.'::sub::'.$scopeSuffix);
    }

    /**
     * Queue a turn: the person's message, then the agent's run on the queue.
     * The model loop never runs inside the HTTP request.
     *
     * @return int the cursor of the `message.created` event
     */
    public function send(string $text, string $by): int
    {
        return $this->charlotte->turns()->send($this->record(), $text, $by);
    }

    /** Ask a running turn to stop. Tools refuse with `stopped` from now on. */
    public function stop(string $by): void
    {
        $this->charlotte->turns()->stop($this->record(), $by);
    }

    /** A person's edit, straight to the draft. Stale `basedOn` throws surface_changed_under_you. */
    public function applyHuman(array $ops, string $basedOn, string $by): array
    {
        return $this->workspace()->applyHuman($this->record(), $ops, $basedOn, $by);
    }

    /** Run the code-derived checks on the current view (on a site: the selected document plus the site-wide checks). */
    public function check(): array
    {
        $record = $this->record();
        if ($record->isSite()) {
            return $this->sites()->check($record, $this->workspace()->view($record)['revision']);
        }

        return $this->workspace()->check($record);
    }

    /** A site session's structure, regions, reserved routes and site revision (what `site_get` shows). */
    public function site(): array
    {
        return $this->sites()->describe($this->record());
    }

    /**
     * A site session as a host serves it, in the lab kit-site `site.json`
     * shape: `{pages[{route, title, description, doc}], header, footer,
     * notFound, islands}`. Drafts, or the published copies.
     */
    public function siteExport(bool $published = false): array
    {
        return $this->sites()->export($this->record(), $published);
    }

    /** approve_on_save: save the pending change set to the draft. */
    public function approve(string $proposalId, string $by): array
    {
        return $this->workspace()->approve($this->record(), $proposalId, $by);
    }

    /** approve_on_save: discard the pending change set. */
    public function reject(string $proposalId, string $by): array
    {
        return $this->workspace()->reject($this->record(), $proposalId, $by);
    }

    /**
     * Publish the draft at the revision the person reviewed. The ONLY way a
     * page is published; Charlotte never calls it on its own. On a site
     * session `$basedOn` is the site revision, and every page and region is
     * published together.
     */
    public function publish(string $basedOn, string $by): array
    {
        $record = $this->record();

        return $record->isSite()
            ? $this->sites()->publish($record, $basedOn, $by)
            : $this->workspace()->publish($record, $basedOn, $by);
    }

    private function workspace(): Workspace
    {
        return $this->charlotte->container()->make(Workspace::class);
    }

    private function sites(): SiteWorkspace
    {
        return $this->charlotte->container()->make(SiteWorkspace::class);
    }
}
