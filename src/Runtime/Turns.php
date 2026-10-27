<?php

declare(strict_types=1);

namespace Charlotte\Runtime;

use Charlotte\Events\EventTypes;
use Charlotte\Events\Ulid;
use Charlotte\Policy\Refused;
use Charlotte\Sessions\SessionRecord;
use Charlotte\Storage\SessionStore;
use Charlotte\Workspace;
use Illuminate\Database\ConnectionInterface;

/** Starting and stopping the agent's turns. */
final class Turns
{
    public const MAX_MESSAGE_CHARS = 8000;

    public function __construct(
        private readonly Workspace $workspace,
        private readonly SessionStore $sessions,
        private readonly ConnectionInterface $db,
        private readonly TurnDispatcher $dispatcher,
    ) {}

    /**
     * Record the person's message, mark the session queued and hand the turn
     * to the dispatcher. One turn at a time: a message while a turn is queued,
     * running or stopping is refused with `turn_in_progress`.
     *
     * @return int the seq of the `message.created` event
     */
    public function send(SessionRecord $session, string $text, string $by): int
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > self::MAX_MESSAGE_CHARS || preg_match('//u', $text) !== 1) {
            throw new Refused('invalid_request');
        }
        $seq = $this->db->transaction(function () use ($session, $text, $by): int {
            $session = $this->sessions->find($session->id, true) ?? throw new Refused('session_missing');
            if (in_array($session->status, ['queued', 'running', 'stopping'], true)) {
                throw new Refused('turn_in_progress');
            }
            $event = $this->workspace->record($session->id, EventTypes::MESSAGE_CREATED, ['kind' => 'human', 'id' => $by],
                ['messageId' => 'm_'.Ulid::generate(), 'role' => 'user', 'text' => $text]);
            $this->workspace->status($session, 'queued', ['stop_requested_at' => null]);

            return $event->seq;
        });
        // After commit: a worker must never pick up a turn whose message rolled back.
        $this->dispatcher->dispatch($session->id, $text);

        return $seq;
    }

    /**
     * Ask the session to stop. A running turn ends within one tool call: every
     * tool refuses with `stopped` from now on (prism-harness has no
     * cross-process cancellation yet; upstream ask U3). A text-only step that
     * is already streaming finishes first.
     */
    public function stop(SessionRecord $session, string $by): void
    {
        $status = in_array($session->status, ['queued', 'running'], true) ? 'stopping' : 'stopped';
        $this->workspace->status($session, $status, ['stop_requested_at' => gmdate('Y-m-d H:i:s')]);
    }
}
