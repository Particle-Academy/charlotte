<?php

declare(strict_types=1);

namespace Charlotte\Laravel;

use Charlotte\Runtime\TurnRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * One agent turn on the queue. Never retried: a turn that already called a
 * tool must not run twice. The runner also ignores a redelivered job whose
 * session is no longer `queued`.
 */
final class RunTurn implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $sessionId, public readonly string $text) {}

    public function handle(TurnRunner $runner): void
    {
        $runner->run($this->sessionId, $this->text);
    }
}
