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
 * finished at. Return, for every width requested, ONE VIEWPORT per
 * {@see Screenshot} at native resolution (device pixel ratio 1 to 3, never
 * scaled down): a page taller than the viewport is several sections at that
 * width, `part` 1..n from the top, each at most
 * `charlotte.critique.max_capture_height` CSS px tall. Never one tall strip.
 * Anything else (a throw, a missing width, a gap in the sections, a capture
 * too tall) is `render_failed` with a `renderProblem` code: the critique is
 * skipped and the turn is unaffected.
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
