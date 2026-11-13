<?php

declare(strict_types=1);

use Charlotte\Islands\CmsDescribeIslands;
use Charlotte\Islands\IslandCatalogue;
use Charlotte\Islands\SchemaIslandCatalogue;
use Charlotte\Policy\ArrayAssetCatalogue;
use Charlotte\Policy\AssetCatalogue;

/*
 * Kit islands: the host's catalogue (from cms_describe once fancy-cms
 * publishes one, or bound by the host until then) decides which island types
 * a page may hold and what props each takes. Every island write is checked
 * against its schema, for every actor.
 */

beforeEach(function (): void {
    charlotteApp(['charlotte.autonomy' => 'full']);
    app()->instance(IslandCatalogue::class, SchemaIslandCatalogue::fromArray(kitIslands()));
    app()->instance(AssetCatalogue::class, new ArrayAssetCatalogue([
        'loaf' => ['alt' => 'A sourdough loaf', 'width' => 1200, 'height' => 800],
    ]));
});

function validTabs(): array
{
    return ['tabs' => [['value' => 'monthly', 'label' => 'Monthly', 'body' => ['$19/month']], ['value' => 'yearly', 'label' => 'Yearly']]];
}

test('cms_describe lists the island catalogue by family and how to place it; cms_island_schema gives a type\'s props schema', function (): void {
    $session = openSession();
    $result = tool($session, 'cms_describe');
    $schema = tool($session, 'cms_island_schema', ['types' => ['kit:Tabs']]);

    expect($result['islands']['families'])->toBe(['kit' => ['kit:Tabs' => 'Tabbed panels of text.', 'kit:Gallery' => $result['islands']['families']['kit']['kit:Gallery']]])
        ->and($result['islands']['builtins'])->toBe([])
        ->and($result['islands']['source'])->toBe('host')
        ->and($result['islands']['place'])->toContain('"island":true')
        ->and($result['islands']['place'])->toContain('cms_island_schema')
        ->and($schema['types'][0]['props']['required'])->toBe(['tabs']);
});

test('a valid island lands on the draft', function (): void {
    $session = openSession();
    $rev = tool($session, 'cms_apply', ['ops' => sectionOps(), 'basedOn' => $session->state()['revision']])['revision'];

    $result = tool($session, 'cms_apply', ['ops' => [islandOp('plans', 'kit:Tabs', validTabs(), 'hero')], 'basedOn' => $rev]);

    expect($result)->not->toHaveKey('isError')
        ->and($session->state()['doc']['nodes']['plans']['island'])->toBeTrue();
});

test('island props that break the schema are refused with the exact path', function (array $props, string $path): void {
    $session = openSession();
    $rev = $session->state()['revision'];

    $result = tool($session, 'cms_apply', ['ops' => [islandOp('plans', 'kit:Tabs', $props)], 'basedOn' => $rev]);

    expect($result['error']['code'])->toBe('island_invalid')
        ->and($result['error']['errors'][0]['path'])->toBe($path)
        ->and($session->state()['revision'])->toBe($rev);
})->with([
    'missing required' => [[], '$.nodes.plans.props.tabs'],
    'wrong type' => [['tabs' => 'Monthly'], '$.nodes.plans.props.tabs'],
    'item too long' => [['tabs' => [['value' => 'm', 'label' => str_repeat('x', 61)]]], '$.nodes.plans.props.tabs[0].label'],
    'pattern' => [['tabs' => [['value' => 'BAD VALUE', 'label' => 'x']]], '$.nodes.plans.props.tabs[0].value'],
    'extra prop' => [['tabs' => [['value' => 'm', 'label' => 'M']], 'onClick' => 'alert(1)'], '$.nodes.plans.props.onClick'],
    'too many' => [['tabs' => array_fill(0, 9, ['value' => 'm', 'label' => 'M'])], '$.nodes.plans.props.tabs'],
]);

test('an island type the catalogue does not have is refused', function (): void {
    $session = openSession();

    $result = tool($session, 'cms_apply', ['ops' => [islandOp('x', 'kit:Shell', ['cmd' => 'rm -rf /'])], 'basedOn' => $session->state()['revision']]);

    expect($result['isError'])->toBeTrue()
        ->and($result['error']['code'])->toBe('invalid_operation');
});

test('a catalogue type without island:true, or island:true on a native type, is refused', function (): void {
    $session = openSession();
    $rev = $session->state()['revision'];

    $flagless = tool($session, 'cms_apply', ['ops' => [islandOp('x', 'kit:Tabs', validTabs(), island: false)], 'basedOn' => $rev]);
    $native = tool($session, 'cms_apply', ['ops' => [islandOp('y', 'text', ['content' => 'Hi'])], 'basedOn' => $rev]);

    expect($flagless['error']['code'])->toBe('island_invalid')
        ->and($native['error']['code'])->toBe('island_invalid');
});

