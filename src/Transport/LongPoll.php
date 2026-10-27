<?php

declare(strict_types=1);

namespace Charlotte\Transport;

use Charlotte\Events\Event;
use Charlotte\Events\EventLog;

/**
 * The baseline transport: `GET /events?after=<seq>&wait=<seconds>`.
 *
 * Answers at once when the log already has events after the cursor; otherwise
 * re-reads every interval until one arrives or the wait runs out. Every request
 * is short (at most 25 seconds), which is why it survives Cloudflare, where
 * only long-lived streams are reset. The cursor in the answer is what the
 * client sends next, so a dropped response loses nothing.
 */
final class LongPoll
{
    public const MAX_WAIT_SECONDS = 25;

    public function __construct(
        private readonly EventLog $log,
        private readonly Sleeper $sleeper = new UsleepSleeper,
        private readonly int $intervalMs = 250,
        private readonly int $limit = 200,
        private readonly int $maxWaitSeconds = self::MAX_WAIT_SECONDS,
    ) {}

    /** @return array{events: list<array<string, mixed>>, cursor: int} */
    public function poll(string $session, int $after, int $waitSeconds): array
    {
        $after = max(0, $after);
        $waitMs = max(0, min($waitSeconds, $this->maxWaitSeconds, self::MAX_WAIT_SECONDS)) * 1000;
        $waited = 0;
        while (true) {
            $events = $this->log->after($session, $after, $this->limit);
            if ($events !== [] || $waited >= $waitMs) {
                return [
                    'events' => array_map(fn (Event $e) => $e->toArray(), $events),
                    'cursor' => $events === [] ? $after : $events[array_key_last($events)]->seq,
                ];
            }
            $step = min(max(1, $this->intervalMs), $waitMs - $waited);
            $this->sleeper->sleep($step);
            $waited += $step;
        }
    }
}
