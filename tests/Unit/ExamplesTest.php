<?php

declare(strict_types=1);

use Charlotte\Design\Examples;
use Charlotte\Design\Starter;
use Charlotte\Guide\BuilderMode;
use Charlotte\Guide\PublishedGuide;
use FancyCms\Agent;

/*
 * The rotating worked examples. Every session saw the same two in the static
 * guide, and paid lab runs copied their motifs (stamp badges, tile panels,
 * ids like hero-media-panel) onto unrelated briefs. Now a pool, two per
 * session, sampled from the session id, delivered outside the cached prompt.
 */

/** A document's nodes from a list of insert ops, as state()['doc'] has them. */
function docFromOps(array $ops): array
{
    $nodes = [];
    foreach ($ops as $op) {
        if (($op['t'] ?? null) === 'insert_node') {
            $nodes[$op['node']['id']] = $op['node'];
        }
    }

    return ['nodes' => $nodes];
}

/** The sizes measured on 2026-11-13 at HEAD 2cfd791, before the examples left the static guide. */
const BEFORE_CHARLOTTE_MD_BYTES = 8960;
const BEFORE_PAGE_MODE_BYTES = 23682;
const BEFORE_SITE_MODE_BYTES = 24370;
const BEFORE_PAGE_SUB_MODE_BYTES = 24195;
const FANCY_CMS_GUIDE_BYTES_AT_BASELINE = 7332;

test('the pool holds 4 to 6 varied examples, keeping the warm shop and the B2B product', function (): void {
    $pool = Examples::pool();
    $ids = array_column($pool, 'id');

    expect(count($pool))->toBeGreaterThanOrEqual(4)->toBeLessThanOrEqual(6)
        ->and(array_unique($ids))->toBe($ids)
        ->and($ids)->toContain('warm-local-shop', 'clean-b2b-product')
        ->and(array_unique(array_map(fn ($e) => $e['ops'][0]['patch']['color']['primary'], $pool)))->toHaveCount(count($pool));
    foreach ($pool as $example) {
        expect($example)->toHaveKeys(['id', 'label', 'description', 'ops', 'motifs'])
            ->and($example['id'])->toMatch('/\A[a-z][a-z0-9-]+\z/')
            ->and($example['description'])->not->toContain("\n")
            ->and($example['ops'][0]['t'])->toBe('set_theme')
            ->and(count($example['ops']))->toBeLessThanOrEqual(3)
            ->and($example['motifs'])->not->toBe([]);
    }
});

test('every motif is a node its example inserts, and its signature is that node\'s own shape', function (): void {
    foreach (Examples::pool() as $example) {
        $nodes = docFromOps($example['ops'])['nodes'];
        foreach ($example['motifs'] as $motif) {
            $node = $nodes[$motif['id']] ?? null;
            $shape = Examples::shapeOf($node ?? []);

            expect($node)->not->toBeNull("{$example['id']} never inserts {$motif['id']}")
                ->and($motif['type'])->toBe($node['type'])
                ->and($motif['layout'])->toBe($node['layout'] ?? null)
                ->and($motif['signature'])->not->toBe([])
                ->and(array_diff(array_keys($motif['signature']), Examples::SIGNATURE_KEYS))->toBe([])
                ->and(array_intersect_key($shape['signature'], $motif['signature']))->toEqual($motif['signature'])
                ->and(Examples::reuse(['nodes' => [$motif['id'].'-copy' => ['id' => $motif['id'].'-copy'] + $node]], [$example['id']]))
                ->toBe([['example' => $example['id'], 'motif' => $motif['id'], 'node' => $motif['id'].'-copy', 'by' => 'shape']]);
        }
    }
});

test('motifs() flattens the pool\'s motifs with their example, and every motif id is unique', function (): void {
    $motifs = Examples::motifs();
    $expected = [];
    foreach (Examples::pool() as $example) {
        foreach ($example['motifs'] as $motif) {
            $expected[] = ['example' => $example['id']] + $motif;
        }
    }

    expect($motifs)->toBe($expected)
        ->and(array_unique(array_column($motifs, 'id')))->toHaveCount(count($motifs));
});

test('reuse() finds a motif copied by id or by shape, and the starter skeleton matches none', function (): void {
    $starter = docFromOps([...Starter::skeleton('header'), ...Starter::skeleton('page'), ...Starter::skeleton('footer')]);
    $stamp = docFromOps(Examples::pool()[0]['ops'])['nodes']['hero-stamp'];
    $renamed = ['nodes' => ['brand-seal' => ['id' => 'brand-seal'] + $stamp]];

    expect(Examples::reuse($starter))->toBe([])
        ->and(Examples::reuse(['nodes' => ['hero-stamp' => $stamp]]))->toBe([['example' => 'warm-local-shop', 'motif' => 'hero-stamp', 'node' => 'hero-stamp', 'by' => 'id']])
        ->and(Examples::reuse($renamed))->toBe([['example' => 'warm-local-shop', 'motif' => 'hero-stamp', 'node' => 'brand-seal', 'by' => 'shape']])
        ->and(Examples::reuse($renamed, ['clean-b2b-product']))->toBe([]);
});

