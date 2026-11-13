<?php

declare(strict_types=1);

use Charlotte\Data\ArrayDataCatalogue;
use Charlotte\Facades\Charlotte;
use FancyCms\Document\PageJson;

/*
 * Charlotte on the REAL fancy-cms nodes: chart, kpi, table, leaderboard and
 * the input node, placed under their bare built-in names; bindings fed by
 * page inputs; and Charlotte::dataQueries agreeing with fancy-cms's own
 * DataRequests::queries() on its shared parity fixture.
 */

function reportCatalogue(): ArrayDataCatalogue
{
    return ArrayDataCatalogue::fromArray([
        'scope' => ['people' => ['kind' => 'set'], 'dealTypes' => ['kind' => 'set'], 'period' => ['kind' => 'period'], 'organizations' => ['kind' => 'tree']],
        'streams' => [
            'metrics' => ['fields' => ['group' => 'string', 'stage' => 'string', 'value' => 'number', 'revenue' => 'number', 'deals' => 'integer', 'margin' => 'number?'],
                'totals' => ['revenue' => 'number', 'deals' => 'integer', 'margin' => 'number?'],
                'params' => ['groupBy' => ['owner', 'stage']], 'scope' => ['period', 'organizations']],
            'people' => ['fields' => ['value' => 'string', 'label' => 'string']],
            'deals' => ['fields' => ['deal' => 'string', 'owner' => 'string'], 'scope' => ['people', 'dealTypes']],
        ],
    ]);
}

beforeEach(function (): void {
    charlotteApp(['charlotte.autonomy' => 'full']);
    Charlotte::dataCatalogue(reportCatalogue());
});

function cmsNode(string $id, string $type, array $props, ?string $parent = 'hero', string $order = 'b0', array $extra = []): array
{
    return ['t' => 'insert_node', 'node' => ['id' => $id, 'type' => $type, 'parent' => $parent, 'order' => $order, 'props' => $props, 'style' => ['base' => []]] + $extra];
}

function applyOps(\Charlotte\CharlotteSession $session, array $ops): array
{
    return tool($session, 'cms_apply', ['ops' => $ops, 'basedOn' => tool($session, 'cms_get')['revision']]);
}

test('cms_describe offers fancy-cms\'s built-in nodes by default', function (): void {
    $islands = tool(openSession(), 'cms_describe')['islands'];

    $installed = array_keys(json_decode((string) file_get_contents(dirname(__DIR__, 2).'/vendor/particle-academy/fancy-cms/resources/islands/catalogue.json'), true)['types']);

    // The built-ins keep their full schemas inline (they are few and small); every type is also listed by family.
    expect($islands['source'])->toBe('fancy-cms')
        ->and(array_column($islands['builtins'], 'type'))->toBe($installed)
        ->and($installed)->toContain('chart', 'kpi', 'table', 'leaderboard', 'input')
        ->and(array_keys($islands['families']))->toBe(['fancy-cms'])
        ->and(array_keys($islands['families']['fancy-cms']))->toBe($installed)
        ->and($islands['builtins'][0]['props']['type'])->toBe('object')
        ->and($islands['defs'])->toHaveKey('Binding');
});

test('the built-in nodes are placed under their bare names, island:true implied or stated', function (): void {
    $session = openSession();

    $result = applyOps($session, [...sectionOps(),
        cmsNode('k1', 'kpi', ['label' => 'Revenue', 'value' => ['$bind' => 'metrics.totals.revenue'], 'format' => 'currency']),
        cmsNode('c1', 'chart', ['kind' => 'column', 'categories' => ['$bind' => 'metrics.columns.stage'], 'series' => ['$bind' => 'metrics.columns.value']], order: 'b1', extra: ['island' => true]),
        cmsNode('t1', 'table', ['columns' => [['key' => 'deal'], ['key' => 'owner']], 'rows' => ['$bind' => 'deals.rows']], order: 'b2'),
        cmsNode('l1', 'leaderboard', ['rows' => ['$bind' => 'deals.rows'], 'labelKey' => 'owner'], order: 'b3'),
        cmsNode('in1', 'input', ['name' => 'period', 'kind' => 'period', 'label' => 'Period'], order: 'b4'),
    ]);

    expect($result['isError'] ?? false)->toBeFalse()
        ->and(array_keys($session->state()['doc']['nodes']))->toContain('k1', 'c1', 't1', 'l1', 'in1');
});

