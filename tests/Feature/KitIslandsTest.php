<?php

declare(strict_types=1);

use Charlotte\Guide\BuilderMode;
use Charlotte\Islands\CmsDescribeIslands;
use Charlotte\Islands\IslandCatalogue;
use Charlotte\Islands\IslandIndex;
use Charlotte\Islands\IslandPolicy;
use Charlotte\Policy\EmptyAssetCatalogue;
use FancyCms\Exceptions\PageSessionException;
use Charlotte\Islands\SchemaIslandCatalogue;
use Charlotte\Tests\Support\ScriptedProvider;
use FancyCms\Islands\IslandCatalogue as FancyIslandCatalogue;
use FancyCms\Render\EchartOption;
use Prism\Harness\Modes\ModeRegistry;

/*
 * Every kit component (owner, 2026-11-13). fancy-cms serves the whole kit as
 * IslandCatalogue::kit(): its built-ins (echart included) and 180
 * `<package>:<Component>` islands, about 300 KB of schemas. Too big for a
 * prompt or one tool result, so cms_describe lists each type in one line by
 * family, cms_island_schema returns the full schema of a few named types,
 * and every write is still validated against the FULL schema.
 *
 * tests/fixtures/fancy-cms-kit-catalogue.json is fancy-cms 8d1d435's
 * `IslandCatalogue::kit()->toArray()`, so these tests do not depend on which
 * fancy-cms release is installed. The drift test below holds it to the
 * installed one once that release serves the kit.
 */

function kitDocument(): array
{
    static $document = null;

    return $document ??= json_decode((string) file_get_contents(dirname(__DIR__).'/fixtures/fancy-cms-kit-catalogue.json'), true, 512, JSON_THROW_ON_ERROR);
}

function kitCatalogue(): SchemaIslandCatalogue
{
    return CmsDescribeIslands::fromKit(kitDocument());
}

function kitApp(array $config = [], ?ScriptedProvider $provider = null): void
{
    charlotteApp(['charlotte.autonomy' => 'full', ...$config], $provider);
    app()->instance(IslandCatalogue::class, kitCatalogue());
}

test('the kit catalogue reads as fancy-cms\'s built-ins plus 180 kit islands', function (): void {
    $catalogue = kitCatalogue();
    $builtins = array_values(array_filter($catalogue->types(), fn (string $t) => $catalogue->builtin($t)));

    expect(count($catalogue->types()))->toBe(186)
        ->and($builtins)->toBe(['chart', 'echart', 'kpi', 'table', 'leaderboard', 'input'])
        ->and($catalogue->types())->toContain('react-fancy:Tabs', 'fancy-sheets:Spreadsheet', 'fancy-brand-icons:BrandIcon')
        ->and($catalogue->source())->toBe('fancy-cms-kit');
});

test('every catalogue example passes the same validation a write gets', function (): void {
    $catalogue = kitCatalogue();
    $failed = [];
    foreach ($catalogue->describe() as $entry) {
        if (array_key_exists('example', $entry) && ($errors = $catalogue->validate($entry['type'], $entry['example'])) !== []) {
            $failed[$entry['type']] = $errors;
        }
    }

    expect($failed)->toBe([]);
});

test('cms_describe stays compact: every type in one line, grouped by family, no kit schema inline', function (): void {
    kitApp();
    $raw = openSession()->tools()->call('cms_describe');
    $islands = json_decode($raw, true, 512, JSON_THROW_ON_ERROR)['islands'];
    $listed = array_merge(...array_map('array_keys', array_values($islands['families'])));

    expect($islands['count'])->toBe(186)
        ->and($listed)->toHaveCount(186)
        ->and(array_keys($islands['families']))->toContain('fancy-cms', 'react-fancy', 'fancy-sheets', 'fancy-trading-ui')
        ->and($islands['families']['react-fancy']['react-fancy:Tabs'])->toBe('Tabbed panels; each tab\'s body is paragraphs of text.')
        ->and($islands['families']['fancy-cms'])->toHaveKeys(['chart', 'echart', 'kpi', 'table', 'leaderboard', 'input'])
        ->and(array_column($islands['builtins'], 'type'))->toBe(['chart', 'echart', 'kpi', 'table', 'leaderboard', 'input'])
        ->and($raw)->not->toContain('"Shape/fill variant"') // a react-fancy:Button prop description
        ->and(max(array_map('mb_strlen', array_merge(...array_map('array_values', array_values($islands['families']))))))->toBeLessThanOrEqual(IslandIndex::LINE)
        ->and(strlen($raw))->toBeLessThan(40_000)
        ->and(strlen(json_encode(kitDocument())))->toBeGreaterThan(250_000);
});

