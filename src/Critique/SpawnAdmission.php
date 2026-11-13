<?php

declare(strict_types=1);

namespace Charlotte\Critique;

use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Subagents\Subagent;
use Prism\Harness\Subagents\SubagentOutcome;

/**
 * DELETE WHEN prism-harness G-265 LANDS (`SubagentRunner::run()` taking media).
 *
 * A copy of `SubagentRunner::run()`'s pre-spawn admission, in its order and
 * with its outcomes, and nothing more: the critic's whole input is
 * screenshots, the runner takes no media, so {@see DesignLoop} spawns the
 * critic itself and must refuse what the runner would. Kept minimal on
 * purpose; every extra check here is a way to disagree with the harness.
 * `tests/Feature/CriticAdmissionTest.php` runs this and the runner through
 * the harness's own refusal cases, so drift fails a test.
 */
final class SpawnAdmission
{
    /**
     * @return array{0: ?string, 1: ?RunContext} the refusal outcome (a {@see SubagentOutcome} value) and no child,
     *                                          or null and the child context to run it on
     */
    public static function check(RunContext $context, Subagent $subagent, string $parentRunId, ?int $parentThreadId): array
    {
        if ($context->ledger->exhaustion($context->budget) !== null) {
            return [($context->ledger->cancelled() ? SubagentOutcome::Cancelled : SubagentOutcome::Exhausted)->value, null];
        }
        $child = $context->forChild($subagent, $parentRunId, $parentThreadId);
        if ($child->tooDeep()) {
            return [SubagentOutcome::Denied->value, null];
        }
        if ($child->budget->maxSteps < 1) {
            return [SubagentOutcome::Exhausted->value, null];
        }

        return [null, $child];
    }
}
