<?php

declare(strict_types=1);

namespace Charlotte\Events;

/**
 * The ordered, durable record every transport delivers.
 *
 * The server writes here; a long-poll, a socket nudge or an SSE stream only
 * ever deliver what is already in the log. Push is an accelerator, never the
 * source of truth.
 */
interface EventLog
{
    /**
     * Append one event, assigning the next seq for the session. A database
     * log joins the caller's transaction, so an event recorded beside a
     * mutation commits or rolls back with it.
     *
     * @param  array{kind: string, id: string}  $actor
     * @param  array<string, mixed>  $data
     *
     * @throws \InvalidArgumentException for a malformed type, actor or oversized data
     */
    public function append(string $session, string $type, array $actor, array $data): Event;

    /**
     * Events with seq greater than $after, in seq order, at most $limit.
     *
     * @return list<Event>
     */
    public function after(string $session, int $after, int $limit = 200): array;

    /** The newest seq for the session, or 0. */
    public function latest(string $session): int;
}
