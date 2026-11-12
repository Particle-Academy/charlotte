<?php

declare(strict_types=1);

namespace Charlotte\Checks;

use Charlotte\Sessions\SessionRecord;

/** The default: no rendered reports, so contrast is judged on the document. */
final class NoRenderedEvidence implements RenderedEvidenceSource
{
    public function for(SessionRecord $session, string $page): ?RenderedEvidence
    {
        return null;
    }
}