test('cms_island_schema returns exactly the requested types, their full schema and example, and only the defs they use', function (): void {
    kitApp();
    $result = tool(openSession(), 'cms_island_schema', ['types' => ['react-fancy:Tabs', 'echart', 'fancy-sheets:Spreadsheet']]);
    $full = kitDocument()['types'];

    expect(array_column($result['types'], 'type'))->toBe(['react-fancy:Tabs', 'echart', 'fancy-sheets:Spreadsheet'])
        ->and($result['types'][0]['props'])->toBe($full['react-fancy:Tabs']['props'])
        ->and($result['types'][0]['example'])->toBe($full['react-fancy:Tabs']['example'])
        ->and($result['types'][0]['package'])->toBe('@particle-academy/react-fancy')
        ->and(array_keys($result['defs']))->toBe(['Binding', 'Text'])
        ->and($result)->not->toHaveKey('unknown')
        ->and($result)->toHaveKey('revision');
});

test('cms_island_schema names a type it does not have instead of guessing, and returns the rest', function (): void {
    kitApp();
    $result = tool(openSession(), 'cms_island_schema', ['types' => ['react-fancy:Tabs', 'react-fancy:Shell']]);

    expect(array_column($result['types'], 'type'))->toBe(['react-fancy:Tabs'])
        ->and($result['unknown'])->toBe(['react-fancy:Shell']);
});

test('cms_island_schema is capped per call, and the cap is configurable', function (): void {
    kitApp(['charlotte.islands.schema_max_types' => 2]);
    $session = openSession();

    $over = tool($session, 'cms_island_schema', ['types' => ['react-fancy:Tabs', 'react-fancy:Card', 'react-fancy:Badge']]);
    $at = tool($session, 'cms_island_schema', ['types' => ['react-fancy:Tabs', 'react-fancy:Card']]);
    $string = tool($session, 'cms_island_schema', ['types' => '["react-fancy:Badge"]']);
    $empty = tool($session, 'cms_island_schema', ['types' => []]);

    expect($over['error']['code'])->toBe('invalid_request')
        ->and($over['error']['errors'][0])->toMatchArray(['path' => '$.arguments.types', 'got' => '3 types'])
        ->and($over['error']['errors'][0]['expected'])->toContain('at most 2')
        ->and(array_column($at['types'], 'type'))->toBe(['react-fancy:Tabs', 'react-fancy:Card'])
        ->and(array_column($string['types'], 'type'))->toBe(['react-fancy:Badge'])
        ->and($empty['error']['errors'][0]['path'])->toBe('$.arguments.types');
});

test('a kit island with props from its schema lands; island:true is required', function (): void {
    kitApp();
    $session = openSession();
    $rev = tool($session, 'cms_apply', ['ops' => sectionOps(), 'basedOn' => $session->state()['revision']])['revision'];
    $example = kitDocument()['types']['react-fancy:Tabs']['example'];

    $flagless = tool($session, 'cms_apply', ['ops' => [islandOp('plans', 'react-fancy:Tabs', $example, 'hero', island: false)], 'basedOn' => $rev]);
    $ok = tool($session, 'cms_apply', ['ops' => [islandOp('plans', 'react-fancy:Tabs', $example, 'hero')], 'basedOn' => $rev]);

    expect($flagless['error']['code'])->toBe('island_invalid')
        ->and($ok)->not->toHaveKey('isError')
        ->and($session->state()['doc']['nodes']['plans']['type'])->toBe('react-fancy:Tabs');
});

test('a type the kit catalogue does not have is refused', function (): void {
    kitApp();
    $session = openSession();
    $rev = $session->state()['revision'];

    $result = tool($session, 'cms_apply', ['ops' => [islandOp('x', 'react-fancy:Shell', ['cmd' => 'ls'])], 'basedOn' => $rev]);

    expect($result['isError'])->toBeTrue()
        ->and($result['error']['code'])->toBeIn(['invalid_operation', 'island_invalid'])
        ->and($session->state()['revision'])->toBe($rev);
});

