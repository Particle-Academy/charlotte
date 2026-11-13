<?php

declare(strict_types=1);

namespace Charlotte;

use Charlotte\Checks\Checks;
use Charlotte\Checks\RenderedEvidence;
use Charlotte\Checks\RenderedEvidenceSource;
use Charlotte\Data\ArrayDataCatalogue;
use Charlotte\Data\BindingPolicy;
use Charlotte\Data\DataCatalogue;
use Charlotte\Drafts\NodeTypes;
use Charlotte\Drafts\PageRepository;
use Charlotte\Events\Event;
use Charlotte\Events\EventLog;
use Charlotte\Events\EventTypes;
use Charlotte\Events\Ulid;
use Charlotte\Islands\IslandCatalogue;
use Charlotte\Islands\IslandPolicy;
use Charlotte\Policy\AssetCatalogue;
use Charlotte\Policy\AssetPolicy;
use Charlotte\Policy\LayoutPolicy;
use Charlotte\Policy\LinkPolicy;
use Charlotte\Policy\Refused;
use Charlotte\Sessions\SessionRecord;
use Charlotte\Storage\ProposalStore;
use Charlotte\Storage\SessionStore;
use FancyCms\Exceptions\PageSessionException;
use FancyCms\PageSession;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * The document a session edits: its draft, the agent's pending change set,
 * and the events every mutation records in the same transaction.
 *
 * Every method acts on `$session->page`. A site session's tools address its
 * other documents with {@see SessionRecord::onPage()}; the site-level
 * operations live in {@see SiteWorkspace}.
 *
 * Every write here is host-attributed. The agent's writes always carry actor
 * `agent`; a person's carry `human` plus the id the host passed. Neither page,
 * tenant nor actor ever comes from model arguments.
 */
final class Workspace
{
    public const AGENT = ['kind' => 'agent', 'id' => 'charlotte'];

    public const SYSTEM = ['kind' => 'system', 'id' => 'charlotte'];

    /** `doc.changed` carries the ops only up to this size; otherwise clients refetch state. */
    public const MAX_EVENT_OPS_BYTES = 65536;

