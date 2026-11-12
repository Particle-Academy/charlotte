<?php

declare(strict_types=1);

namespace Charlotte\Checks;

use InvalidArgumentException;

/**
 * What a real browser measured on a rendered page, at one revision. Charlotte
 * does not render anything itself and adds no browser dependency: the host
 * or the lab supplies this from the capture it already makes (finding S12).
 *
 * Today it carries contrast only, as `{target, node?, island?, ratio?,
 * needed?, summary?}` items: every text element the browser found below the
 * WCAG minimum, islands included, which the document check cannot see.
 *
 * `revision` is what the capture rendered: the page's revision, or (for a
 * site session) the site revision. A report for any other revision is stale
 * and is never used.
 */
final readonly class RenderedEvidence
{
    public const MAX_ITEMS = 100;

    private const ID = '/\A[A-Za-z0-9_:.-]{1,128}\z/';

    private const ISLAND = '/\A[a-z][a-z0-9-]{0,31}:[A-Za-z][A-Za-z0-9-]{0,63}\z/';

    /** @var list<array{target: string, node?: string, island?: string, ratio?: float, needed?: float, summary?: string}> */
    public array $contrast;

    /** @param list<array<string, mixed>> $contrast */
    public function __construct(public string $revision, public string $source, array $contrast)
    {
        if ($revision === '' || strlen($revision) > 512) {
            throw new InvalidArgumentException('A rendered report names the revision it rendered.');
        }
        $items = [];
        foreach (array_slice($contrast, 0, self::MAX_ITEMS) as $item) {
            $items[] = self::item(is_array($item) ? $item : []);
        }
        $this->contrast = $items;
    }

    /**
     * From axe-core results as the lab's inspector already records them
     * (`lanes/node/kit-site/inspect.mjs`, `checks.contrast.violations`):
     * `[{id: 'color-contrast', nodes: [{target, summary, node?, island?}]}]`.
     * Other rules are ignored. `node` and `island` are read from the item when
     * the inspector supplies them, else from `data-cms` / `data-cms-island`
     * in the target selector when it holds them.
     */
    public static function fromAxe(array $violations, string $revision, string $source = 'axe-core'): self
    {
        $items = [];
        foreach ($violations as $violation) {
            if (! is_array($violation) || ($violation['id'] ?? 'color-contrast') !== 'color-contrast') {
                continue;
            }
            foreach ((array) ($violation['nodes'] ?? []) as $node) {
                if (is_array($node)) {
                    $items[] = $node;
                }
            }
        }

        return new self($revision, mb_substr($source, 0, 64), $items);
    }

    private static function item(array $raw): array
    {
        $target = mb_substr(is_string($raw['target'] ?? null) ? $raw['target'] : (is_array($raw['target'] ?? null) ? implode(' ', array_filter($raw['target'], 'is_string')) : ''), 0, 300);
        $summary = is_string($raw['summary'] ?? null) ? $raw['summary'] : (is_string($raw['failureSummary'] ?? null) ? $raw['failureSummary'] : '');
        $item = ['target' => $target];

        $node = $raw['node'] ?? (preg_match('/\[data-cms="([^"]{1,128})"\]/', $target, $m) === 1 ? $m[1] : null);
        if (is_string($node) && preg_match(self::ID, $node) === 1) {
            $item['node'] = $node;
        }
        $island = $raw['island'] ?? (preg_match('/\[data-(?:cms|kit)-island="([^"]{1,96})"\]/', $target, $m) === 1 ? $m[1] : null);
        if (is_string($island) && preg_match(self::ISLAND, $island) === 1) {
            $item['island'] = $island;
        }
        $ratio = $raw['ratio'] ?? (preg_match('/contrast of ([0-9]+(?:\.[0-9]+)?)/', $summary, $m) === 1 ? (float) $m[1] : null);
        if (is_int($ratio) || is_float($ratio)) {
            $item['ratio'] = round((float) $ratio, 2);
        }
        $needed = $raw['needed'] ?? (preg_match('/ratio of ([0-9]+(?:\.[0-9]+)?):1/', $summary, $m) === 1 ? (float) $m[1] : null);
        if (is_int($needed) || is_float($needed)) {
            $item['needed'] = round((float) $needed, 2);
        }
        if ($summary !== '') {
            $item['summary'] = mb_substr($summary, 0, 300);
        }

        return $item;
    }
}