test('an island prop cannot carry an image URL: images are host asset handles', function (): void {
    $session = openSession();
    $rev = $session->state()['revision'];

    $url = tool($session, 'cms_apply', ['ops' => [islandOp('g', 'kit:Gallery', ['items' => [['asset' => 'loaf', 'src' => 'https://tracker.example/p.gif']]])], 'basedOn' => $rev]);
    $unknown = tool($session, 'cms_apply', ['ops' => [islandOp('g', 'kit:Gallery', ['items' => [['asset' => 'not-ours']]])], 'basedOn' => $rev]);
    $ok = tool($session, 'cms_apply', ['ops' => [islandOp('g', 'kit:Gallery', ['items' => [['asset' => 'loaf', 'caption' => 'Fresh']]])], 'basedOn' => $rev]);

    expect($url['error']['code'])->toBe('image_source_refused')
        ->and($unknown['error']['code'])->toBe('image_source_refused')
        ->and($ok)->not->toHaveKey('isError');
});

test('an island prop href is held to the same link policy as any other link', function (): void {
    $session = openSession();

    $result = tool($session, 'cms_apply', ['ops' => [islandOp('g', 'kit:Gallery', ['items' => [], 'href' => 'https://phish.example.net/'])], 'basedOn' => $session->state()['revision']]);

    expect($result['error']['code'])->toBe('link_refused');
});

test('a person\'s island edit is held to the schema too', function (): void {
    $session = openSession();
    $rev = $session->state()['revision'];

    expect(fn () => $session->applyHuman([islandOp('plans', 'kit:Tabs', ['tabs' => 'x'])], $rev, 'user-7'))
        ->toThrow(\FancyCms\Exceptions\PageSessionException::class);
});

test('a draft whose island type later leaves the catalogue is still readable, and untouched islands do not block writes', function (): void {
    $session = openSession();
    $rev = tool($session, 'cms_apply', ['ops' => [...sectionOps(), islandOp('plans', 'kit:Tabs', validTabs(), 'hero')], 'basedOn' => $session->state()['revision']])['revision'];

    app()->instance(IslandCatalogue::class, SchemaIslandCatalogue::fromArray([]));

    $get = tool($session, 'cms_get');
    $write = tool($session, 'cms_apply', ['ops' => [textOp('p', 'hero', 'Still editable')], 'basedOn' => $rev]);
    $again = tool($session, 'cms_apply', ['ops' => [islandOp('more', 'kit:Tabs', validTabs(), 'hero', 'a9')], 'basedOn' => $write['revision']]);

    expect($get['doc']['nodes'])->toHaveKey('plans')
        ->and($write)->not->toHaveKey('isError')
        ->and($again['error']['code'])->toBe('island_invalid');
});

test('richtext can never be smuggled in as an island type', function (): void {
    SchemaIslandCatalogue::fromArray([['type' => 'richtext', 'props' => ['type' => 'object']]]);
})->throws(InvalidArgumentException::class);

test('the seam: fancy-cms\'s real {$schema, $defs, types} shape is read; the shapes guessed before it shipped are not', function (): void {
    $real = CmsDescribeIslands::fromDescribe(['islands' => ['$defs' => [], 'types' => ['gauge' => ['description' => 'G', 'props' => ['type' => 'object', 'properties' => ['v' => ['type' => 'number']]]]]]]);
    $map = CmsDescribeIslands::fromDescribe(['islands' => ['kit:Tabs' => ['description' => 'Tabs', 'schema' => kitIslands()[0]['props']]]]);
    $list = CmsDescribeIslands::fromDescribe(['islands' => [['type' => 'kit:Tabs', 'props' => kitIslands()[0]['props']]]]);
    $none = CmsDescribeIslands::fromDescribe(['ops' => []]);

    expect($real->types())->toBe(['gauge'])
        ->and($real->validate('gauge', ['v' => 'x'])[0]['path'])->toBe('$.v')
        ->and($map)->toBeNull()
        ->and($list)->toBeNull()
        ->and($none)->toBeNull();
});

test('with no island catalogue anywhere, cms_describe says so and no island can be placed', function (): void {
    app()->instance(IslandCatalogue::class, SchemaIslandCatalogue::fromArray([]));
    $session = openSession();

    $describe = tool($session, 'cms_describe');
    $place = tool($session, 'cms_apply', ['ops' => [islandOp('plans', 'kit:Tabs', validTabs())], 'basedOn' => $session->state()['revision']]);

    expect($describe['islands']['families'])->toBe([])
        ->and($describe['islands']['count'])->toBe(0)
        ->and($place['isError'])->toBeTrue();
});

test('the default catalogue comes from fancy-cms cms_describe when it has one, else is empty', function (): void {
    $catalogue = CmsDescribeIslands::catalogue();

    expect($catalogue)->toBeInstanceOf(IslandCatalogue::class)
        ->and($catalogue->source())->toBeIn(['fancy-cms', 'none']);
});
