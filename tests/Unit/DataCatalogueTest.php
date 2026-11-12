<?php

declare(strict_types=1);

use Charlotte\Data\ArrayDataCatalogue;
use Charlotte\Data\Bindings;
use Charlotte\Data\Scope;

/*
 * The host DataCatalogue: the named data streams a host supplies, each with
 * its row fields and types, whether it adds totals, the parameters it takes
 * and the scope parameters it accepts. Charlotte binds nodes to these
 * streams only, and a binding's scope can only NARROW what the viewer may
 * already see.
 */

function crmCatalogue(): ArrayDataCatalogue
{
    return ArrayDataCatalogue::fromArray([
        'scope' => [
            'people' => ['kind' => 'set', 'description' => 'User ids'],
            'dealTypes' => ['kind' => 'set', 'description' => 'Deal type keys'],
            'period' => ['kind' => 'period', 'description' => 'Inclusive date range'],
            'organizations' => ['kind' => 'tree', 'description' => 'Organization ids; children:true adds their child organizations'],
        ],
        'streams' => [
            'metrics' => [
                'description' => 'Pipeline metrics grouped by owner, stage or deal type.',
                'fields' => ['group' => 'string', 'deals' => 'integer', 'revenue' => 'number', 'winRate' => 'number'],
                'totals' => ['deals' => 'integer', 'revenue' => 'number'],
                'params' => ['groupBy' => ['owner', 'stage', 'dealType']],
                'scope' => ['people', 'dealTypes', 'period', 'organizations'],
            ],
            'revenueByRep' => [
                'description' => 'Closed revenue per sales rep.',
                'fields' => ['rep' => 'string', 'revenue' => 'number'],
                'scope' => ['people', 'period', 'organizations'],
            ],
            'dealsCutEarly' => [
                'fields' => ['deal' => 'string', 'owner' => 'string', 'closedOn' => 'date', 'value' => 'number'],
                'scope' => ['people', 'dealTypes', 'period'],
            ],
        ],
    ]);
}

/** A one-node document whose node carries these props (and optionally a repeat). */
function boundDoc(array $props, ?array $repeat = null, array $children = []): array
{
    $node = ['id' => 'n1', 'type' => 'kit:Kpi', 'parent' => null, 'order' => 'a0', 'island' => true, 'props' => $props, 'style' => ['base' => []]];
    if ($repeat !== null) {
        $node['repeat'] = $repeat;
    }
    $nodes = ['n1' => $node];
    foreach ($children as $id => $child) {
        $nodes[$id] = ['id' => $id, 'type' => 'text', 'parent' => 'n1', 'order' => 'a1', 'props' => $child, 'style' => ['base' => []]];
    }

    return ['nodes' => $nodes];
}

function bindingErrors(array $doc): array
{
    return Bindings::validate($doc, crmCatalogue());
}

test('the declaration states each stream\'s fields and types, totals, params and accepted scope', function (): void {
    $described = crmCatalogue()->describe();

    expect(array_column($described['streams'], 'name'))->toBe(['metrics', 'revenueByRep', 'dealsCutEarly'])
        ->and($described['streams'][0])->toMatchArray([
            'fields' => ['group' => 'string', 'deals' => 'integer', 'revenue' => 'number', 'winRate' => 'number'],
            'totals' => ['deals' => 'integer', 'revenue' => 'number'],
            'params' => ['groupBy' => ['owner', 'stage', 'dealType']],
            'scope' => ['people', 'dealTypes', 'period', 'organizations'],
        ])
        ->and($described['streams'][1]['totals'])->toBeNull()
        ->and($described['scope']['organizations']['kind'])->toBe('tree')
        ->and($described['bind'])->toContain('metrics.rows');
});

test('a malformed declaration is refused when the host builds it, not when the agent hits it', function (array $declaration): void {
    ArrayDataCatalogue::fromArray($declaration);
})->throws(InvalidArgumentException::class)->with([
    'unknown field type' => [['streams' => ['s' => ['fields' => ['a' => 'blob']]]]],
    'no fields' => [['streams' => ['s' => ['fields' => []]]]],
    'bad stream name' => [['streams' => ['bad.name' => ['fields' => ['a' => 'string']]]]],
    'totals on an undeclared field' => [['streams' => ['s' => ['fields' => ['a' => 'number'], 'totals' => ['b' => 'number']]]]],
    'scope param not declared' => [['streams' => ['s' => ['fields' => ['a' => 'string'], 'scope' => ['people']]]]],
    'unknown scope kind' => [['scope' => ['people' => ['kind' => 'everything']], 'streams' => []]],
    'empty param enum' => [['streams' => ['s' => ['fields' => ['a' => 'string'], 'params' => ['groupBy' => []]]]]],
]);

