<?php

declare(strict_types=1);

namespace Charlotte\Events;

/** A process-local log: for tests, demos and a host that brings its own persistence. */
final class InMemoryEventLog implements EventLog
{
    /** @var array<string, list<Event>> */
    private array $events = [];

    public function append(string $session, string $type, array $actor, array $data): Event
    {
        Event::assertType($type);
        Event::assertActor($actor);
        $data = json_decode(Event::encodeData($data), true, 512, JSON_THROW_ON_ERROR);
        $event = new Event(Ulid::generate(), $this->latest($session) + 1, $session, Event::now(), $type, $actor, $data);
        $this->events[$session][] = $event;

        return $event;
    }

    public function after(string $session, int $after, int $limit = 200): array
    {
        $out = [];
        foreach ($this->events[$session] ?? [] as $event) {
            if ($event->seq > $after) {
                $out[] = $event;
                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        return $out;
    }

    public function latest(string $session): int
    {
        $events = $this->events[$session] ?? [];

        return $events === [] ? 0 : $events[array_key_last($events)]->seq;
    }
}
