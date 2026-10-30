<?php

declare(strict_types=1);

namespace Charlotte\Runtime;

use Prism\Harness\Subagents\RunContext;

/** The default {@see TurnContextFactory}: no host context, so the harness's own config governs the run. */
final class HarnessDefaults implements TurnContextFactory
{
    public function forTurn(Turn $turn): ?RunContext
    {
        return null;
    }
}
