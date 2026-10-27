<?php

declare(strict_types=1);

namespace Charlotte\Laravel;

use Charlotte\Runtime\TurnDispatcher;
use Illuminate\Contracts\Bus\Dispatcher;

final class QueueTurnDispatcher implements TurnDispatcher
{
    public function __construct(private readonly Dispatcher $bus, private readonly ?string $connection = null, private readonly ?string $queue = null) {}

    public function dispatch(string $sessionId, string $text): void
    {
        $job = new RunTurn($sessionId, $text);
        if ($this->connection !== null) {
            $job->onConnection($this->connection);
        }
        if ($this->queue !== null) {
            $job->onQueue($this->queue);
        }
        $this->bus->dispatch($job);
    }
}
