<?php

declare(strict_types=1);

namespace Charlotte\Checks;

use Charlotte\Sessions\SessionRecord;

/**
 * Where rendered reports come from. OPTIONAL: the default returns none, and
 * every check then runs on the document, saying so. A host (or the lab) binds
 * its own, or registers one with `Charlotte::renderedUsing()`.
 *
 * Return whatever report you hold for the page; Charlotte compares its
 * revision with the revision being checked and ignores a stale one. A source
 * that throws is treated as "no report" (`rendered_report_failed`).
 */
interface RenderedEvidenceSource
{
    /** @param string $page the PageRepository key of the document being checked */
    public function for(SessionRecord $session, string $page): ?RenderedEvidence;
}
