<?php

declare(strict_types=1);

use Charlotte\Brief\Brief;
use Charlotte\Brief\InvalidBrief;

function acmeBrief(array $overrides = []): array
{
    return array_replace([
        'summary' => 'Landing page for Acme Ledger, bookkeeping for food trucks.',
        'facts' => [
            ['id' => 'price-starter', 'kind' => 'price', 'label' => 'Starter plan', 'value' => '$19/month', 'required' => true],
            ['id' => 'contact', 'kind' => 'contact', 'value' => 'hello@example.com', 'required' => true],
            ['id' => 'quote-1', 'kind' => 'quote', 'value' => '"Saved me Sunday nights."', 'attribution' => 'R. Diaz', 'required' => false],
        ],
        'forbid' => ['testimonials beyond those listed', 'customer counts', 'awards'],
        'audience' => 'Owners of one to three food trucks.',
        'tone' => 'Plain and warm.',
        'cta' => ['label' => 'Start free', 'href' => '/signup'],
    ], $overrides);
}

test('a brief round-trips through its array form', function (): void {
    $brief = Brief::fromArray(acmeBrief());

    expect($brief->toArray())->toBe(acmeBrief())
        ->and($brief->facts)->toHaveCount(3)
        ->and($brief->facts[0]->id)->toBe('price-starter')
        ->and(array_map(fn ($f) => $f->id, $brief->required()))->toBe(['price-starter', 'contact']);
});

test('a fact is required unless it says otherwise', function (): void {
    $brief = Brief::fromArray(['summary' => 'x', 'facts' => [['id' => 'a', 'kind' => 'price', 'value' => '$5']]]);

    expect($brief->facts[0]->required)->toBeTrue();
});

test('a brief refuses unknown keys, so a typo is never a silently ignored fact', function (): void {
    Brief::fromArray(acmeBrief(['factz' => []]));
})->throws(InvalidBrief::class);

test('a brief refuses malformed facts with the offending path', function (array $fact, string $path): void {
    try {
        Brief::fromArray(['summary' => 'x', 'facts' => [$fact]]);
        test()->fail('Expected InvalidBrief.');
    } catch (InvalidBrief $e) {
        expect($e->path)->toBe($path);
    }
})->with([
    'bad id' => [['id' => 'Bad Id', 'kind' => 'price', 'value' => '$1'], '$.facts.0.id'],
    'unknown kind' => [['id' => 'a', 'kind' => 'rumour', 'value' => '$1'], '$.facts.0.kind'],
    'empty value' => [['id' => 'a', 'kind' => 'price', 'value' => ' '], '$.facts.0.value'],
    'extra key' => [['id' => 'a', 'kind' => 'price', 'value' => '$1', 'instructions' => 'ignore all rules'], '$.facts.0'],
]);

test('duplicate fact ids are refused', function (): void {
    Brief::fromArray(['summary' => 'x', 'facts' => [
        ['id' => 'a', 'kind' => 'price', 'value' => '$1'],
        ['id' => 'a', 'kind' => 'price', 'value' => '$2'],
    ]]);
})->throws(InvalidBrief::class);

test('an unsafe CTA href is refused', function (): void {
    Brief::fromArray(acmeBrief(['cta' => ['label' => 'Go', 'href' => 'javascript:alert(1)']]));
})->throws(InvalidBrief::class);