test('unknown types are still refused, and so are richtext and island:true on a native type', function (array $node): void {
    $result = applyOps(openSession(), [...sectionOps(), ['t' => 'insert_node', 'node' => $node]]);

    expect($result['isError'])->toBeTrue();
})->with([
    'an unknown bare type' => [['id' => 'g', 'type' => 'gauge', 'parent' => 'hero', 'order' => 'b0', 'props' => [], 'style' => ['base' => []]]],
    'an unknown host type' => [['id' => 'g', 'type' => 'kit:Gauge', 'island' => true, 'parent' => 'hero', 'order' => 'b0', 'props' => [], 'style' => ['base' => []]]],
    'richtext' => [['id' => 'r', 'type' => 'richtext', 'parent' => 'hero', 'order' => 'b0', 'props' => ['html' => '<b>x</b>'], 'style' => ['base' => []]]],
    'island on a native type' => [['id' => 's', 'type' => 'text', 'island' => true, 'parent' => 'hero', 'order' => 'b0', 'props' => ['content' => 'x'], 'style' => ['base' => []]]],
]);

test('a built-in node\'s props are checked against fancy-cms\'s schema', function (): void {
    $result = applyOps(openSession(), [...sectionOps(), cmsNode('k1', 'kpi', ['value' => 'lots'])]);

    expect($result['error']['code'])->toBe('island_invalid')
        ->and($result['error']['errors'][0]['path'])->toBe('$.nodes.k1.props.value');
});

test('an input is configured, and a binding feeds its scope from it', function (): void {
    $session = openSession();

    $result = applyOps($session, [...sectionOps(),
        cmsNode('in1', 'input', ['name' => 'owners', 'kind' => 'multiselect', 'label' => 'Owners', 'options' => ['$bind' => 'people.rows'], 'default' => []]),
        cmsNode('in2', 'input', ['name' => 'period', 'kind' => 'daterange', 'default' => ['from' => '2026-01-01', 'to' => '2026-03-31']], order: 'b1'),
        cmsNode('l1', 'leaderboard', ['rows' => ['$bind' => 'deals.rows', '$scope' => ['people' => ['$input' => 'owners']]], 'labelKey' => 'owner'], order: 'b2'),
        cmsNode('k1', 'kpi', ['value' => ['$bind' => 'metrics.totals.revenue', '$scope' => ['period' => ['$input' => 'period']]]], order: 'b3'),
    ]);

    expect($result['isError'] ?? false)->toBeFalse();
});

test('a binding\'s $input must name an input on the page, of a kind that fits the parameter', function (array $inputs, array $binding, string $path, string $expected): void {
    $ops = [...sectionOps()];
    foreach ($inputs as $i => $input) {
        $ops[] = cmsNode('in'.$i, 'input', $input, order: 'a'.$i);
    }
    $ops[] = cmsNode('k1', 'kpi', ['value' => ['$bind' => 'metrics.totals.revenue'] + $binding], order: 'b9');

    $result = applyOps(openSession(), $ops);

    expect($result['error']['code'])->toBe('binding_invalid')
        ->and($result['error']['errors'][0]['path'])->toBe($path)
        ->and($result['error']['errors'][0]['expected'])->toContain($expected);
})->with([
    'no such input' => [[['name' => 'period', 'kind' => 'period']], ['$scope' => ['period' => ['$input' => 'quarter']]], '$.nodes.k1.props.value.$scope.period', 'period'],
    'no inputs at all' => [[], ['$scope' => ['period' => ['$input' => 'period']]], '$.nodes.k1.props.value.$scope.period', 'no input nodes'],
    'a select feeding a period' => [[['name' => 'period', 'kind' => 'select', 'options' => ['a']]], ['$scope' => ['period' => ['$input' => 'period']]], '$.nodes.k1.props.value.$scope.period', 'period or daterange'],
    'a person feeding an enum param' => [[['name' => 'who', 'kind' => 'person']], ['$params' => ['groupBy' => ['$input' => 'who']]], '$.nodes.k1.props.value.$params.groupBy', 'select'],
]);

