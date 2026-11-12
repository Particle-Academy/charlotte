<?php

declare(strict_types=1);

/*
 * An agent write of a stack or grid without `layout` is refused (Prism
 * maintainers, on flabs runs 423-463: fancy-cms renders such a node as a
 * plain block, and 18 of 23 lab pages shipped that way). No default is
 * filled in: the agent states the layout. Human writes are not refused; the
 * cms_finish gate still reports what a person leaves.
 */

beforeEach(function (): void {
    charlotteApp(['charlotte.autonomy' => 'full']);
});

function containerOp(string $id, string $type, ?string $layout, ?string $parent = null, string $order = 'a0'): array
{
    $node = ['id' => $id, 'type' => $type, 'parent' => $parent, 'order' => $order, 'props' => [], 'style' => ['base' => ['gap' => ['value' => 16, 'unit' => 'px']]]];
    if ($layout !== null) {
        $node['layout'] = $layout;
    }

    return ['t' => 'insert_node', 'node' => $node];
}

test('a stack or grid written without layout is refused, naming the node and the allowed values', function (string $type): void {
    $session = openSession();
    $before = $session->state()['revision'];

    $result = tool($session, 'cms_apply', ['ops' => [containerOp('row', $type, null)], 'basedOn' => $before]);

    expect($result['isError'])->toBeTrue()
        ->and($result['error']['code'])->toBe('layout_required')
        ->and($result['error']['errors'][0]['path'])->toBe('$.nodes.row.layout')
        ->and($result['error']['errors'][0]['expected'])->toContain('"stack"')->toContain('"grid"')->toContain('"free"')
        ->and($result['error']['errors'][0]['got'])->toBe('missing')
        ->and($session->state()['revision'])->toBe($before);
})->with(['stack', 'grid']);

test('the same nodes with a layout are accepted, and nothing is filled in for them', function (): void {
    $session = openSession();

    $result = tool($session, 'cms_apply', ['ops' => [containerOp('row', 'stack', 'stack'), containerOp('cols', 'grid', 'grid', 'row')], 'basedOn' => $session->state()['revision']]);
    $nodes = json_decode(json_encode($session->state()['doc']), true)['nodes'];

    expect($result['isError'] ?? false)->toBeFalse()
        ->and($nodes['row']['layout'])->toBe('stack')
        ->and($nodes['cols']['layout'])->toBe('grid');
});

test('a set_layout in the same batch satisfies it; clearing a stack\'s layout is refused', function (): void {
    $session = openSession();
    $rev = tool($session, 'cms_apply', ['ops' => [containerOp('row', 'stack', null), ['t' => 'set_layout', 'id' => 'row', 'layout' => 'stack']],
        'basedOn' => $session->state()['revision']])['revision'];

    $cleared = tool($session, 'cms_apply', ['ops' => [['t' => 'set_layout', 'id' => 'row']], 'basedOn' => $rev]);

    expect($cleared['error']['code'])->toBe('layout_required')
        ->and($cleared['error']['errors'][0]['path'])->toBe('$.nodes.row.layout');
});

test('sections, cards and text need no layout', function (): void {
    $session = openSession();

    $result = tool($session, 'cms_apply', ['ops' => [containerOp('s', 'section', null), containerOp('c', 'card', null, 's'), textOp('t', 'c', 'Hello')],
        'basedOn' => $session->state()['revision']]);

    expect($result['isError'] ?? false)->toBeFalse();
});

test('under approve_on_save the staged write is refused the same way', function (): void {
    charlotteApp(['charlotte.autonomy' => 'approve_on_save']);
    $session = openSession();

    $result = tool($session, 'cms_apply', ['ops' => [containerOp('row', 'grid', null)], 'basedOn' => $session->state()['revision']]);

    expect($result['error']['code'])->toBe('layout_required')
        ->and($session->state()['proposal'])->toBeNull();
});

test('a person may write a layout-less stack; an agent edit elsewhere still lands, but editing that stack needs its layout', function (): void {
    $session = openSession();
    $rev = $session->applyHuman([containerOp('row', 'stack', null), containerOp('other', 'section', null, null, 'a1')], $session->state()['revision'], 'user-7')['revision'];

    $elsewhere = tool($session, 'cms_apply', ['ops' => [textOp('t', 'other', 'Hello', 'a0')], 'basedOn' => $rev]);
    $touched = tool($session, 'cms_apply', ['ops' => [['t' => 'set_node_props', 'id' => 'row', 'patch' => ['anchor' => 'top']]], 'basedOn' => $elsewhere['revision']]);

    expect($elsewhere['isError'] ?? false)->toBeFalse()
        ->and($touched['error']['code'])->toBe('layout_required');
});
