<?php

declare(strict_types=1);

namespace Charlotte\Storage;

use Charlotte\Events\Event;
use Charlotte\Events\EventLog;
use Charlotte\Events\Ulid;
use Illuminate\Database\ConnectionInterface;

/**
 * The default log, in `charlotte_events`.
 *
 * Appends run inside a transaction (joining the caller's, as a savepoint) and
 * lock the session row first, so two writers cannot draw the same seq. The
 * unique (session_id, seq) index is the backstop: a race that slipped past the
 * lock fails its transaction rather than leaving a gap or a duplicate.
 */
final class DatabaseEventLog implements EventLog
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function append(string $session, string $type, array $actor, array $data): Event
    {
        Event::assertType($type);
        Event::assertActor($actor);
        $json = Event::encodeData($data);

        return $this->db->transaction(function () use ($session, $type, $actor, $json): Event {
            $this->db->table('charlotte_sessions')->where('id', $session)->lockForUpdate()->first(['id']);
            $seq = (int) $this->db->table('charlotte_events')->where('session_id', $session)->max('seq') + 1;
            $event = new Event(Ulid::generate(), $seq, $session, Event::now(), $type, $actor,
                json_decode($json, true, 512, JSON_THROW_ON_ERROR));
            $this->db->table('charlotte_events')->insert([
                'session_id' => $session, 'seq' => $seq, 'event_id' => $event->id, 'type' => $type,
                'actor_kind' => $actor['kind'], 'actor_id' => $actor['id'], 'data_json' => $json, 'at' => $event->at,
            ]);

            return $event;
        });
    }

    public function after(string $session, int $after, int $limit = 200): array
    {
        return $this->db->table('charlotte_events')
            ->where('session_id', $session)->where('seq', '>', $after)
            ->orderBy('seq')->limit(max(1, $limit))->get()
            ->map(fn (object $row): Event => new Event(
                (string) $row->event_id, (int) $row->seq, (string) $row->session_id, (string) $row->at, (string) $row->type,
                ['kind' => (string) $row->actor_kind, 'id' => (string) $row->actor_id],
                json_decode((string) $row->data_json, true, 512, JSON_THROW_ON_ERROR),
            ))->all();
    }

    public function latest(string $session): int
    {
        return (int) $this->db->table('charlotte_events')->where('session_id', $session)->max('seq');
    }
}
