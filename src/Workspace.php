<?php

declare(strict_types=1);

namespace Charlotte;

use Charlotte\Checks\Checks;
use Charlotte\Drafts\NodeTypes;
use Charlotte\Drafts\PageRepository;
use Charlotte\Events\Event;
use Charlotte\Events\EventLog;
use Charlotte\Events\EventTypes;
use Charlotte\Events\Ulid;
use Charlotte\Policy\AssetCatalogue;
use Charlotte\Policy\AssetPolicy;
use Charlotte\Policy\LinkPolicy;
use Charlotte\Policy\Refused;
use Charlotte\Sessions\SessionRecord;
use Charlotte\Storage\ProposalStore;
use Charlotte\Storage\SessionStore;
use FancyCms\Exceptions\PageSessionException;
use FancyCms\PageSession;
use Illuminate\Database\ConnectionInterface;

/**
 * The page a session edits: its draft, the agent's pending change set, and
 * the events every mutation records in the same transaction.
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
    ) {}

    public function session(string $id): SessionRecord
    {
        return $this->sessions->find($id) ?? throw new Refused('session_missing');
    }

    public function assets(): AssetCatalogue
    {
        return $this->assets;
    }

    /**
     * What the agent sees: the draft, or under approve_on_save with a change
     * set open, the change set's working copy.
     *
     * @return array{doc: array, revision: string, staged: bool}
     */
    public function view(SessionRecord $session): array
    {
        $open = $session->autonomy === Autonomy::ApproveOnSave ? $this->proposals->openFor($session->id) : null;
        $page = $open !== null
            ? PageSession::fromJson((string) $open->working_json, NodeTypes::allowed())
            : $this->pages->read($session->tenant, $session->page);

        return [...$page->get(), 'staged' => $open !== null];
    }

    /** @return array<string, mixed> the tool result */
    public function applyAgent(SessionRecord $session, array $ops, string $basedOn): array
    {
        return $this->db->transaction(function () use ($session, $ops, $basedOn): array {
            $session = $this->sessions->find($session->id, true) ?? throw new Refused('session_missing');
            if ($session->stopped()) {
                throw new Refused('stopped');
            }
            $this->links->assertOps($ops);

            if ($session->autonomy === Autonomy::Full) {
                return $this->pages->edit($session->tenant, $session->page, function (PageSession $draft) use ($session, $ops, $basedOn): array {
                    $before = $draft->get()['revision'];
                    $result = $draft->apply($ops, 'agent', $basedOn);
                    AssetPolicy::assert($result['doc'], $this->assets, $before);
                    if ($result['revision'] !== $before) {
                        $this->changed($session, self::AGENT, $before, $result['revision'], $ops);
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
                $before = $draft->get()['revision'];
                $result = $draft->apply($ops, 'human', $basedOn);
                AssetPolicy::assert($result['doc'], $this->assets, $before);
                if ($result['revision'] !== $before) {
                    $this->changed($session, ['kind' => 'human', 'id' => $by], $before, $result['revision'], $ops);
                }

                return $result;
            }));
    }

    public function changesSince(SessionRecord $session, string $since): array
    {
        $open = $session->autonomy === Autonomy::ApproveOnSave ? $this->proposals->openFor($session->id) : null;
        $page = $open !== null
            ? PageSession::fromJson((string) $open->working_json, NodeTypes::allowed())
            : $this->pages->read($session->tenant, $session->page);

        return $page->changesSince($since);
    }

    /** Run every code-derived check on what the agent sees and record the result. */
    public function check(SessionRecord $session, ?string $basedOn = null): array
    {
        $view = $this->view($session);
        if ($basedOn !== null) {
            $this->assertCurrent($view['revision'], $basedOn);
        }
        $result = Checks::run($view['doc'], $view['revision'], $session->brief, $this->links);
        $this->events->append($session->id, EventTypes::CHECKS_RESULT, self::SYSTEM, $result);

        return $result;
    }

    /** Mark the observed revision ready for a person's review. Never publishes, never freezes editing. */
    public function finish(SessionRecord $session, string $basedOn): array
    {
        return $this->db->transaction(function () use ($session, $basedOn): array {
            $view = $this->view($session);
            $this->assertCurrent($view['revision'], $basedOn);
            $checks = $this->check($session, $basedOn);
            $this->sessions->update($session->id, ['ready_revision' => $view['revision']]);
            $this->events->append($session->id, EventTypes::DRAFT_READY, self::AGENT,
                ['revision' => $view['revision'], 'staged' => $view['staged']]);

            return ['revision' => $view['revision'], 'ready' => true, 'staged' => $view['staged'], 'checks' => $checks['summary']];
        });
    }

    /** Save a pending change set to the draft. A set that no longer applies resolves `conflicted` and writes nothing. */
    public function approve(SessionRecord $session, string $proposalId, string $by): array
    {
        return $this->db->transaction(function () use ($session, $proposalId, $by): array {
            $proposal = $this->openProposal($session, $proposalId);
            $ops = ProposalStore::ops($proposal);
            try {
                $result = $this->pages->edit($session->tenant, $session->page, function (PageSession $draft) use ($session, $ops, $proposalId, $by): array {
                    $before = $draft->get()['revision'];
                    $result = $draft->get();
                    foreach (array_chunk($ops, self::MAX_BATCH) as $chunk) {
                        $result = $draft->apply($chunk, 'agent');
                    }
                    AssetPolicy::assert($result['doc'], $this->assets, $before);
                    if ($result['revision'] !== $before) {
                        $this->changed($session, self::AGENT, $before, $result['revision'], $ops, ['proposalId' => $proposalId, 'approvedBy' => $by]);
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
                ['proposalId' => $proposalId, 'state' => $state, 'revision' => $revision]);

            return ['state' => $state, 'revision' => $revision];
        });
    }

    public function reject(SessionRecord $session, string $proposalId, string $by): array
    {
        return $this->db->transaction(function () use ($session, $proposalId, $by): array {
            $this->openProposal($session, $proposalId);
            $revision = $this->pages->read($session->tenant, $session->page)->get()['revision'];
            $this->proposals->resolve($proposalId, 'rejected', $by);
            $this->events->append($session->id, EventTypes::PROPOSAL_RESOLVED, ['kind' => 'human', 'id' => $by],
                ['proposalId' => $proposalId, 'state' => 'rejected', 'revision' => $revision]);

            return ['state' => 'rejected', 'revision' => $revision];
        });
    }

    /**
     * Copy the draft, at the revision the person saw, to the published slot.
     * Host code only: no tool and no agent path reaches this.
     */
    public function publish(SessionRecord $session, string $basedOn, string $by): array
    {
        return $this->db->transaction(function () use ($session, $basedOn, $by): array {
            return $this->pages->edit($session->tenant, $session->page, function (PageSession $draft) use ($session, $basedOn, $by): array {
                $current = $draft->get();
                $this->assertCurrent($current['revision'], $basedOn);
                AssetPolicy::assert($current['doc'], $this->assets, $current['revision']);
                $this->pages->publish($session->tenant, $session->page, $current['doc'], $current['revision'], $by);
                $this->events->append($session->id, EventTypes::DRAFT_PUBLISHED, ['kind' => 'human', 'id' => $by], ['revision' => $current['revision']]);

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
        $open = $this->proposals->openFor($session->id);
        $proposal = null;
        if ($open !== null) {
            $working = PageSession::fromJson((string) $open->working_json, NodeTypes::allowed())->get();
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

    /** approve_on_save: apply to the working copy and record it in the open change set. */
    private function stage(SessionRecord $session, array $ops, string $basedOn): array
    {
        $open = $this->proposals->openFor($session->id, true);
        $working = $open !== null
            ? PageSession::fromJson((string) $open->working_json, NodeTypes::allowed())
            : $this->pages->read($session->tenant, $session->page);
        $before = $working->get()['revision'];
        $result = $working->apply($ops, 'agent', $basedOn);
        AssetPolicy::assert($result['doc'], $this->assets, $before);
        if ($result['revision'] === $before) {
            return ['revision' => $before, 'applied' => 0, 'staged' => $open !== null] + ($open !== null ? ['proposalId' => (string) $open->id] : []);
        }

        $batch = $this->eventOps($ops);
        if ($open === null) {
            $id = Ulid::generate();
            $this->proposals->create($id, $session->id, $before, $working->toJson(), $ops);
            $this->events->append($session->id, EventTypes::PROPOSAL_CREATED, self::AGENT,
                ['proposalId' => $id, 'baseRevision' => $before, 'revision' => $result['revision'], 'opCount' => count($ops), ...$batch]);
        } else {
            $id = (string) $open->id;
            $all = [...ProposalStore::ops($open), ...$ops];
            $this->proposals->append($id, $working->toJson(), $all, (int) $open->batches + 1);
            $this->events->append($session->id, EventTypes::PROPOSAL_UPDATED, self::AGENT,
                ['proposalId' => $id, 'revision' => $result['revision'], 'opCount' => count($all), ...$batch]);
        }

        return ['revision' => $result['revision'], 'applied' => count($ops), 'staged' => true, 'proposalId' => $id];
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
            ['revision' => $after, 'previousRevision' => $before, 'opCount' => count($ops), ...$this->eventOps($ops), ...$extra]);
    }

    /** The ops, when small enough to carry; otherwise nothing and clients refetch. */
    private function eventOps(array $ops): array
    {
        $json = json_encode($ops, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json !== false && strlen($json) <= self::MAX_EVENT_OPS_BYTES ? ['ops' => $ops] : [];
    }

    private function assertCurrent(string $current, string $basedOn): void
    {
        if (! hash_equals($current, $basedOn)) {
            throw new PageSessionException('surface_changed_under_you', [['index' => null, 'path' => '$.basedOn', 'expected' => 'current revision',
                'got' => 'refused', 'hint' => 'Read the current page and attributed changes before retrying.']], $current);
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