test('a prop outside the kit schema is refused with its path, though the schema itself leaves the object open', function (string $prop, mixed $value): void {
    kitApp();
    $session = openSession();
    $rev = $session->state()['revision'];
    expect(kitDocument()['types']['react-fancy:Button']['props'])->not->toHaveKey('additionalProperties');

    $result = tool($session, 'cms_apply', ['ops' => [islandOp('cta', 'react-fancy:Button', ['text' => 'Start', $prop => $value])], 'basedOn' => $rev]);

    expect($result['error']['code'])->toBe('island_invalid')
        ->and($result['error']['errors'][0]['path'])->toBe('$.nodes.cta.props.'.$prop)
        ->and($session->state()['revision'])->toBe($rev);
})->with([
    'a handler' => ['onClick', 'alert(1)'],
    'a class' => ['className', 'p-4'],
    'a style' => ['style', ['color' => 'red']],
    'an invented prop' => ['sparkle', true],
]);

test('a prop of the wrong type for the kit schema is refused with its path', function (): void {
    kitApp();
    $session = openSession();

    $result = tool($session, 'cms_apply', ['ops' => [islandOp('cta', 'react-fancy:Button', ['text' => 'Start', 'size' => 'huge'])], 'basedOn' => $session->state()['revision']]);

    expect($result['error']['code'])->toBe('island_invalid')
        ->and($result['error']['errors'][0]['path'])->toBe('$.nodes.cta.props.size');
});

test('an echart option that breaks the safe-subset rules is refused at its path', function (array $option, string $path): void {
    kitApp();
    $session = openSession();
    $rev = $session->state()['revision'];
    $props = ['title' => 'Tax by quarter', 'summary' => 'Tax rose each quarter.', 'option' => $option];

    $result = tool($session, 'cms_apply', ['ops' => [islandOp('tax', 'echart', $props, island: false)], 'basedOn' => $rev]);

    // fancy-cms's own document validation (EchartOption) refuses it first, as
    // invalid_operation; Charlotte's IslandPolicy runs the same validator as
    // island_invalid behind it. Either way it is refused at the option's path.
    $paths = array_column($result['error']['errors'] ?? [], 'path');

    expect($result['isError'] ?? false)->toBeTrue()
        ->and($result['error']['code'])->toBeIn(['invalid_operation', 'island_invalid'])
        ->and(array_filter($paths, fn (string $p) => str_ends_with($p, $path)))->not->toBe([])
        ->and($session->state()['revision'])->toBe($rev);
})->with([
    'a key outside the allow-list' => [['series' => [['type' => 'bar']], 'toolbox' => ['show' => true]], 'props.option.toolbox'],
    'a series type outside the allow-list' => [['series' => [['type' => 'lines3D']]], 'props.option.series[0].type'],
    'a code formatter' => [['tooltip' => ['formatter' => 'function (p) { return p.name }'], 'series' => [['type' => 'bar']]], 'props.option.tooltip.formatter'],
    'markup in a string' => [['title' => ['text' => '<img src=x onerror=alert(1)>'], 'series' => [['type' => 'bar']]], 'props.option.title.text'],
    'rich text' => [['title' => ['text' => 'Tax', 'textStyle' => ['rich' => ['a' => ['color' => 'red']]]], 'series' => [['type' => 'bar']]], 'props.option.title.textStyle.rich'],
])->skip(fn () => ! class_exists(EchartOption::class), 'The installed fancy-cms has no echart node (it ships after 0.9.1).');

test('Charlotte\'s own island policy refuses an unsafe echart option too, island_invalid at its path', function (): void {
    $node = ['id' => 'tax', 'type' => 'echart', 'parent' => null, 'order' => 'a0', 'style' => ['base' => []],
        'props' => ['title' => 'Tax', 'summary' => 'Tax rose.', 'option' => ['series' => [['type' => 'bar']], 'toolbox' => ['show' => true]]]];

    try {
        IslandPolicy::assert(['nodes' => []], ['nodes' => ['tax' => $node]], kitCatalogue(), new EmptyAssetCatalogue);
        $error = null;
    } catch (PageSessionException $e) {
        $error = $e;
    }

    expect($error?->getErrorCode())->toBe('island_invalid')
        ->and($error->getErrors()[0]['path'])->toBe('$.nodes.tax.props.option.toolbox');
})->skip(fn () => ! class_exists(EchartOption::class), 'The installed fancy-cms has no echart node (it ships after 0.9.1).');

