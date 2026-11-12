<?php

declare(strict_types=1);

namespace Charlotte\Checks;

use Charlotte\Brief\Brief;
use Charlotte\Policy\LinkPolicy;

/**
 * Every code-derived check on one revision: PageChecks (structure,
 * accessibility, links, meta), FactChecks (content truth against the brief)
 * and the host's link allowlist. Free and deterministic, so the agent can run
 * it as often as it likes before finishing.
 *
 * Contrast has two paths, and the result's `contrast` field says which ran:
 * - `rendered`: a {@see RenderedEvidence} for this revision (or one of
 *   `$alsoAccept`) was supplied, so contrast is what the browser measured on
 *   the rendered page, islands included. The document's own contrast
 *   verdicts are dropped: the render is the better instrument.
 * - `document`: no usable report (`reason` says why), so contrast is judged
 *   from the document's styles, which cannot see inside islands.
 */
final class Checks
{
    /** Codes that make no sense on a shared region (it is not a page). */
    public const REGION_SKIP = ['h1_count', 'meta_title', 'meta_description', 'anchor_target', 'missing_required_fact'];

    /**
     * @param  list<string>  $alsoAccept  other revisions a report may name (a site revision)
     * @param  list<string>  $skip  defect codes the caller judges elsewhere
     * @param  string|null  $unavailable  why no report could be obtained, when the caller tried
     * @return array{revision: string, summary: array{errors: int, warnings: int, info: int}, defects: list<array>, facts: array, contrast: array}
     */
    public static function run(array $doc, string $revision, Brief $brief, LinkPolicy $links, ?RenderedEvidence $rendered = null,
        array $alsoAccept = [], array $skip = [], ?string $unavailable = null): array
    {
        $facts = PageChecks::facts($doc);
        $defects = [...PageChecks::check($doc), ...FactChecks::check($doc, $brief)];
        foreach ($facts['links'] as $link) {
            if ($link['external'] && $link['safe'] && ! $links->allows($link['href'])) {
                $defects[] = ['code' => 'link_not_allowlisted', 'severity' => 'warning', 'node' => $link['node'],
                    'message' => "Links to {$link['origin']}, which the host has not allowlisted."];
            }
        }

        if ($rendered !== null && in_array($rendered->revision, [$revision, ...$alsoAccept], true)) {
            $contrast = ['source' => 'rendered', 'renderedBy' => $rendered->source, 'revision' => $rendered->revision];
            $defects = array_values(array_filter($defects, fn (array $d) => ! in_array($d['code'], ['contrast', 'contrast_unchecked'], true)));
            foreach ($rendered->contrast as $item) {
                $defects[] = ['code' => 'contrast', 'severity' => 'error', 'source' => 'rendered'] + $item + [
                    'message' => isset($item['ratio'], $item['needed'])
                        ? sprintf('Rendered text contrast is %.2f:1; it needs %.1f:1%s.', $item['ratio'], $item['needed'], isset($item['island']) ? " (inside {$item['island']})" : '')
                        : 'Rendered text is below the contrast minimum'.(isset($item['island']) ? " (inside {$item['island']})" : '').'.',
                ];
            }
        } elseif ($rendered !== null) {
            $contrast = ['source' => 'document', 'reason' => 'rendered_report_stale', 'renderedRevision' => $rendered->revision];
        } else {
            $contrast = ['source' => 'document', 'reason' => $unavailable ?? 'no_rendered_report'];
        }

        if ($skip !== []) {
            $defects = array_values(array_filter($defects, fn (array $d) => ! in_array($d['code'], $skip, true)));
        }

        return ['revision' => $revision, 'summary' => self::summary($defects), 'defects' => $defects, 'facts' => $facts, 'contrast' => $contrast];
    }

    /** @return array{errors: int, warnings: int, info: int} */
    public static function summary(array $defects): array
    {
        $summary = ['errors' => 0, 'warnings' => 0, 'info' => 0];
        foreach ($defects as $defect) {
            $key = match ($defect['severity']) {
                'error' => 'errors', 'warning' => 'warnings', default => 'info',
            };
            $summary[$key]++;
        }

        return $summary;
    }
}