    private const MAX_BATCH = 1000;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly SessionStore $sessions,
        private readonly PageRepository $pages,
        private readonly ProposalStore $proposals,
        private readonly EventLog $events,
        private readonly AssetCatalogue $assets,
        private readonly LinkPolicy $links,
        private readonly IslandCatalogue $islands,
        private readonly RenderedEvidenceSource $rendered,
        private readonly ?DataCatalogue $data = null,
        private readonly int $maxNotReady = 3,
        private readonly bool $stagedGates = true,
    ) {}

    /** The host's data streams; empty when the host binds none. */
    public function data(): DataCatalogue
    {
        return $this->data ?? ArrayDataCatalogue::empty();
    }

    public function session(string $id): SessionRecord
    {
        return $this->sessions->find($id) ?? throw new Refused('session_missing');
    }

    public function assets(): AssetCatalogue
    {
        return $this->assets;
    }

    public function islands(): IslandCatalogue
    {
        return $this->islands;
    }

    public function links(): LinkPolicy
    {
        return $this->links;
    }

    public function pages(): PageRepository
    {
        return $this->pages;
    }

    /**
     * What the agent sees: the draft, or under approve_on_save with a change
     * set open for this page, the change set's working copy.
     *
     * @return array{doc: array, revision: string, staged: bool}
     */
    public function view(SessionRecord $session): array
    {
        $open = $this->openFor($session);
        $page = $open !== null ? $this->working($open) : $this->pages->read($session->tenant, $session->page);

        return [...$page->get(), 'staged' => $open !== null];
    }

    /** @return array<string, mixed> the tool result */
    public function applyAgent(SessionRecord $session, array $ops, string $basedOn): array
    {
        return $this->db->transaction(function () use ($session, $ops, $basedOn): array {
            $page = $session->page;
            $session = ($this->sessions->find($session->id, true) ?? throw new Refused('session_missing'))->onPage($page);
            if ($session->stopped()) {
                throw new Refused('stopped');
            }
            $this->links->assertOps($ops);

            if ($session->autonomy === Autonomy::Full) {
                return $this->pages->edit($session->tenant, $session->page, function (PageSession $draft) use ($session, $ops, $basedOn): array {
                    $prior = $draft->get();
                    $result = $draft->apply($ops, 'agent', $basedOn);
                    $this->guard($prior['doc'], $result['doc'], $prior['revision']);
                    LayoutPolicy::assert($prior['doc'], $result['doc'], $prior['revision']);
                    if ($result['revision'] !== $prior['revision']) {
                        $this->changed($session, self::AGENT, $prior['revision'], $result['revision'], $ops);
                    }

                    return ['revision' => $result['revision'], 'applied' => count($ops)];
                });
            }

            return $this->stage($session, $ops, $basedOn);
        });
    }

    /** @return array{doc: array, revision: string} */
    public function applyHuman(SessionRecord $session, array $ops, string $basedOn, string $by): array
    {
        return $this->db->transaction(fn (): array => $this->pages->edit($session->tenant, $session->page,
            function (PageSession $draft) use ($session, $ops, $basedOn, $by): array {
                $prior = $draft->get();
                $result = $draft->apply($ops, 'human', $basedOn);
                $this->guard($prior['doc'], $result['doc'], $prior['revision']);
                if ($result['revision'] !== $prior['revision']) {
                    $this->changed($session, ['kind' => 'human', 'id' => $by], $prior['revision'], $result['revision'], $ops);
                }

                return $result;
            }));
    }

    public function changesSince(SessionRecord $session, string $since): array
    {
        $open = $this->openFor($session);
        $page = $open !== null ? $this->working($open) : $this->pages->read($session->tenant, $session->page);

        return $page->changesSince($since);
    }

    /** Run every code-derived check on what the agent sees and record the result. */
    public function check(SessionRecord $session, ?string $basedOn = null): array
    {
        $result = $this->checkResult($session, $basedOn);
        $this->events->append($session->id, EventTypes::CHECKS_RESULT, self::SYSTEM, $result);

        return $result;
    }

    /**
     * The checks on what the agent sees, without recording them. Asks the
     * host for a rendered report first; contrast is judged on it when it
     * names this revision (or one of $alsoAccept).
     *
     * @param  list<string>  $skip
     * @param  list<string>  $alsoAccept
     */
    public function checkResult(SessionRecord $session, ?string $basedOn = null, array $skip = [], array $alsoAccept = []): array
    {
        $view = $this->view($session);
        if ($basedOn !== null) {
            $this->assertCurrent($view['revision'], $basedOn);
        }
        [$rendered, $unavailable] = $this->rendered($session);

        return Checks::run($view['doc'], $view['revision'], $session->brief, $this->links, $rendered, $alsoAccept, $skip, $unavailable, $this->data(), $this->assets->catalogue(), $this->stagedGates);
    }

    /**
     * Mark the observed revision ready for a person's review. Never publishes, never freezes editing.
     * `$markSession` false (one page of a site, finished by its page sub-agent) records the page's
     * readiness without setting the session's ready revision, which on a site is the SITE revision.
     */
    public function finish(SessionRecord $session, string $basedOn, bool $markSession = true, array $skip = [], ?string $draftPage = null, ?string $run = null): array
    {
        $result = $this->db->transaction(function () use ($session, $basedOn, $markSession, $skip): array {
            $view = $this->view($session);
            $this->assertCurrent($view['revision'], $basedOn);
            $checks = $this->checkResult($session, $basedOn, $skip);
            $this->events->append($session->id, EventTypes::CHECKS_RESULT, self::SYSTEM, $checks);
            $gates = Checks::gates($checks['defects']);
            if ($gates !== []) {
                return ['refused' => $gates, 'revision' => $view['revision']]; // the checks.result stays on the log
            }
            if ($markSession) {
                $this->sessions->update($session->id, ['ready_revision' => $view['revision']]);
            }
            $this->events->append($session->id, EventTypes::DRAFT_READY, self::AGENT,
                ['revision' => $view['revision'], 'staged' => $view['staged']] + $this->pageField($session));

            return ['revision' => $view['revision'], 'ready' => true, 'staged' => $view['staged'], 'checks' => $checks['summary']];
        });
        if (isset($result['refused'])) {
            $this->refuseFinish($session->id, $result['revision'], $result['refused'], $draftPage, $run);
        }
        $this->sessions->resetRefusals($session->id);

        return $result;
    }

    /** The configured number of consecutive not_ready results after which the agent is stopped. */
    public function maxNotReady(): int
    {
        return $this->maxNotReady;
    }

    /**
     * Whether this session's agent has hit the not_ready limit and must stop
     * rather than retry: within `$run` (a sub-agent's run), or session-wide.
     */
    public function limited(string $sessionId, ?string $run = null): bool
    {
        return $this->sessions->refusals($sessionId, $run) >= $this->maxNotReady;
    }

    /** New work began (a turn, a page sub-agent): count refusals from zero. */
    public function resetRefusals(string $sessionId): void
    {
        $this->sessions->resetRefusals($sessionId);
    }

    /**
     * Count one more consecutive refusal and refuse: `not_ready` with the
     * located defects, or at the limit `not_ready_limit`, which tells the
     * agent to stop and hand the defects back (with its draft reference
     * when it is a page sub-agent). A sub-agent passes its harness `$run`:
     * each child run counts from zero, with no spawn hook needed.
     *
     * @param  list<array>  $defects  the gate defects
     */
    public function refuseFinish(string $sessionId, string $revision, array $defects, ?string $draftPage = null, ?string $run = null): never
    {
        $count = $this->sessions->refusals($sessionId, $run) + 1;
        $this->sessions->setRefusals($sessionId, $count, $run);
        $limit = $count >= $this->maxNotReady;

        throw new Refused($limit ? 'not_ready_limit' : 'not_ready', $revision, ['defects' => $defects, 'refusals' => $count, 'limit' => $this->maxNotReady]
            + ($limit && $draftPage !== null ? ['draft' => ['page' => $draftPage, 'revision' => $revision]] : []));
    }

    /**
     * Save a pending change set to the draft of the page it was made on. A
     * set that no longer applies resolves `conflicted` and writes nothing.
     */
    public function approve(SessionRecord $session, string $proposalId, string $by): array
    {
        return $this->db->transaction(function () use ($session, $proposalId, $by): array {
            $proposal = $this->openProposal($session, $proposalId);
            $session = $session->onPage($proposal->page_key === null ? $session->page : (string) $proposal->page_key);
            $ops = ProposalStore::ops($proposal);
            try {
                $result = $this->pages->edit($session->tenant, $session->page, function (PageSession $draft) use ($session, $ops, $proposalId, $by): array {
                    $prior = $draft->get();
                    $result = $prior;
                    foreach (array_chunk($ops, self::MAX_BATCH) as $chunk) {
                        $result = $draft->apply($chunk, 'agent');
                    }
                    $this->guard($prior['doc'], $result['doc'], $prior['revision']);
                    if ($result['revision'] !== $prior['revision']) {
                        $this->changed($session, self::AGENT, $prior['revision'], $result['revision'], $ops, ['proposalId' => $proposalId, 'approvedBy' => $by]);
                    }

                    return $result;
                });
                $state = 'accepted';
                $revision = $result['revision'];
            } catch (PageSessionException|Refused $e) {
                if ($e instanceof PageSessionException && $e->getErrorCode() === 'invalid_state') {
                    throw $e;
                }
                $state = 'conflicted';
                $revision = $this->pages->read($session->tenant, $session->page)->get()['revision'];
            }
            $this->proposals->resolve($proposalId, $state, $by);
            $this->events->append($session->id, EventTypes::PROPOSAL_RESOLVED, ['kind' => 'human', 'id' => $by],
                ['proposalId' => $proposalId, 'state' => $state, 'revision' => $revision] + $this->pageField($session));

            return ['state' => $state, 'revision' => $revision];
        });
    }

    public function reject(SessionRecord $session, string $proposalId, string $by): array
    {
        return $this->db->transaction(function () use ($session, $proposalId, $by): array {
            $proposal = $this->openProposal($session, $proposalId);
            $session = $session->onPage($proposal->page_key === null ? $session->page : (string) $proposal->page_key);
            $revision = $this->pages->read($session->tenant, $session->page)->get()['revision'];
            $this->proposals->resolve($proposalId, 'rejected', $by);
            $this->events->append($session->id, EventTypes::PROPOSAL_RESOLVED, ['kind' => 'human', 'id' => $by],
                ['proposalId' => $proposalId, 'state' => 'rejected', 'revision' => $revision] + $this->pageField($session));

            return ['state' => 'rejected', 'revision' => $revision];
        });
    }

    /**
     * Copy the draft, at the revision the person saw, to the published slot.
     * Host code only: no tool and no agent path reaches this. A site session
     * publishes through {@see SiteWorkspace::publish()}, which calls this per
     * document inside one transaction.
     */
    public function publish(SessionRecord $session, string $basedOn, string $by, bool $record = true): array
    {
        return $this->db->transaction(function () use ($session, $basedOn, $by, $record): array {
            return $this->pages->edit($session->tenant, $session->page, function (PageSession $draft) use ($session, $basedOn, $by, $record): array {
                $current = $draft->get();
                $this->assertCurrent($current['revision'], $basedOn);
                AssetPolicy::assert($current['doc'], $this->assets, $current['revision']);
                $this->pages->publish($session->tenant, $session->page, $current['doc'], $current['revision'], $by);
                if ($record) {
                    $this->events->append($session->id, EventTypes::DRAFT_PUBLISHED, ['kind' => 'human', 'id' => $by], ['revision' => $current['revision']]);
                }

                return ['revision' => $current['revision'], 'published' => true];
            });
        });
    }

    /** @return list<array{proposalId: string, state: string}> */
    public function notices(SessionRecord $session): array
    {
        return $this->db->transaction(fn (): array => $this->proposals->takeNotices($session->id));
    }

    /** Record a status change for the session. */
    public function status(SessionRecord $session, string $status, array $fields = []): void
    {
        $this->db->transaction(function () use ($session, $status, $fields): void {
            $this->sessions->update($session->id, ['status' => $status, ...$fields]);
            $this->events->append($session->id, EventTypes::SESSION_STATUS, self::SYSTEM, ['status' => $status]);
        });
    }

    /** The whole state a client needs to render, plus the cursor to poll from. */
    public function state(SessionRecord $session): array
    {
        $session = $this->session($session->id);
        $draft = $this->pages->read($session->tenant, $session->page)->get();
        $open = $this->openFor($session, applies: true);
        $proposal = null;
        if ($open !== null) {
            $working = $this->working($open)->get();
            $proposal = ['id' => (string) $open->id, 'baseRevision' => (string) $open->base_revision,
                'revision' => $working['revision'], 'opCount' => (int) $open->op_count, 'batches' => (int) $open->batches,
                'ops' => ProposalStore::ops($open), 'doc' => $working['doc']];
        }
        $published = $this->pages->published($session->tenant, $session->page);

        return [
            'session' => $session->summary(),
            'doc' => $draft['doc'],
            'revision' => $draft['revision'],
            'readyRevision' => $session->readyRevision,
            'proposal' => $proposal,
            'published' => $published === null ? null : ['revision' => $published['revision'], 'by' => $published['by'], 'at' => $published['at']],
            'cursor' => $this->events->latest($session->id),
        ];
    }

    /** approve_on_save: apply to the working copy and record it in this page's open change set. */
    private function stage(SessionRecord $session, array $ops, string $basedOn): array
    {
        $open = $this->proposals->openFor($session->id, $this->proposalPage($session), true);
        $working = $open !== null ? $this->working($open) : $this->pages->read($session->tenant, $session->page);
        $prior = $working->get();
        $result = $working->apply($ops, 'agent', $basedOn);
        $this->guard($prior['doc'], $result['doc'], $prior['revision']);
        LayoutPolicy::assert($prior['doc'], $result['doc'], $prior['revision']);
        if ($result['revision'] === $prior['revision']) {
            return ['revision' => $prior['revision'], 'applied' => 0, 'staged' => $open !== null] + ($open !== null ? ['proposalId' => (string) $open->id] : []);
        }

        $batch = $this->eventOps($ops);
        if ($open === null) {
            $id = Ulid::generate();
            $this->proposals->create($id, $session->id, $prior['revision'], $working->toJson(), $ops, $session->isSite() ? $session->page : null);
            $this->events->append($session->id, EventTypes::PROPOSAL_CREATED, self::AGENT,
                ['proposalId' => $id, 'baseRevision' => $prior['revision'], 'revision' => $result['revision'], 'opCount' => count($ops), ...$batch] + $this->pageField($session));
        } else {
            $id = (string) $open->id;
            $all = [...ProposalStore::ops($open), ...$ops];
            $this->proposals->append($id, $working->toJson(), $all, (int) $open->batches + 1);
            $this->events->append($session->id, EventTypes::PROPOSAL_UPDATED, self::AGENT,
                ['proposalId' => $id, 'revision' => $result['revision'], 'opCount' => count($all), ...$batch] + $this->pageField($session));
        }

        return ['revision' => $result['revision'], 'applied' => count($ops), 'staged' => true, 'proposalId' => $id];
    }

    /** The open change set this page's view reads, when the session stages. */
    private function openFor(SessionRecord $session, bool $applies = false): ?object
    {
        if ($session->autonomy !== Autonomy::ApproveOnSave && ! $applies) {
            return null;
        }

        return $this->proposals->openFor($session->id, $this->proposalPage($session));
    }

    /** Page sessions keep 0.3's one-set-per-session rows (page_key null); site sessions key by page. */
    private function proposalPage(SessionRecord $session): ?string
    {
        return $session->isSite() ? $session->page : null;
    }

    private function working(object $open): PageSession
    {
        $json = (string) $open->working_json;

        return PageSession::fromJson($json, NodeTypes::forStored($json, $this->islands->types()));
    }

    /** The policies every write passes, for every actor: assets, islands, then data bindings. */
    private function guard(array $before, array $after, ?string $revision): void
    {
        AssetPolicy::assert($after, $this->assets, $revision);
        IslandPolicy::assert($before, $after, $this->islands, $this->assets, $revision);
        BindingPolicy::assert($before, $after, $this->data(), $revision);
    }

    /** @return array{0: ?RenderedEvidence, 1: ?string} the report, or why there is none */
    private function rendered(SessionRecord $session): array
    {
        try {
            return [$this->rendered->for($session, $session->page), null];
        } catch (Throwable) {
            return [null, 'rendered_report_failed'];
        }
    }

    private function openProposal(SessionRecord $session, string $proposalId): object
    {
        $proposal = $this->proposals->find($proposalId, true);
        if ($proposal === null || (string) $proposal->session_id !== $session->id) {
            throw new Refused('proposal_missing');
        }
        if ($proposal->state !== 'open') {
            throw new Refused('proposal_resolved');
        }

        return $proposal;
    }

    private function changed(SessionRecord $session, array $actor, string $before, string $after, array $ops, array $extra = []): void
    {
        $this->events->append($session->id, EventTypes::DOC_CHANGED, $actor,
            ['revision' => $after, 'previousRevision' => $before, 'opCount' => count($ops), ...$this->eventOps($ops), ...$extra] + $this->pageField($session));
    }

    /** Site sessions say which document an event is about; page sessions are unchanged. */
    private function pageField(SessionRecord $session): array
    {
        return $session->isSite() ? ['page' => $session->page] : [];
    }

    /** The ops, when small enough to carry; otherwise nothing and clients refetch. */
    private function eventOps(array $ops): array
    {
        $json = json_encode($ops, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json !== false && strlen($json) <= self::MAX_EVENT_OPS_BYTES ? ['ops' => $ops] : [];
    }

    public function assertCurrent(string $current, string $basedOn, string $hint = 'Read the current page and attributed changes before retrying.'): void
    {
        if (! hash_equals($current, $basedOn)) {
            throw new PageSessionException('surface_changed_under_you', [['index' => null, 'path' => '$.basedOn', 'expected' => 'current revision',
                'got' => 'refused', 'hint' => $hint]], $current);
        }
    }

    /** Exposed for the event-recording runtime and the HTTP kit. */
    public function events(): EventLog
    {
        return $this->events;
    }

    public function record(string $session, string $type, array $actor, array $data): Event
    {
        return $this->events->append($session, $type, $actor, $data);
    }
}
