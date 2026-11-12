<?php

declare(strict_types=1);

use Charlotte\Data\ArrayDataCatalogue;
use Charlotte\Facades\Charlotte;
use Charlotte\Islands\IslandCatalogue;
use Charlotte\Islands\SchemaIslandCatalogue;

/*
 * Report dashboards: the host declares its data streams, cms_describe hands
 * them to the agent, and every write that binds a node to anything else is
 * refused. Checks then treat bound figures as evidence.
 */

/** Infographic islands shaped like fancy-cms's kpi and table: data props take a literal or a binding. */
function dashboardIslands(): array
{
    $binding = ['type' => 'object', 'required' => ['$bind']];

    return [
        ['type' => 'kit:Kpi', 'description' => 'Stat tile.', 'props' => ['type' => 'object', 'required' => ['value'], 'properties' => [
            'label' => ['type' => 'string'],
            'value' => ['anyOf' => [['type' => 'number'], $binding]],
            'delta' => ['anyOf' => [['type' => 'number'], $binding]],
            'format' => ['enum' => ['number', 'currency', 'percent']],
        ]]],
        ['type' => 'kit:Table', 'description' => 'Data table.', 'props' => ['type' => 'object', 'required' => ['rows'], 'properties' => [
            'rows' => ['anyOf' => [['type' => 'array'], $binding]],
            'columns' => ['type' => 'array'],
        ]]],
    ];
}

function dashboardCatalogue(): ArrayDataCatalogue
{
    return ArrayDataCatalogue::fromArray([
        'scope' => ['people' => ['kind' => 'set'], 'period' => ['kind' => 'period']],
        'streams' => [
            'metrics' => ['description' => 'Pipeline metrics.', 'fields' => ['group' => 'string', 'revenue' => 'number'],
                'totals' => ['revenue' => 'number'], 'params' => ['groupBy' => ['owner', 'stage']], 'scope' => ['people', 'period']],
            'revenueByRep' => ['fields' => ['rep' => 'string', 'revenue' => 'number'], 'scope' => ['people', 'period']],
        ],
    ]);
}

beforeEach(function (): void {
    charlotteApp(['charlotte.autonomy' => 'full']);
    app()->instance(IslandCatalogue::class, SchemaIslandCatalogue::fromArray(dashboardIslands()));
    Charlotte::dataCatalogue(dashboardCatalogue());
});

function kpiOps(array $value): array
{
    return [...sectionOps(), islandOp('kpi', 'kit:Kpi', ['label' => 'Revenue', 'value' => $value, 'format' => 'currency'], 'hero')];
}

test('cms_describe hands the agent the host data catalogue and how to bind', function (): void {
    $described = tool(openSession(), 'cms_describe');

    expect(array_column($described['data']['streams'], 'name'))->toBe(['metrics', 'revenueByRep'])
        ->and($described['data']['streams'][0]['totals'])->toBe(['revenue' => 'number'])
        ->and($described['data']['scope']['period']['kind'])->toBe('period')
        ->and($described['data']['bind'])->toContain('$bind');
});

test('a node bound to a catalogue stream is saved', function (): void {
    $session = openSession();
    $revision = tool($session, 'cms_get')['revision'];

    $result = tool($session, 'cms_apply', ['ops' => kpiOps(['$bind' => 'metrics.totals.revenue', '$scope' => ['period' => ['$input' => 'period']]]), 'basedOn' => $revision]);

    expect($result['isError'] ?? false)->toBeFalse()
        ->and($session->state()['doc']['nodes'])->toHaveKey('kpi');
});

test('a binding to an unknown stream or field is refused with a clear error, and nothing is written', function (string $path, string $expected): void {
    $session = openSession();
    $revision = tool($session, 'cms_get')['revision'];

    $result = tool($session, 'cms_apply', ['ops' => kpiOps(['$bind' => $path]), 'basedOn' => $revision]);

    expect($result['isError'])->toBeTrue()
        ->and($result['error']['code'])->toBe('binding_invalid')
        ->and($result['error']['errors'][0]['path'])->toBe('$.nodes.kpi.props.value.$bind')
        ->and($result['error']['errors'][0]['expected'])->toContain($expected)
        ->and($result['error']['errors'][0]['hint'])->toContain('cms_describe data')
        ->and($session->state()['doc']['nodes'])->not->toHaveKey('kpi')
        ->and(eventsOf($session, 'doc.changed'))->toBe([]);
})->with([
    'unknown stream' => ['salaries.totals.total', 'metrics, revenueByRep'],
    'unknown field' => ['metrics.columns.salary', 'group, revenue'],
]);

test('HOSTILE: a binding cannot carry a tenant, an actor or a scope the stream does not accept', function (array $binding, string $path): void {
    $session = openSession();
    $revision = tool($session, 'cms_get')['revision'];

    $result = tool($session, 'cms_apply', ['ops' => kpiOps(['$bind' => 'metrics.totals.revenue'] + $binding), 'basedOn' => $revision]);

    expect($result['error']['code'])->toBe('binding_invalid')
        ->and($result['error']['errors'][0]['path'])->toBe($path);
})->with([
    'a tenant key' => [['$tenant' => 'rival'], '$.nodes.kpi.props.value.$tenant'],
    'a viewer override' => [['$scope' => ['viewer' => 'admin']], '$.nodes.kpi.props.value.$scope.viewer'],
    'a wildcard people set' => [['$scope' => ['people' => '*']], '$.nodes.kpi.props.value.$scope.people'],
]);

test('a person\'s write is held to the catalogue too', function (): void {
    $session = openSession();
    $revision = $session->state()['revision'];

    expect(fn () => $session->applyHuman(kpiOps(['$bind' => 'salaries.rows']), $revision, 'user-7'))
        ->toThrow(\FancyCms\Exceptions\PageSessionException::class);
});

test('cms_check reports a binding that no longer matches the catalogue, and no unsupported claim for a bound figure', function (): void {
    $session = openSession();
    $revision = tool($session, 'cms_apply', ['ops' => kpiOps(['$bind' => 'metrics.totals.revenue']), 'basedOn' => tool($session, 'cms_get')['revision']])['revision'];

    // The host narrows its catalogue after the page was built.
    Charlotte::dataCatalogue(ArrayDataCatalogue::fromArray(['streams' => ['revenueByRep' => ['fields' => ['rep' => 'string', 'revenue' => 'number']]]]));
    $check = tool($session, 'cms_check', ['basedOn' => $revision]);

    $codes = array_column($check['defects'], 'code');
    expect($codes)->toContain('invalid_binding')
        ->and(array_values(array_filter($check['defects'], fn ($d) => $d['code'] === 'unsupported_claim')))->toBe([]);
});

test('a page can be rendered from the catalogue: queries for the viewer, then the data context', function (): void {
    $session = openSession();
    tool($session, 'cms_apply', ['ops' => kpiOps(['$bind' => 'metrics.totals.revenue', '$params' => ['groupBy' => 'owner']]), 'basedOn' => tool($session, 'cms_get')['revision']]);
    $doc = json_decode(json_encode($session->state()['doc']), true);

    $queries = Charlotte::dataQueries($doc, ['people' => ['u_1'], 'period' => ['from' => '2026-01-01', 'to' => '2026-03-31']]);

    expect($queries)->toBe([['key' => 'metrics', 'stream' => 'metrics', 'params' => ['groupBy' => 'owner'],
        'scope' => ['people' => ['u_1'], 'period' => ['from' => '2026-01-01', 'to' => '2026-03-31']]]]);
});
