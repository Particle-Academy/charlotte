<?php

declare(strict_types=1);

namespace Charlotte\Critique;

use Charlotte\Sessions\SessionRecord;

/**
 * How a host renders a page draft for the design critic. OPTIONAL: with none
 * bound, the render, critique, revise loop is off and nothing about a turn
 * changes. Register one with `Charlotte::screenshotsUsing()`, or bind this
 * contract.
 *
 * Asked once a page's cms_finish has passed its gates, for the revision it
 * finished at. Return exactly one {@see Screenshot} per width requested.
 * Anything else (a throw, a missing width, a stale render) is
 * `render_failed`: the critique is skipped and the turn is unaffected.
 */
interface PageRenderer
{
    /**
     * @param  string  $page  the PageRepository key of the document
     * @param  string  $revision  the revision to render: the ready revision
     * @param  list<int>  $widths  CSS widths, e.g. [375, 768, 1280]
     * @return list<Screenshot>
     */
    public function render(SessionRecord $session, string $page, string $revision, array $widths): array;
}
