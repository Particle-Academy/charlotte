<?php

declare(strict_types=1);

namespace Charlotte\Checks;

use Charlotte\Brief\Brief;
use Charlotte\Data\Bindings;
use Charlotte\Data\DataCatalogue;
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
    public const REGION_SKIP = ['h1_count', 'meta_title', 'meta_description', 'anchor_target', 'missing_required_fact',
        'responsive_layout', 'required_image_missing', 'required_image_unavailable'];

    /** What a site judges across all its documents rather than per page. */
    public const SITE_WIDE = ['missing_required_fact', 'required_image_missing', 'required_image_unavailable'];

    /**
     * The codes that, at `error` severity, make cms_finish refuse: what the
     * lab's reviewer scored a page down or to 0 for (runs 423-463). Every
     * other error is still reported and still lets the draft be marked ready.
     */
    public const FINISH_GATES = ['layout_missing', 'link_spacing', 'responsive_layout', 'required_image_missing', 'meta_description', 'unsupported_claim'];

    /** The rule each gate holds a page to, as every gate defect states it. */
    public const RULES = [
        'layout_missing' => 'Every stack and grid has a layout ("stack", "grid" or "free"); fancy-cms renders a container without one as a plain block.',
        'link_spacing' => 'Two or more links or buttons side by side sit in a stack or grid with a gap above 0 at base.',
        'responsive_layout' => 'Every content section has md or lg styles, and the page shows two or more columns somewhere at md and at lg.',
        'required_image_missing' => 'Every image the brief requires is placed as an image node (props.asset).',
        'meta_description' => 'meta.seo.description is set, at most 155 characters.',
        'unsupported_claim' => 'Every price, count, percentage, phone, email, date and year on the page appears in the brief.',
    ];

    /**
     * Make every gate defect concrete and locatable: the `rule` it breaks and
     * a `node`. A defect with no node of its own is the page's: fancy-cms's
     * page handle `page:<doc id>` (with `field` for meta).
     */
    public static function locate(array $defects, string $docId): array
    {
        return array_map(function (array $d) use ($docId): array {
            if (! isset(self::RULES[$d['code']])) {
                return $d;
            }
            $d['rule'] = self::RULES[$d['code']];
            if ($d['code'] === 'meta_description') {
                $d['field'] ??= 'meta.seo.description';
            }
            if (! is_string($d['node'] ?? null) || $d['node'] === '') {
                $d['node'] = 'page:'.$docId;
            }

            return $d;
        }, $defects);
    }

    /** The defects that hold cms_finish back. */
    public static function gates(array $defects): array
    {
        return array_values(array_filter($defects, fn (array $d) => $d['severity'] === 'error' && in_array($d['code'], self::FINISH_GATES, true)));
    }

    /**
     * @param  list<string>  $alsoAccept  other revisions a report may name (a site revision)
     * @param  list<string>  $skip  defect codes the caller judges elsewhere
     * @param  string|null  $unavailable  why no report could be obtained, when the caller tried
     * @param  list<array{handle: string}>  $assets  the host AssetCatalogue's catalogue(), for the required-image check
     * @return array{revision: string, summary: array{errors: int, warnings: int, info: int}, defects: list<array>, facts: array, contrast: array}
     */
    public static function run(array $doc, string $revision, Brief $brief, LinkPolicy $links, ?RenderedEvidence $rendered = null,
        array $alsoAccept = [], array $skip = [], ?string $unavailable = null, ?DataCatalogue $data = null, array $assets = []): array
    {
        $facts = PageChecks::facts($doc);
        $defects = [...PageChecks::check($doc), ...FactChecks::check($doc, $brief), ...QualityChecks::check($doc, $brief, $assets)];
        if ($data !== null) {
            foreach (Bindings::validate($doc, $data) as $error) {
                $defects[] = ['code' => 'invalid_binding', 'severity' => 'error', 'path' => $error['path'], 'expected' => $error['expected'], 'got' => $error['got'],
                    'message' => 'A data binding does not match the host data catalogue; rebind it to a stream and field from cms_describe data.'];
            }
        }
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
        $defects = self::locate($defects, is_string($doc['id'] ?? null) ? $doc['id'] : 'page');

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
