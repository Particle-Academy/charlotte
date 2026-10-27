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

test('a page carrying every required fact, and nothing else, passes', function (): void {
    $page = pageWithCopy(['Acme Ledger keeps your books.', 'Starter plan: $19/month', 'Write to hello@example.com', 'Saved me Sunday nights. R. Diaz']);

    expect(FactChecks::check($page, factBrief()))->toBe([]);
});

test('an invented price is caught as an unsupported claim', function (): void {
    $page = pageWithCopy(['Starter plan: $19/month', 'Pro plan: $29/month', 'hello@example.com']);

    $claims = defectsBy(FactChecks::check($page, factBrief()), 'unsupported_claim');

    expect($claims)->toHaveCount(1)
        ->and($claims[0])->toMatchArray(['severity' => 'warning', 'kind' => 'currency', 'claim' => '$29', 'node' => 't1']);
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