test('a valid echart option, with a bound data slot, lands', function (): void {
    kitApp();
    $session = openSession();
    $rev = tool($session, 'cms_apply', ['ops' => sectionOps(), 'basedOn' => $session->state()['revision']])['revision'];
    $props = ['title' => 'Tax by quarter', 'summary' => 'Tax rose each quarter.', 'option' => [
        'dataset' => ['source' => [['quarter', 'tax'], ['Q1', 1200], ['Q2', 1450]]],
        'tooltip' => ['trigger' => 'item', 'formatter' => '{b}: {c}'],
        'xAxis' => ['type' => 'category'], 'yAxis' => ['type' => 'value'], 'series' => [['type' => 'bar', 'encode' => ['x' => 'quarter', 'y' => 'tax']]],
    ]];

    $result = tool($session, 'cms_apply', ['ops' => [islandOp('tax', 'echart', $props, 'hero', island: false)], 'basedOn' => $rev]);

    expect($result)->not->toHaveKey('isError')
        ->and($session->state()['doc']['nodes']['tax']['type'])->toBe('echart');
})->skip(fn () => ! class_exists(EchartOption::class), 'The installed fancy-cms has no echart node (it ships after 0.9.1).');

test('binding the kit does not change the prompt: the catalogue never enters it', function (): void {
    charlotteApp();
    $before = app(ModeRegistry::class)->resolve(BuilderMode::NAME)->systemPrompt;
    kitApp();
    $after = app(ModeRegistry::class)->resolve(BuilderMode::NAME)->systemPrompt;
    $page = app(ModeRegistry::class)->resolve(BuilderMode::PAGE_SUB_NAME)->systemPrompt;

    expect(strlen($after))->toBe(strlen($before))
        ->and($after)->toBe($before)
        ->and($after)->not->toContain('react-fancy:')
        ->and($page)->not->toContain('react-fancy:')
        ->and($after)->toContain('cms_island_schema');
});

test('a scripted run finds a kit component, reads its schema and places it, with no paid call', function (): void {
    $provider = new ScriptedProvider([[
        ['tools' => [['name' => 'cms_describe', 'args' => []]]],
        ['tools' => [['name' => 'cms_island_schema', 'args' => ['types' => ['react-fancy:Accordion']]]]],
        ['tools' => [['name' => 'cms_apply', 'args' => function (array $calls): array {
            $result = fn (string $tool): array => json_decode(array_values(array_filter($calls, fn ($c) => $c['name'] === $tool))[0]['result'], true);

            return ['ops' => [...sectionOps(), islandOp('faq', 'react-fancy:Accordion', $result('cms_island_schema')['types'][0]['example'], 'hero')],
                'basedOn' => $result('cms_describe')['revision']];
        }]]],
        ['text' => 'Placed.'],
    ]]);
    kitApp([], $provider);
    $session = openSession();

    $session->send('Add an FAQ.', 'user-7');

    expect(array_keys($provider->result('cms_describe')['islands']['families']))->toContain('react-fancy')
        ->and(array_column($provider->result('cms_island_schema')['types'], 'type'))->toBe(['react-fancy:Accordion'])
        ->and($provider->result('cms_apply'))->not->toHaveKey('isError')
        ->and($session->state()['doc']['nodes']['faq']['type'])->toBe('react-fancy:Accordion')
        ->and(BuilderMode::TOOLS)->toContain('cms_island_schema');
});

test('charlotte.islands.kit binds fancy-cms\'s kit catalogue, which matches the fixture', function (): void {
    charlotteApp(['charlotte.islands.kit' => true]);

    expect(app(IslandCatalogue::class)->types())->toBe(kitCatalogue()->types());
})->skip(fn () => ! method_exists(FancyIslandCatalogue::class, 'kit'), 'The installed fancy-cms has no kit catalogue (it ships after 0.9.1).');

test('asking for the kit on a fancy-cms without one fails loudly, never quietly falls back to the built-ins', function (): void {
    expect(fn () => CmsDescribeIslands::kit())->toThrow(RuntimeException::class, 'no kit island catalogue');
})->skip(fn () => method_exists(FancyIslandCatalogue::class, 'kit'), 'The installed fancy-cms serves the kit.');