test('bindings to catalogue streams and their fields are accepted', function (): void {
    $doc = boundDoc([
        'value' => ['$bind' => 'metrics.totals.revenue', '$params' => ['groupBy' => 'owner'], '$scope' => ['period' => ['$input' => 'period'], 'people' => ['u_1']]],
        'rows' => ['$bind' => 'metrics.rows'],
        'categories' => ['$bind' => 'metrics.columns.group'],
        'series' => ['$bind' => 'revenueByRep#closed.columns.revenue', '$scope' => ['organizations' => ['ids' => ['org-a'], 'children' => true]]],
    ]);

    expect(bindingErrors($doc))->toBe([]);
});

test('a binding to an unknown stream or field is refused with the path and what exists', function (array $props, string $path, string $expected): void {
    $errors = bindingErrors(boundDoc($props));

    expect($errors)->not->toBe([])
        ->and($errors[0]['path'])->toBe($path)
        ->and($errors[0]['expected'])->toContain($expected);
})->with([
    'unknown stream' => [['value' => ['$bind' => 'salaries.rows']], '$.nodes.n1.props.value.$bind', 'metrics, revenueByRep, dealsCutEarly'],
    'unknown field' => [['value' => ['$bind' => 'metrics.columns.salary']], '$.nodes.n1.props.value.$bind', 'group, deals, revenue, winRate'],
    'totals on a stream without totals' => [['value' => ['$bind' => 'revenueByRep.totals.revenue']], '$.nodes.n1.props.value.$bind', 'revenueByRep has no totals'],
    'a total not declared' => [['value' => ['$bind' => 'metrics.totals.winRate']], '$.nodes.n1.props.value.$bind', 'deals, revenue'],
    'unknown part' => [['value' => ['$bind' => 'metrics.everything']], '$.nodes.n1.props.value.$bind', 'rows'],
    'scope the stream does not accept' => [['rows' => ['$bind' => 'revenueByRep.rows', '$scope' => ['dealTypes' => ['new']]]], '$.nodes.n1.props.rows.$scope.dealTypes', 'people, period, organizations'],
    'a scope key that is not a parameter' => [['rows' => ['$bind' => 'metrics.rows', '$scope' => ['viewer' => 'admin']]], '$.nodes.n1.props.rows.$scope.viewer', 'people, dealTypes, period, organizations'],
    'a param value outside its enum' => [['rows' => ['$bind' => 'metrics.rows', '$params' => ['groupBy' => 'salary']]], '$.nodes.n1.props.rows.$params.groupBy', 'owner'],
    'an undeclared binding key' => [['rows' => ['$bind' => 'metrics.rows', '$tenant' => 'rival']], '$.nodes.n1.props.rows.$tenant', '$bind, $source, $params, $scope'],
    'a malformed period' => [['rows' => ['$bind' => 'metrics.rows', '$scope' => ['period' => ['from' => '2026-12-01', 'to' => '2026-01-01']]]], '$.nodes.n1.props.rows.$scope.period', 'from <= to'],
]);

test('two bindings of one query must agree on its params and scope', function (): void {
    $errors = bindingErrors(boundDoc([
        'value' => ['$bind' => 'metrics.totals.revenue', '$params' => ['groupBy' => 'owner']],
        'rows' => ['$bind' => 'metrics.rows', '$params' => ['groupBy' => 'stage']],
    ]));

    expect($errors[0]['path'])->toBe('$.nodes.n1.props.rows.$params')
        ->and($errors[0]['expected'])->toContain('metrics#<alias>');
});

test('a repeat over a stream\'s rows lets its children bind that row\'s fields, and only those', function (): void {
    $ok = boundDoc(['label' => 'Reps'], ['each' => ['$bind' => 'revenueByRep.rows'], 'as' => 'rep'], ['c1' => ['content' => ['$bind' => 'rep.rep']]]);
    $bad = boundDoc(['label' => 'Reps'], ['each' => ['$bind' => 'revenueByRep.rows'], 'as' => 'rep'], ['c1' => ['content' => ['$bind' => 'rep.salary']]]);
    $notRows = boundDoc(['label' => 'Reps'], ['each' => ['$bind' => 'metrics.totals.revenue'], 'as' => 'm']);

    expect(bindingErrors($ok))->toBe([])
        ->and(bindingErrors($bad)[0]['path'])->toBe('$.nodes.c1.props.content.$bind')
        ->and(bindingErrors($notRows)[0]['path'])->toBe('$.nodes.n1.repeat.each.$bind');
});

test('with no catalogue bound, every binding is refused', function (): void {
    $errors = Bindings::validate(boundDoc(['value' => ['$bind' => 'metrics.rows']]), ArrayDataCatalogue::empty());

    expect($errors[0]['expected'])->toContain('declares no data streams');
});

