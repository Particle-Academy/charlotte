<?php

declare(strict_types=1);

use Charlotte\Brief\Brief;
use Charlotte\Checks\Checks;
use Charlotte\Checks\RenderedEvidence;
use Charlotte\Facades\Charlotte;
use Charlotte\Policy\LinkPolicy;
use Charlotte\Sessions\SessionRecord;

/*
 * S12: the document check cannot see inside an island. When the host or the
 * lab supplies a rendered report for the revision being checked, contrast is
 * judged on the RENDERED page, islands included; otherwise on the document,
 * and the result always says which one ran.
 */

/** The lab's shape: inspect.mjs `checks.contrast.violations` (axe color-contrast). */
function axeContrast(): array
{
    return [[
        'id' => 'color-contrast', 'impact' => 'serious', 'help' => 'Elements must meet minimum color contrast ratio thresholds',
        'count' => 1,
        'nodes' => [[
            'target' => '[data-cms="tabs"] > div > button:nth-child(2)',
            'summary' => 'Fix any of the following: Element has insufficient color contrast of 4.39 (foreground color: #71717b, background color: #f4f4f5, font size: 10.5pt (14px), font weight: normal). Expected contrast ratio of 4.5:1',
            'island' => 'kit:Tabs',
        ]],
    ]];
}

/** A page whose document contrast passes; its only island is a tab list. */
function islandPage(): array
{
    return [
        'id' => 'p', 'seq' => 3,
        'meta' => ['title' => 'Pricing for Acme Ledger', 'slug' => '/pricing', 'seo' => ['description' => 'Plans and prices for Acme Ledger.']],
        'theme' => [], 'breakpoints' => ['base', 'md', 'lg'],
        'nodes' => [
            's' => ['id' => 's', 'type' => 'section', 'parent' => null, 'order' => 'a0', 'props' => [], 'style' => ['base' => []]],
            'h' => ['id' => 'h', 'type' => 'heading', 'parent' => 's', 'order' => 'a0', 'props' => ['content' => 'Pricing', 'level' => 1], 'style' => ['base' => []]],
            'tabs' => ['id' => 'tabs', 'type' => 'kit:Tabs', 'island' => true, 'parent' => 's', 'order' => 'a1', 'props' => ['tabs' => [['value' => 'm', 'label' => 'Monthly']]], 'style' => ['base' => []]],
        ],
    ];
}

function contrastCodes(array $result): array
{
    return array_values(array_filter($result['defects'], fn ($d) => in_array($d['code'], ['contrast', 'contrast_unchecked'], true)));
}

test('without a rendered report the document check runs, and says so', function (): void {
    $result = Checks::run(islandPage(), 'r1', Brief::fromArray(briefArray()), new LinkPolicy);

    expect($result['contrast'])->toBe(['source' => 'document', 'reason' => 'no_rendered_report'])
        ->and(contrastCodes($result))->toBe([]);
});

test('a rendered report for this revision judges contrast on the rendered page, island included', function (): void {
    $rendered = RenderedEvidence::fromAxe(axeContrast(), 'r1', 'axe-core 4.14.0');
    $result = Checks::run(islandPage(), 'r1', Brief::fromArray(briefArray()), new LinkPolicy, $rendered);
    $contrast = contrastCodes($result);

    expect($result['contrast'])->toBe(['source' => 'rendered', 'renderedBy' => 'axe-core 4.14.0', 'revision' => 'r1'])
        ->and($contrast)->toHaveCount(1)
        ->and($contrast[0])->toMatchArray(['code' => 'contrast', 'severity' => 'error', 'source' => 'rendered',
            'node' => 'tabs', 'island' => 'kit:Tabs', 'ratio' => 4.39, 'needed' => 4.5]);
});

test('a rendered report for another revision is stale: the document check runs instead', function (): void {
    $rendered = RenderedEvidence::fromAxe(axeContrast(), 'r0', 'axe-core 4.14.0');
    $result = Checks::run(islandPage(), 'r1', Brief::fromArray(briefArray()), new LinkPolicy, $rendered);

    expect($result['contrast'])->toBe(['source' => 'document', 'reason' => 'rendered_report_stale', 'renderedRevision' => 'r0'])
        ->and(contrastCodes($result))->toBe([]);
});

