<?php

declare(strict_types=1);

use Charlotte\Islands\CmsDescribeIslands;
use Charlotte\Islands\SchemaIslandCatalogue;
use FancyCms\Agent;

/*
 * The REAL fancy-cms island catalogue (`{$schema, $defs, types}`), read from
 * Agent::describe() and from resources/islands/catalogue.json: the
 * infographic kit and the input node as bare built-in types, host islands as
 * namespace:Name, and props validated with the catalogue's own $defs.
 */

function cmsCatalogueFile(): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__, 2).'/vendor/particle-academy/fancy-cms/resources/islands/catalogue.json'), true, 512, JSON_THROW_ON_ERROR);
}

test('Agent::describe() islands are read: the built-in node types as bare names, with their $defs', function (): void {
    $catalogue = CmsDescribeIslands::fromDescribe(Agent::describe());

    expect($catalogue)->not->toBeNull()
        ->and($catalogue->types())->toBe(['chart', 'kpi', 'table', 'leaderboard', 'input'])
        ->and($catalogue->source())->toBe('fancy-cms')
        ->and(array_keys($catalogue->defs()))->toContain('Binding', 'Number', 'Text')
        ->and(array_column($catalogue->describe(), 'type'))->toBe(['chart', 'kpi', 'table', 'leaderboard', 'input'])
        ->and($catalogue->describe()[1]['example']['value'])->toBe(['$bind' => 'metrics.revenue']);
});

test('the catalogue file parses to the same catalogue as describe()', function (): void {
    expect(CmsDescribeIslands::fromCatalogue(cmsCatalogueFile())->describe())
        ->toEqual(CmsDescribeIslands::fromDescribe(Agent::describe())->describe());
});

test('the default binding is fancy-cms\'s catalogue', function (): void {
    expect(CmsDescribeIslands::catalogue()->types())->toBe(['chart', 'kpi', 'table', 'leaderboard', 'input']);
});

test('props are validated with the catalogue\'s $defs: a binding or a number, never a typed-in string', function (string $type, array $props, ?string $path): void {
    $errors = CmsDescribeIslands::catalogue()->validate($type, $props);

    expect($path === null ? $errors : ($errors[0]['path'] ?? null))->toBe($path ?? []);
})->with([
    'kpi bound' => ['kpi', ['value' => ['$bind' => 'metrics.totals.revenue']], null],
    'kpi literal number' => ['kpi', ['value' => 1200], null],
    'kpi string value' => ['kpi', ['value' => 'lots'], '$.value'],
    'kpi format outside the enum' => ['kpi', ['value' => 1, 'format' => 'roman'], '$.format'],
    'input period' => ['input', ['name' => 'period', 'kind' => 'period'], null],
    'input daterange' => ['input', ['name' => 'range', 'kind' => 'daterange', 'default' => ['from' => '2026-01-01', 'to' => '2026-03-31']], null],
    'input multiselect bound options' => ['input', ['name' => 'owners', 'kind' => 'multiselect', 'options' => ['$bind' => 'people.rows']], null],
    'input bad name' => ['input', ['name' => 'Bad Name', 'kind' => 'select'], '$.name'],
    'input unknown kind' => ['input', ['name' => 'x', 'kind' => 'slider'], '$.kind'],
    'input without a name' => ['input', ['kind' => 'select'], '$.name'],
]);

test('host islands are added beside the built-ins and stay namespace:Name', function (): void {
    $catalogue = CmsDescribeIslands::withHost([['type' => 'kit:Tabs', 'description' => 'Tabs.', 'props' => ['type' => 'object']]]);

    expect($catalogue->types())->toBe(['chart', 'kpi', 'table', 'leaderboard', 'input', 'kit:Tabs'])
        ->and(fn () => CmsDescribeIslands::withHost([['type' => 'tabs', 'props' => ['type' => 'object']]]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => SchemaIslandCatalogue::fromArray([['type' => 'gauge', 'props' => ['type' => 'object']]]))->toThrow(InvalidArgumentException::class);
});

test('a built-in may never be a native or excluded node type', function (string $type): void {
    $file = cmsCatalogueFile();
    $file['types'][$type] = $file['types']['kpi'];

    expect(CmsDescribeIslands::fromCatalogue($file))->toBeNull();
})->with(['richtext', 'section', 'text']);