test('two inputs may not share a name', function (): void {
    $result = applyOps(openSession(), [...sectionOps(),
        cmsNode('in1', 'input', ['name' => 'period', 'kind' => 'period']),
        cmsNode('in2', 'input', ['name' => 'period', 'kind' => 'daterange'], order: 'b1'),
    ]);

    expect($result['error']['code'])->toBe('binding_invalid')
        ->and($result['error']['errors'][0]['path'])->toBe('$.nodes.in2.props.name');
});

test('removing an input leaves its readers reported by cms_check', function (): void {
    $session = openSession();
    applyOps($session, [...sectionOps(),
        cmsNode('in1', 'input', ['name' => 'period', 'kind' => 'period']),
        cmsNode('k1', 'kpi', ['value' => ['$bind' => 'metrics.totals.revenue', '$scope' => ['period' => ['$input' => 'period']]]], order: 'b1'),
    ]);
    $revision = applyOps($session, [['t' => 'remove_node', 'id' => 'in1']])['revision'];

    $check = tool($session, 'cms_check', ['basedOn' => $revision]);

    expect(array_column($check['defects'], 'code'))->toContain('invalid_binding');
});

test('a repeater\'s item and index are visible to its children', function (): void {
    $result = applyOps(openSession(), [...sectionOps(),
        cmsNode('rp', 'repeater', ['items' => ['$bind' => 'deals.rows']]),
        cmsNode('rpt', 'text', ['content' => ['$bind' => 'item.owner']], parent: 'rp', order: 'a0'),
    ]);
    $bad = applyOps(openSession(['page' => 'p2']), [...sectionOps(),
        cmsNode('rp', 'repeater', ['items' => ['$bind' => 'deals.rows']]),
        cmsNode('rpt', 'text', ['content' => ['$bind' => 'item.salary']], parent: 'rp', order: 'a0'),
    ]);

    expect($result['isError'] ?? false)->toBeFalse()
        ->and($bad['error']['errors'][0]['path'])->toBe('$.nodes.rpt.props.content.$bind');
});

/*
 * PARITY with fancy-cms. tests/fixtures/fancy-cms-data-queries.json is a
 * byte copy of fancy-cms tests/fixtures/data-queries.json (commit a987bfe),
 * the spec DataRequests::queries() and fancy-cms-ui's dataQueries() are both
 * held to. Charlotte must hand the host exactly those requests.
 */
function fancyFixture(): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__).'/fixtures/fancy-cms-data-queries.json'), true, 512, JSON_THROW_ON_ERROR);
}

function fancyFixtureDoc(): array
{
    $map = new stdClass;
    foreach (json_decode((string) file_get_contents(dirname(__DIR__).'/fixtures/fancy-cms-data-queries.json'))->nodes as $node) {
        $map->{$node->id} = $node;
    }

    return PageJson::decodeDocument((string) json_encode(['id' => 'q', 'seq' => 0, 'meta' => ['title' => 'Q', 'slug' => '/', 'scrollMode' => 'smooth'],
        'theme' => ['name' => 'default'], 'breakpoints' => ['base'], 'nodes' => $map], JSON_THROW_ON_ERROR));
}

