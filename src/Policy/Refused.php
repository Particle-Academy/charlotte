<?php

declare(strict_types=1);

namespace Charlotte\Policy;

use RuntimeException;

/**
 * An expected refusal with a stable code. Tool results and events carry the
 * code and this fixed message, never an exception message from below.
 */
final class Refused extends RuntimeException
{
    public const MESSAGES = [
        'image_source_refused' => 'Use a host-supplied image asset handle; authored image URLs, dimensions and CSS fetches are refused.',
        'link_refused' => 'Links may target this site, an in-page anchor, mailto: or tel:, or an external origin the host allowlisted.',
        'stopped' => 'The session was stopped by a person. Do not continue; end your turn.',
        'proposal_missing' => 'No such pending change set.',
        'proposal_resolved' => 'That change set was already resolved.',
        'page_missing' => 'This session has no page.',
        'session_missing' => 'No such session.',
        'turn_in_progress' => 'A turn is already running for this session.',
        'invalid_request' => 'The request was malformed.',
        'guide_mode_stale' => 'The builder mode does not embed the current guide exactly once.',
    ];

    public function __construct(public readonly string $reason, public readonly ?string $revision = null)
    {
        parent::__construct(self::MESSAGES[$reason] ?? 'The request was refused.');
    }
}