test('queries list one request per bound query, with the scope narrowed to the viewer', function (): void {
    $doc = boundDoc([
        'value' => ['$bind' => 'metrics.totals.revenue', '$params' => ['groupBy' => 'owner'], '$scope' => ['people' => ['u_1', 'u_9']]],
        'rows' => ['$bind' => 'metrics.rows'],
        'series' => ['$bind' => 'revenueByRep.columns.revenue'],
    ]);
    $viewer = ['people' => ['u_1', 'u_2'], 'dealTypes' => ['new', 'renewal'], 'period' => ['from' => '2026-01-01', 'to' => '2026-06-30'], 'organizations' => ['org-a', 'org-a1']];

    $queries = Bindings::queries($doc, crmCatalogue(), $viewer);

    expect(array_column($queries, 'key'))->toBe(['metrics', 'revenueByRep'])
        ->and($queries[0])->toMatchArray(['stream' => 'metrics', 'params' => ['groupBy' => 'owner']])
        ->and($queries[0]['scope'])->toBe(['people' => ['u_1'], 'dealTypes' => ['new', 'renewal'], 'period' => ['from' => '2026-01-01', 'to' => '2026-06-30'], 'organizations' => ['org-a', 'org-a1']])
        ->and($queries[1]['scope'])->toBe(['people' => ['u_1', 'u_2'], 'period' => ['from' => '2026-01-01', 'to' => '2026-06-30'], 'organizations' => ['org-a', 'org-a1']]);
});

test('HOSTILE: a binding that asks for more than the viewer may see is narrowed, never widened', function (): void {
    // The agent (or a page input) asks for everyone, every org under the
    // root, all time, and a deal type the viewer cannot see.
    $requested = [
        'people' => ['u_1', 'u_ceo', 'u_other'],
        'dealTypes' => ['new', 'secret'],
        'period' => ['from' => '2000-01-01', 'to' => '2099-12-31'],
        'organizations' => ['ids' => ['org-root'], 'children' => true],
    ];
    $viewer = ['people' => ['u_1'], 'dealTypes' => ['new'], 'period' => ['from' => '2026-01-01', 'to' => '2026-03-31'], 'organizations' => ['org-a', 'org-a1']];
    $descendants = fn (string $org): array => $org === 'org-root' ? ['org-a', 'org-a1', 'org-b', 'org-b1'] : [];

    $scope = Scope::narrow(crmCatalogue(), 'metrics', $viewer, $requested, [], $descendants);
    $disjoint = Scope::narrow(crmCatalogue(), 'metrics', $viewer, ['period' => ['from' => '2025-01-01', 'to' => '2025-12-31'], 'people' => ['u_other']]);

    expect($scope)->toBe([
        'people' => ['u_1'],
        'dealTypes' => ['new'],
        'period' => ['from' => '2026-01-01', 'to' => '2026-03-31'],
        'organizations' => ['org-a', 'org-a1'],
    ])->and($disjoint['period'])->toBeNull()
        ->and($disjoint['people'])->toBe([]);
});

test('HOSTILE: a page input cannot widen the scope either, and a malformed one falls back to the viewer', function (): void {
    $viewer = ['people' => ['u_1'], 'dealTypes' => ['new'], 'period' => ['from' => '2026-01-01', 'to' => '2026-03-31'], 'organizations' => ['org-a']];
    $requested = ['period' => ['$input' => 'period'], 'people' => ['$input' => 'who']];

    $scope = Scope::narrow(crmCatalogue(), 'metrics', $viewer, $requested, ['period' => ['from' => '1990-01-01', 'to' => '2026-02-01'], 'who' => 'everyone']);

    expect($scope['period'])->toBe(['from' => '2026-01-01', 'to' => '2026-02-01'])
        ->and($scope['people'])->toBe(['u_1']);
});

test('HOSTILE: a viewer scope that does not state a parameter fails closed', function (): void {
    Scope::narrow(crmCatalogue(), 'metrics', ['people' => ['u_1'], 'dealTypes' => ['new'], 'period' => ['from' => '2026-01-01', 'to' => '2026-01-31']], []);
})->throws(InvalidArgumentException::class, 'organizations');

test('context derives each bound query\'s columns from its rows for charts', function (): void {
    $data = Bindings::context(['metrics' => ['rows' => [['group' => 'Ada', 'revenue' => 10], ['group' => 'Bo', 'revenue' => 5]], 'totals' => ['revenue' => 15]]]);

    expect($data['metrics']['columns'])->toBe(['group' => ['Ada', 'Bo'], 'revenue' => [10, 5]])
        ->and($data['metrics']['totals'])->toBe(['revenue' => 15]);
});
