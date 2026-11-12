<?php

declare(strict_types=1);

use Charlotte\Brief\Brief;
use Charlotte\Checks\FactChecks;

/** A page whose copy is the given strings, one text node each, in order. */
function pageWithCopy(array $copy, array $meta = []): array
{
    $nodes = ['root' => ['id' => 'root', 'type' => 'section', 'parent' => null, 'order' => 'a0', 'props' => [], 'style' => ['base' => []]]];
    foreach (array_values($copy) as $i => $text) {
        $id = 't'.$i;
        $nodes[$id] = ['id' => $id, 'type' => 'text', 'parent' => 'root', 'order' => 'a'.$i, 'props' => ['content' => $text], 'style' => ['base' => []]];
    }

    return ['id' => 'p', 'seq' => 0, 'meta' => ['title' => 'T', 'slug' => '/', 'scrollMode' => 'smooth', ...$meta], 'theme' => [], 'breakpoints' => ['base'], 'nodes' => $nodes];
}

function factBrief(): Brief
{
    return Brief::fromArray([
        'summary' => 'Landing page for Acme Ledger, bookkeeping for food trucks.',
        'facts' => [
            ['id' => 'price-starter', 'kind' => 'price', 'label' => 'Starter plan', 'value' => '$19/month'],
            ['id' => 'contact', 'kind' => 'contact', 'value' => 'hello@example.com'],
            ['id' => 'quote-1', 'kind' => 'quote', 'value' => '"Saved me Sunday nights."', 'attribution' => 'R. Diaz', 'required' => false],
        ],
    ]);
}

function defectsBy(array $defects, string $code): array
{
    return array_values(array_filter($defects, fn ($d) => $d['code'] === $code));
}

test('copy inside an island\'s props counts: a required fact there is present, an invented price there is caught', function (): void {
    $page = pageWithCopy(['Acme Ledger keeps your books.', 'Write to hello@example.com']);
    $page['nodes']['tabs'] = ['id' => 'tabs', 'type' => 'kit:Tabs', 'island' => true, 'parent' => 'root', 'order' => 'b0', 'style' => ['base' => []],
        'props' => ['tabs' => [
            ['value' => 'starter', 'label' => 'Starter', 'body' => ['Starter plan: $19/month']],
            ['value' => 'pro', 'label' => 'Pro', 'body' => ['Pro plan: $49/month'], 'href' => '/pricing'],
        ]]];

    $defects = FactChecks::check($page, factBrief());

    expect(defectsBy($defects, 'missing_required_fact'))->toBe([])
        ->and(array_column(defectsBy($defects, 'unsupported_claim'), 'claim'))->toBe(['$49'])
        ->and(defectsBy($defects, 'unsupported_claim')[0]['node'])->toBe('tabs');
});

test('a page carrying every required fact, and nothing else, passes', function (): void {
    $page = pageWithCopy(['Acme Ledger keeps your books.', 'Starter plan: $19/month', 'Write to hello@example.com', 'Saved me Sunday nights. R. Diaz']);

    expect(FactChecks::check($page, factBrief()))->toBe([]);
});

test('an invented price is caught as an unsupported claim', function (): void {
    $page = pageWithCopy(['Starter plan: $19/month', 'Pro plan: $29/month', 'hello@example.com']);

    $claims = defectsBy(FactChecks::check($page, factBrief()), 'unsupported_claim');

    expect($claims)->toHaveCount(1)
        ->and($claims[0])->toMatchArray(['severity' => 'error', 'kind' => 'currency', 'claim' => '$29', 'node' => 't1']);
});

test('an unsupported figure is an error that gates cms_finish; an unsupported quote stays a warning', function (): void {
    $page = pageWithCopy(['$19/month hello@example.com', 'Trusted by 500+ trucks', '"Best app ever" said everyone']);

    $claims = defectsBy(FactChecks::check($page, factBrief()), 'unsupported_claim');

    expect(array_column($claims, 'severity', 'kind'))->toEqual(['quote' => 'warning', 'count' => 'error']);
});

