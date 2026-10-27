<?php

declare(strict_types=1);

namespace Charlotte\Events;

/**
 * Every event type Charlotte 0.x emits, envelope v1.
 *
 * Clients MUST ignore a type they do not know: that is the forward
 * compatibility rule. New types and new data fields arrive in minor releases;
 * removing one, or changing what one means, bumps the envelope version.
 */
final class EventTypes
{
    public const SESSION_STATUS = 'session.status';

    public const RUN_STARTED = 'run.started';

    public const RUN_FINISHED = 'run.finished';

    public const RUN_FAILED = 'run.failed';

    public const MESSAGE_CREATED = 'message.created';

    public const MESSAGE_DELTA = 'message.delta';

    public const MESSAGE_COMPLETED = 'message.completed';

    public const TOOL_CALL = 'tool.call';

    public const TOOL_RESULT = 'tool.result';

    public const DOC_CHANGED = 'doc.changed';

    public const PROPOSAL_CREATED = 'proposal.created';

    public const PROPOSAL_UPDATED = 'proposal.updated';

    public const PROPOSAL_RESOLVED = 'proposal.resolved';

    public const CHECKS_RESULT = 'checks.result';

    public const DRAFT_READY = 'draft.ready';

    public const DRAFT_PUBLISHED = 'draft.published';

    public const USAGE_OBSERVED = 'usage.observed';

    public const ERROR = 'error';

    public const ALL = [
        self::SESSION_STATUS, self::RUN_STARTED, self::RUN_FINISHED, self::RUN_FAILED,
        self::MESSAGE_CREATED, self::MESSAGE_DELTA, self::MESSAGE_COMPLETED,
        self::TOOL_CALL, self::TOOL_RESULT, self::DOC_CHANGED,
        self::PROPOSAL_CREATED, self::PROPOSAL_UPDATED, self::PROPOSAL_RESOLVED,
        self::CHECKS_RESULT, self::DRAFT_READY, self::DRAFT_PUBLISHED,
        self::USAGE_OBSERVED, self::ERROR,
    ];

    public const ACTOR_KINDS = ['agent', 'human', 'system'];

    /** The type grammar: lowercase words joined by dots. Unknown but well-formed types are valid. */
    public const TYPE_PATTERN = '/\A[a-z][a-z_]*(?:\.[a-z][a-z_]*)*\z/';
}
