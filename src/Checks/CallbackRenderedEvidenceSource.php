<?php

declare(strict_types=1);

namespace Charlotte\Checks;

use Charlotte\Sessions\SessionRecord;
use Closure;

final class CallbackRenderedEvidenceSource implements RenderedEvidenceSource
{
    /** @param Closure(SessionRecord, string): ?RenderedEvidence $callback */
    public function __construct(private readonly Closure $callback) {}

    public function for(SessionRecord $session, string $page): ?RenderedEvidence
    {
        $evidence = ($this->callback)($session, $page);

        return $evidence instanceof RenderedEvidence ? $evidence : null;
    }
}