/** The conference brief from flabs run 426, as text. */
function conferenceBrief(): Brief
{
    return Brief::fromArray(['summary' => 'Northwind DevDay 2027. Date: Thursday 18 March 2027, 09:00-18:00. '
        .'Tickets: Early bird $149 until 31 January 2027, then $219. Booking from June 2027.']);
}

test('run 426: a date the brief never gave ("From 1 February 2027") is an unsupported date claim', function (): void {
    $page = pageWithCopy(['Early bird $149', 'Until 31 January 2027', 'Standard $219', 'From 1 February 2027']);

    $claims = defectsBy(FactChecks::check($page, conferenceBrief(), 2026), 'unsupported_claim');

    expect($claims)->toHaveCount(1)
        ->and($claims[0])->toMatchArray(['severity' => 'error', 'kind' => 'date', 'claim' => '1 February 2027', 'node' => 't3']);
});

test('a brief date written another way is the same date', function (string $copy): void {
    expect(defectsBy(FactChecks::check(pageWithCopy([$copy]), conferenceBrief(), 2026), 'unsupported_claim'))->toBe([]);
})->with([
    'month first' => ['Thursday, March 18, 2027'],
    'short month' => ['Early bird until 31 Jan 2027'],
    'ordinal' => ['On the 18th of March'],
    'month and year' => ['Booking from June 2027'],
]);

test('an invented month and year is caught, though its year alone is supported', function (): void {
    $claims = defectsBy(FactChecks::check(pageWithCopy(['Booking from July 2027']), conferenceBrief(), 2026), 'unsupported_claim');

    expect(array_column($claims, 'claim'))->toBe(['July 2027'])
        ->and($claims[0]['kind'])->toBe('date');
});

test('the real run 426 page is caught on its invented date and nothing else', function (): void {
    $fixture = json_decode((string) file_get_contents(__DIR__.'/../fixtures/lab-run-426-page.json'), true);

    $claims = defectsBy(FactChecks::check($fixture['doc'], Brief::fromArray($fixture['brief']), 2026), 'unsupported_claim');

    expect(array_column($claims, 'claim'))->toBe(['1 February 2027']);
});

test('a CSS length an agent put in props ({value, unit}) is not a figure', function (): void {
    $page = pageWithCopy(['$19/month hello@example.com']);
    $page['nodes']['grid'] = ['id' => 'grid', 'type' => 'grid', 'parent' => 'root', 'order' => 'c0', 'style' => ['base' => []],
        'props' => ['columnMin' => ['value' => 260, 'unit' => 'px']]];

    expect(defectsBy(FactChecks::check($page, factBrief(), 2026), 'unsupported_claim'))->toBe([]);
});

test('a missing required fact is an error naming the fact', function (): void {
    $page = pageWithCopy(['Starter plan: $19/month']);

    $missing = defectsBy(FactChecks::check($page, factBrief()), 'missing_required_fact');

    expect($missing)->toHaveCount(1)
        ->and($missing[0])->toMatchArray(['severity' => 'error', 'fact' => 'contact']);
});

test('an optional fact may be absent', function (): void {
    $page = pageWithCopy(['$19/month', 'hello@example.com']);

    expect(defectsBy(FactChecks::check($page, factBrief()), 'missing_required_fact'))->toBe([]);
});

test('matching normalizes whitespace, curly quotes, thousands separators and zero cents', function (): void {
    $brief = Brief::fromArray(['summary' => 'x', 'facts' => [
        ['id' => 'p', 'kind' => 'price', 'value' => '$1,200 / year'],
        ['id' => 'q', 'kind' => 'quote', 'value' => '"It just works."'],
    ]]);
    $page = pageWithCopy(['Only $1200.00/year', "\u{201C}It  just works.\u{201D}"]);

    expect(FactChecks::check($page, $brief))->toBe([]);
});

test('high-risk claim shapes that do not trace to the brief are flagged', function (string $copy, string $kind): void {
    $claims = defectsBy(FactChecks::check(pageWithCopy(['$19/month hello@example.com', $copy]), factBrief()), 'unsupported_claim');

    expect(array_column($claims, 'kind'))->toContain($kind);
})->with([
    'percentage' => ['Save 40% on fees', 'percentage'],
    'customer count' => ['Trusted by 500+ trucks', 'count'],
    'phone' => ['Call +1 (555) 010-2030', 'phone'],
    'email' => ['Or sales@example.com', 'email'],
    'year' => ['Serving trucks since 2019', 'year'],
    'quote' => ['"Best app ever" said everyone', 'quote'],
]);

