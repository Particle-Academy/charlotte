<?php

declare(strict_types=1);

namespace Charlotte\Runtime;

use Charlotte\Sessions\SessionRecord;
use Prism\Harness\Sessions\Session;

/**
 * One turn, as a host's {@see TurnContextFactory} sees it, just before the run
 * starts: the stored Charlotte session (tenant, page, provider, model, all
 * host-owned), the harness session the run will use (already in the builder
 * mode, on the session's provider and model), and the person's message.
 */
final readonly class Turn
{
    public function __construct(
        public SessionRecord $session,
        public Session $harness,
        public string $text,
    ) {}
}
