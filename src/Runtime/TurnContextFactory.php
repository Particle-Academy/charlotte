<?php

declare(strict_types=1);

namespace Charlotte\Runtime;

use Prism\Harness\Subagents\RunContext;

/**
 * OPTIONAL: the host's say over each turn's run context.
 *
 * Bind one to give every builder turn the host's own prism-harness
 * {@see RunContext}: its budget, its pricing and its cost observer (durable
 * spend admission, per-lane accounts). Return null to keep the harness
 * defaults for that turn. The default binding returns null for every turn, so
 * a host that binds nothing runs exactly as before; billing is never required.
 *
 * Called once per turn inside the turn's failure handling: throw to refuse the
 * turn before any provider call. The turn fails with your exception's
 * `HasErrorCode::code()` (else `run_failed`), never its message.
 */
interface TurnContextFactory
{
    public function forTurn(Turn $turn): ?RunContext;
}