test('the current year is not a claim, so a copyright line stays quiet', function (): void {
    $year = (int) date('Y');
    $page = pageWithCopy(['$19/month hello@example.com', "\u{00A9} {$year} Acme Ledger"]);

    expect(FactChecks::check($page, factBrief(), $year))->toBe([]);
});

test('claims in meta are checked too, because they reach search results', function (): void {
    $page = pageWithCopy(['$19/month hello@example.com'], ['seo' => ['description' => 'Plans from $9/month.']]);

    $claims = defectsBy(FactChecks::check($page, factBrief()), 'unsupported_claim');

    expect(array_column($claims, 'claim'))->toBe(['$9'])
        ->and($claims[0]['node'] ?? null)->toBeNull()
        ->and($claims[0]['field'])->toBe('meta.seo.description');
});

/** A page with one dashboard island holding these props. */
function dashboardPage(array $props, array $copy = ['Pipeline this quarter.']): array
{
    $page = pageWithCopy($copy);
    $page['nodes']['kpi'] = ['id' => 'kpi', 'type' => 'kit:Kpi', 'island' => true, 'parent' => 'root', 'order' => 'b0', 'style' => ['base' => []], 'props' => $props];

    return $page;
}

test('a figure that comes from a binding is evidence, not an unsupported claim, scope dates included', function (): void {
    $page = dashboardPage([
        'label' => 'Revenue',
        'value' => ['$bind' => 'metrics.totals.revenue', '$scope' => ['period' => ['from' => '2025-01-01', 'to' => '2025-12-31']]],
        'rows' => ['$bind' => 'metrics.rows'],
        'format' => 'currency',
        'decimals' => 0,
    ]);

    expect(defectsBy(FactChecks::check($page, factBrief(), 2026), 'unsupported_claim'))->toBe([]);
});

test('a literal figure the agent types into a data prop is still checked', function (): void {
    $page = dashboardPage(['label' => 'Revenue', 'value' => 1250000, 'rows' => [['rep' => 'Ada', 'revenue' => 640000]], 'decimals' => 0, 'limit' => 5]);

    $claims = defectsBy(FactChecks::check($page, factBrief(), 2026), 'unsupported_claim');

    expect(array_column($claims, 'claim'))->toBe(['1250000', '640000'])
        ->and(array_unique(array_column($claims, 'kind')))->toBe(['figure'])
        ->and($claims[0]['node'])->toBe('kpi');
});

test('a literal number beside a bound one is caught, and the bound one is not', function (): void {
    $page = dashboardPage(
        ['label' => 'Revenue', 'value' => ['$bind' => 'metrics.totals.revenue'], 'delta' => 0.42],
        ['Revenue reached $2,400,000 this quarter.'],
    );

    $claims = defectsBy(FactChecks::check($page, factBrief(), 2026), 'unsupported_claim');

    expect(array_column($claims, 'claim'))->toEqualCanonicalizing(['$2400000', '0.42'])
        ->and(array_column($claims, 'node'))->toEqualCanonicalizing(['t0', 'kpi']);
});

test('null is no value: a literal null is never a figure, and the numbers beside it still are', function (): void {
    $page = dashboardPage(['value' => null, 'delta' => null, 'rows' => [['rep' => 'Ada', 'win_rate' => null, 'deals' => 7]]]);

    $claims = defectsBy(FactChecks::check($page, factBrief(), 2026), 'unsupported_claim');

    expect(array_column($claims, 'claim'))->toBe(['7']);
});

test('a literal figure the brief supports passes', function (): void {
    $brief = Brief::fromArray(['summary' => 'Dashboard.', 'facts' => [['id' => 'deals', 'kind' => 'stat', 'value' => '1,250 deals closed']]]);

    expect(defectsBy(FactChecks::check(dashboardPage(['value' => 1250]), $brief, 2026), 'unsupported_claim'))->toBe([]);
});