test('in rendered mode the document contrast verdicts are replaced, not added to', function (): void {
    $doc = islandPage();
    $doc['nodes']['h']['style']['base']['color'] = '#cccccc'; // fails on the document
    $clean = new RenderedEvidence('r1', 'axe-core 4.14.0', []);

    $document = Checks::run($doc, 'r1', Brief::fromArray(briefArray()), new LinkPolicy);
    $rendered = Checks::run($doc, 'r1', Brief::fromArray(briefArray()), new LinkPolicy, $clean);

    expect(array_column(contrastCodes($document), 'code'))->toBe(['contrast'])
        ->and(contrastCodes($rendered))->toBe([]);
});

test('a report may also be accepted at another revision the caller names (a site revision)', function (): void {
    $rendered = RenderedEvidence::fromAxe(axeContrast(), 'site-rev', 'axe');
    $result = Checks::run(islandPage(), 'r1', Brief::fromArray(briefArray()), new LinkPolicy, $rendered, ['site-rev']);

    expect($result['contrast']['source'])->toBe('rendered');
});

test('fromAxe reads node and island from the target selector when the lab did not add them', function (): void {
    $violations = axeContrast();
    unset($violations[0]['nodes'][0]['island']);
    $violations[0]['nodes'][0]['target'] = '[data-cms-island="kit:Tabs"][data-cms="tabs"] button';

    $item = RenderedEvidence::fromAxe($violations, 'r1')->contrast[0];

    expect($item)->toMatchArray(['node' => 'tabs', 'island' => 'kit:Tabs', 'ratio' => 4.39, 'needed' => 4.5]);
});

test('fromAxe ignores rules other than color-contrast and bounds what it keeps', function (): void {
    $violations = [...axeContrast(), ['id' => 'image-alt', 'nodes' => [['target' => 'img']], 'count' => 1]];
    $violations[0]['nodes'] = array_fill(0, 500, $violations[0]['nodes'][0]);

    $evidence = RenderedEvidence::fromAxe($violations, 'r1');

    expect($evidence->contrast)->toHaveCount(RenderedEvidence::MAX_ITEMS);
});

test('hostile report text is truncated and never becomes a node id that is not one', function (): void {
    $violations = axeContrast();
    $violations[0]['nodes'][0]['node'] = '"><script>';
    $violations[0]['nodes'][0]['summary'] = str_repeat('x', 10000);

    $item = RenderedEvidence::fromAxe($violations, 'r1')->contrast[0];

    expect($item)->not->toHaveKey('node')
        ->and(mb_strlen($item['summary']))->toBeLessThanOrEqual(300);
});

test('cms_check asks the host for a rendered report and uses it at the current revision', function (): void {
    charlotteApp(['charlotte.autonomy' => 'full']);
    $session = openSession();
    $rev = tool($session, 'cms_apply', ['ops' => sectionOps(), 'basedOn' => $session->state()['revision']])['revision'];
    $asked = [];
    Charlotte::renderedUsing(function (SessionRecord $record, string $page) use (&$asked, $rev): ?RenderedEvidence {
        $asked[] = [$record->tenant, $page];

        return RenderedEvidence::fromAxe(axeContrast(), $rev, 'axe-core 4.14.0');
    });

    $result = tool($session, 'cms_check', ['basedOn' => $rev]);

    expect($asked)->toBe([['acme', 'home']])
        ->and($result['contrast']['source'])->toBe('rendered')
        ->and(array_column($result['defects'], 'island'))->toContain('kit:Tabs');
});

test('a host report source that throws does not fail the check: the document check runs', function (): void {
    charlotteApp(['charlotte.autonomy' => 'full']);
    $session = openSession();
    Charlotte::renderedUsing(fn () => throw new RuntimeException('browser down, token=secret'));

    $result = tool($session, 'cms_check', ['basedOn' => $session->state()['revision']]);

    expect($result['contrast'])->toBe(['source' => 'document', 'reason' => 'rendered_report_failed'])
        ->and(json_encode($result))->not->toContain('secret');
});