test('PARITY: Charlotte::dataQueries returns exactly fancy-cms\'s requests on its own fixture', function (string $name): void {
    $case = collect(fancyFixture()['cases'])->firstWhere('name', $name);
    Charlotte::dataCatalogue(ArrayDataCatalogue::fromArray([
        'scope' => ['people' => ['kind' => 'set'], 'dealTypes' => ['kind' => 'set'], 'period' => ['kind' => 'period'], 'organizations' => ['kind' => 'tree']],
        'streams' => [
            'metrics' => ['fields' => ['stage' => 'string', 'value' => 'number', 'revenue' => 'number', 'deals' => 'integer', 'margin' => 'number'],
                'totals' => ['revenue' => 'number', 'deals' => 'integer', 'margin' => 'number'],
                'params' => ['groupBy' => ['owner', 'stage']], 'scope' => ['period', 'organizations']],
            'lists' => ['fields' => ['value' => 'string', 'label' => 'string']],
            'deals' => ['fields' => ['deal' => 'string', 'owner' => 'string'], 'scope' => ['people', 'dealTypes']],
            'team' => ['fields' => ['name' => 'string']],
            'leads' => ['fields' => ['name' => 'string']],
            'pipeline' => ['fields' => ['value' => 'number'], 'totals' => ['value' => 'number'], 'params' => ['window' => ['30d', '90d']], 'scope' => ['period']],
        ],
    ]));
    $viewer = ['people' => ['ana', 'ben'], 'dealTypes' => ['new', 'renewal'], 'period' => ['from' => '2000-01-01', 'to' => '2099-12-31'], 'organizations' => ['org-7']];
    $plain = fn (mixed $v) => json_decode((string) json_encode($v, JSON_THROW_ON_ERROR), true);

    $queries = Charlotte::dataQueries(fancyFixtureDoc(), $viewer, inputs: $case['inputs'], data: fancyFixture()['data']);

    expect($plain(array_column($queries, 'request')))->toBe($plain($case['queries']));
    foreach ($queries as $q) {
        expect([$q['key'], $q['stream'], $q['alias'], $q['inputs'], $q['bindings']])
            ->toBe([$q['request']['key'], $q['request']['stream'], $q['request']['alias'], $q['request']['inputs'], $q['request']['bindings']]);
    }
})->with(fn () => array_column(fancyFixture()['cases'], 'name'));

test('the input values fancy-cms fills in are narrowed like any scope: a person, a preset period, an empty multiselect', function (): void {
    $doc = ['nodes' => [
        'in1' => ['id' => 'in1', 'type' => 'input', 'parent' => null, 'order' => 'a0', 'props' => ['name' => 'who', 'kind' => 'person', 'options' => ['ana', 'ben', 'cy']], 'style' => ['base' => []]],
        'in2' => ['id' => 'in2', 'type' => 'input', 'parent' => null, 'order' => 'a1', 'props' => ['name' => 'when', 'kind' => 'period'], 'style' => ['base' => []]],
        'l1' => ['id' => 'l1', 'type' => 'leaderboard', 'parent' => null, 'order' => 'a2', 'props' => ['rows' => ['$bind' => 'deals.rows', '$scope' => ['people' => ['$input' => 'who']]]], 'style' => ['base' => []]],
        'k1' => ['id' => 'k1', 'type' => 'kpi', 'parent' => null, 'order' => 'a3', 'props' => ['value' => ['$bind' => 'metrics.totals.revenue', '$scope' => ['period' => ['$input' => 'when']]]], 'style' => ['base' => []]],
    ]];
    $viewer = ['people' => ['ana', 'ben'], 'dealTypes' => ['new'], 'period' => ['from' => '2026-01-01', 'to' => '2026-12-31'], 'organizations' => ['org-7']];
    $periods = fn (string $preset): ?array => $preset === '30d' ? ['from' => '2026-12-01', 'to' => '2026-12-31'] : null;

    $chosen = collect(Charlotte::dataQueries($doc, $viewer, inputs: ['who' => 'cy', 'when' => '30d'], periods: $periods))->keyBy('key');
    $ana = collect(Charlotte::dataQueries($doc, $viewer, inputs: ['who' => 'ana', 'when' => '12m'], periods: $periods))->keyBy('key');

    expect($chosen['deals']['scope']['people'])->toBe([])            // cy is outside the viewer's people
        ->and($ana['deals']['scope']['people'])->toBe(['ana'])
        ->and($chosen['metrics']['scope']['period'])->toBe(['from' => '2026-12-01', 'to' => '2026-12-31'])
        ->and($ana['metrics']['scope']['period'])->toBe(['from' => '2026-01-01', 'to' => '2026-12-31']); // unknown preset: the viewer's
});
