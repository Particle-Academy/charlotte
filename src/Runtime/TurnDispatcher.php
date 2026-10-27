<?php

declare(strict_types=1);

namespace Charlotte\Runtime;

/** Hands a queued turn to whatever runs it. The Laravel default is a queued job. */
interface TurnDispatcher
{
    public function dispatch(string $sessionId, string $text): void;
}