test('no example\'s nodes match another example\'s motifs: each motif is its own', function (): void {
    foreach (Examples::pool() as $example) {
        $found = array_values(array_unique(array_column(Examples::reuse(docFromOps($example['ops'])), 'example')));
        expect($found)->toBe([$example['id']]);
    }
});

test('a session\'s two examples are sampled from its id: reproducible, distinct, and rotating across sessions', function (): void {
    $ids = array_column(Examples::pool(), 'id');
    $pairs = [];
    $seen = [];
    for ($i = 0; $i < 200; $i++) {
        $session = sprintf('01JC%022d', $i);
        $sample = Examples::sample($session);
        expect($sample)->toHaveCount(Examples::PER_SESSION)
            ->and(array_unique($sample))->toBe($sample)
            ->and(array_diff($sample, $ids))->toBe([])
            ->and(Examples::sample($session))->toBe($sample);
        $pairs[implode('+', $sample)] = true;
        $seen = [...$seen, ...$sample];
    }

    expect(count($pairs))->toBeGreaterThanOrEqual(8)
        ->and(array_values(array_unique($seen)))->toEqualCanonicalizing($ids);
});

test('the per-session section carries the sampled examples\' ops and the no-reuse rule, and skips unknown ids', function (): void {
    $section = Examples::promptSection(['clean-b2b-product', 'retired-example', 'warm-local-shop']);

    expect($section)->toContain('**Clean B2B product:**')
        ->and($section)->toContain('**Warm local shop:**')
        ->and(strpos($section, 'Clean B2B product'))->toBeLessThan(strpos($section, 'Warm local shop'))
        ->and($section)->toContain('"id":"hero-panel"')
        ->and($section)->toContain('"id":"hero-stamp"')
        ->and($section)->toContain('Never reuse their motifs, shapes or node ids.')
        ->and($section)->toContain('not the person')
        // Empty props read as the object fancy-cms documents, never a JSON list.
        ->and($section)->not->toContain('"props":[]')
        ->and($section)->toContain('"props":{}')
        ->and(Examples::promptSection([]))->toBe('')
        ->and(Examples::delivered([], 'Go.'))->toBe('Go.');
    // What the model reads decodes back to exactly the pool's ops.
    foreach (Examples::pool() as $example) {
        expect(guideDesignExamples()[$example['label']])->toBe($example['ops']);
    }
});

test('the examples have left the static guide, and the guide states the no-reuse rule', function (): void {
    $guide = (new PublishedGuide)->current()->guide;

    foreach (Examples::motifs() as $motif) {
        expect($guide)->not->toContain('"id":"'.$motif['id'].'"');
    }
    expect(PublishedGuide::charlotteGuide())->toContain('Never reuse a distinctive motif, badge or shape from an example.');
});

test('guide plus the largest per-session examples stays at or below the prompt size before the change', function (): void {
    $guide = (new PublishedGuide)->current();
    $examples = Examples::maxPromptBytes();
    // fancy-cms's own guide is outside this change; a fancy-cms upgrade that
    // grows it moves the baseline with it rather than failing here.
    $cmsDrift = strlen(Agent::guide()) - FANCY_CMS_GUIDE_BYTES_AT_BASELINE;

    expect(strlen(PublishedGuide::charlotteGuide()) + $examples)->toBeLessThanOrEqual(BEFORE_CHARLOTTE_MD_BYTES)
        ->and(strlen(BuilderMode::config($guide)['system_prompt']) + $examples - $cmsDrift)->toBeLessThanOrEqual(BEFORE_PAGE_MODE_BYTES)
        ->and(strlen(BuilderMode::config($guide, site: true)['system_prompt']) + $examples - $cmsDrift)->toBeLessThanOrEqual(BEFORE_SITE_MODE_BYTES)
        ->and(strlen(BuilderMode::pageConfig($guide)['system_prompt']) + $examples - $cmsDrift)->toBeLessThanOrEqual(BEFORE_PAGE_SUB_MODE_BYTES);
});

test('maxPromptBytes is the largest pair as delivered', function (): void {
    $ids = array_column(Examples::pool(), 'id');
    $max = 0;
    foreach ($ids as $a) {
        foreach ($ids as $b) {
            if ($a !== $b) {
                $max = max($max, strlen(Examples::delivered([$a, $b], 'x')) - 1);
            }
        }
    }

    expect(Examples::maxPromptBytes())->toBe($max);
});
