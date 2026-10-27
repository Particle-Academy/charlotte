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
 */
final class Checks
{
    /** @return array{revision: string, summary: array{errors: int, warnings: int, info: int}, defects: list<array>, facts: array} */
    public static function run(array $doc, string $revision, Brief $brief, LinkPolicy $links): array
    {
        $facts = PageChecks::facts($doc);
        $defects = [...PageChecks::check($doc), ...FactChecks::check($doc, $brief)];
        foreach ($facts['links'] as $link) {
            if ($link['external'] && $link['safe'] && ! $links->allows($link['href'])) {
                $defects[] = ['code' => 'link_not_allowlisted', 'severity' => 'warning', 'node' => $link['node'],
                    'message' => "Links to {$link['origin']}, which the host has not allowlisted."];
            }
        }
        $summary = ['errors' => 0, 'warnings' => 0, 'info' => 0];
        foreach ($defects as $defect) {
            $key = match ($defect['severity']) {
                'error' => 'errors', 'warning' => 'warnings', default => 'info',
            };
            $summary[$key]++;
        }

        return ['revision' => $revision, 'summary' => $summary, 'defects' => $defects, 'facts' => $facts];
    }
}
