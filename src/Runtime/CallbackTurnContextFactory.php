<?php

declare(strict_types=1);

namespace Charlotte\Runtime;

use Closure;
use Prism\Harness\Subagents\RunContext;

/** `Charlotte::contextUsing(fn (Turn $turn): ?RunContext => ...)`. */
final class CallbackTurnContextFactory implements TurnContextFactory
{
    /** @param Closure(Turn): ?RunContext $callback */
    public function __construct(private readonly Closure $callback) {}

    public function forTurn(Turn $turn): ?RunContext
    {
        return ($this->callback)($turn);
    }
}
