<?php

declare(strict_types=1);

namespace Charlotte;

use Charlotte\Sessions\SessionRecord;
use Charlotte\Tools\PageTools;
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
        return $this->workspace()->state($this->record());
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
        return new PageTools($this->workspace(), $this->id);
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

    /** Run the code-derived checks on the current view. */
    public function check(): array
    {
        return $this->workspace()->check($this->record());
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
     * page is published; Charlotte never calls it on its own.
     */
    public function publish(string $basedOn, string $by): array
    {
        return $this->workspace()->publish($this->record(), $basedOn, $by);
    }

    private function workspace(): Workspace
    {
        return $this->charlotte->container()->make(Workspace::class);
    }
}
