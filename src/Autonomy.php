<?php

declare(strict_types=1);

namespace Charlotte;

/**
 * How the agent's edits land. A dev setting (config `charlotte.autonomy`),
 * overridable per session.
 *
 * - Full: each `cms_apply` changes the draft at once.
 * - ApproveOnSave: edits collect in a pending change set; a person approves
 *   it (saved to the draft) or rejects it (discarded).
 *
 * Neither publishes. Publishing is a separate host action in both.
 */
enum Autonomy: string
{
    case Full = 'full';
    case ApproveOnSave = 'approve_on_save';
}
