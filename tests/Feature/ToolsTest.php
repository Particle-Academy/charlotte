<?php

declare(strict_types=1);

use Charlotte\Guide\BuilderMode;
use Charlotte\Policy\ArrayAssetCatalogue;
use Charlotte\Policy\AssetCatalogue;
use FancyCms\Agent;
use FancyCms\Document\PageJson;
use FancyCms\Exceptions\PageSessionException;
use FancyCms\PageSession;

/*
 * Each tool, called exactly as the model calls it (a JSON string back), with
 * its output checked against fancy-cms itself rather than against a copy of
 * Charlotte's own expectations.
 */

beforeEach(fn () => charlotteApp(['charlotte.autonomy' => 'full']));

function jsonRoundTrip(mixed $value): mixed
{
    return json_decode(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);
}

test('the builder mode offers exactly the nine Charlotte tools', function (): void {
    $names = array_map(fn ($t) => $t->name(), openSession()->tools()->prismTools());

    expect($names)->toBe(BuilderMode::TOOLS);
});

test('cms_describe returns the fancy-cms catalogue at the current revision', function (): void {
    $session = openSession();
    $result = tool($session, 'cms_describe');

    expect($result['catalogue'])->toBe(jsonRoundTrip(Agent::describe()))
        ->and($result['revision'])->toBe($session->state()['revision']);
});

test('cms_icons pages through the fancy-cms icon catalogue', function (): void {
    $result = tool(openSession(), 'cms_icons', ['query' => 'arrow', 'cursor' => 0]);

    expect($result['catalogue'])->toBe(jsonRoundTrip(Agent::icons('arrow', 0)));
});

test('cms_assets lists the host catalogue and never a URL', function (): void {
    app()->instance(AssetCatalogue::class, new ArrayAssetCatalogue([
        'hero-truck' => ['alt' => 'A food truck at dusk', 'width' => 1600, 'height' => 1067, 'src' => '/private/hero.jpg'],
    ]));
    $result = tool(openSession(), 'cms_assets');

    expect($result['catalogue'])->toBe([['handle' => 'hero-truck', 'alt' => 'A food truck at dusk', 'width' => 1600, 'height' => 1067]])
        ->and(json_encode($result))->not->toContain('/private/hero.jpg');
});

test('cms_apply applies the ops to the draft exactly as fancy-cms would', function (): void {
    $session = openSession();
    $before = $session->state();
    $ops = [...sectionOps(), textOp('price', 'hero', 'Starter plan: $19/month')];

    $result = tool($session, 'cms_apply', ['ops' => $ops, 'basedOn' => $before['revision']]);
    $after = $session->state();

    // The same ops through a bare fancy-cms PageSession give the same document.
    $reference = new PageSession($before['doc'], array_values(array_diff(\FancyCms\Document\PageDoc::NODE_TYPES, ['richtext'])));
    $expected = $reference->apply($ops, 'agent')['doc'];

    expect($result)->toBe(['revision' => $after['revision'], 'applied' => 3])
        ->and($after['revision'])->not->toBe($before['revision'])
        ->and(PageJson::encodeDocument($after['doc']))->toBe(PageJson::encodeDocument($expected));
});

test('cms_get returns the draft and keeps fancy-cms wire records intact', function (): void {
    $session = openSession();
    tool($session, 'cms_apply', ['ops' => sectionOps(), 'basedOn' => $session->state()['revision']]);
    $raw = $session->tools()->call('cms_get');
    $result = json_decode($raw, true);

    expect($result['revision'])->toBe($session->state()['revision'])
        ->and(array_keys($result['doc']['nodes']))->toBe(['hero', 'hero-h'])
        ->and($raw)->toContain('"props":{}')
        ->and($result['staged'])->toBeFalse();
});

test('cms_changes_since reports a person\'s edits with attribution', function (): void {
    $session = openSession();
    $r1 = tool($session, 'cms_apply', ['ops' => sectionOps(), 'basedOn' => $session->state()['revision']])['revision'];
    $session->applyHuman([textOp('note', 'hero', 'Added by a person')], $r1, 'user-7');

    $changes = tool($session, 'cms_changes_since', ['since' => $r1]);

    expect($changes['complete'])->toBeTrue()
        ->and($changes['changes'])->toBe([['handle' => 'node:note', 'change' => 'created', 'actor' => 'human']]);
});

test('a write pinned to a stale revision is refused as surface_changed_under_you', function (): void {
    $session = openSession();
    $r0 = $session->state()['revision'];
    tool($session, 'cms_apply', ['ops' => sectionOps(), 'basedOn' => $r0]);

    $result = tool($session, 'cms_apply', ['ops' => [textOp('x', 'hero', 'late')], 'basedOn' => $r0]);

    expect($result['isError'])->toBeTrue()
        ->and($result['error']['code'])->toBe('surface_changed_under_you')
        ->and($result['revision'])->toBe($session->state()['revision']);
});

test('richtext is refused for the agent and for a person', function (): void {
    $session = openSession();
    $rev = $session->state()['revision'];
    $richtext = [['t' => 'insert_node', 'node' => ['id' => 'r', 'type' => 'richtext', 'parent' => null, 'order' => 'a0', 'props' => ['html' => '<img src=x onerror=alert(1)>'], 'style' => ['base' => []]]]];

    expect(tool($session, 'cms_apply', ['ops' => $richtext, 'basedOn' => $rev])['error']['code'])->toBe('invalid_operation');
    expect(fn () => $session->applyHuman($richtext, $rev, 'user-7'))->toThrow(PageSessionException::class);
    expect($session->state()['revision'])->toBe($rev);
});

test('arguments are closed: no extra field, no missing field, and identity is never an argument', function (array $arguments): void {
    $session = openSession();
    $result = tool($session, 'cms_apply', $arguments);

    expect($result['error']['code'])->toBe('invalid_request')
        ->and($session->state()['doc']['nodes'])->toBe([]);
})->with([
    'actor smuggled in' => [['ops' => [], 'basedOn' => 'r0', 'actor' => 'human']],
    'page smuggled in' => [['ops' => [], 'basedOn' => 'r0', 'page' => 'other']],
    'basedOn missing' => [['ops' => []]],
    'ops not a list' => [['ops' => 'drop table', 'basedOn' => 'r0']],
]);

test('an unknown tool is a programming error, not a model-facing result', function (): void {
    openSession()->tools()->call('cms_publish');
})->throws(InvalidArgumentException::class);

test('charlotte_brief returns the structured brief as data', function (): void {
    $session = openSession();
    $result = tool($session, 'charlotte_brief');

    expect($result['brief'])->toBe(\Charlotte\Brief\Brief::fromArray(briefArray())->toArray())
        ->and($result['revision'])->toBe($session->state()['revision']);
});

test('cms_check reports page facts and catches an invented price and a missing fact', function (): void {
    $session = openSession();
    $rev = tool($session, 'cms_apply', ['ops' => [...sectionOps(), textOp('price', 'hero', 'Pro plan: $29/month')], 'basedOn' => $session->state()['revision']])['revision'];

    $result = tool($session, 'cms_check', ['basedOn' => $rev]);
    $codes = array_column($result['defects'], 'code');

    expect($result['revision'])->toBe($rev)
        ->and($codes)->toContain('unsupported_claim')
        ->and($codes)->toContain('missing_required_fact')
        ->and($result['facts']['outline'])->toBe([['node' => 'hero-h', 'level' => 1, 'text' => 'Acme Ledger']])
        ->and($result['summary']['errors'])->toBeGreaterThan(0)
        ->and(eventsOf($session, 'checks.result'))->toHaveCount(1);
});

test('cms_finish marks the draft ready, runs the checks and does not publish', function (): void {
    $session = openSession();
    $rev = tool($session, 'cms_apply', ['ops' => sectionOps(), 'basedOn' => $session->state()['revision']])['revision'];

    $result = tool($session, 'cms_finish', ['basedOn' => $rev]);
    $state = $session->state();

    expect($result['ready'])->toBeTrue()
        ->and($result['revision'])->toBe($rev)
        ->and($state['readyRevision'])->toBe($rev)
        ->and($state['published'])->toBeNull()
        ->and(eventsOf($session, 'draft.ready')[0]['data']['revision'])->toBe($rev)
        ->and(eventsOf($session, 'checks.result')[0]['data']['revision'])->toBe($rev);
});

test('an authored image URL is refused and the draft is unchanged', function (): void {
    $session = openSession();
    $rev = $session->state()['revision'];
    $image = [['t' => 'insert_node', 'node' => ['id' => 'i', 'type' => 'image', 'parent' => null, 'order' => 'a0', 'props' => ['src' => 'https://tracker.example/p.gif', 'alt' => 'x'], 'style' => ['base' => []]]]];

    expect(tool($session, 'cms_apply', ['ops' => $image, 'basedOn' => $rev])['error']['code'])->toBe('image_source_refused')
        ->and($session->state()['revision'])->toBe($rev)
        ->and(eventsOf($session, 'doc.changed'))->toBe([]);
});

test('an agent link to an origin the host did not allowlist is refused', function (): void {
    $session = openSession();
    $rev = $session->state()['revision'];
    $link = [['t' => 'insert_node', 'node' => ['id' => 'l', 'type' => 'link', 'parent' => null, 'order' => 'a0', 'props' => ['content' => 'Win', 'href' => 'https://phish.example.net/'], 'style' => ['base' => []]]]];

    expect(tool($session, 'cms_apply', ['ops' => $link, 'basedOn' => $rev])['error']['code'])->toBe('link_refused')
        ->and($session->state()['revision'])->toBe($rev);
});

test('once a person stops the session every tool refuses with stopped', function (): void {
    $session = openSession();
    $session->stop('user-7');

    foreach (['cms_get', 'cms_describe', 'charlotte_brief'] as $name) {
        expect(tool($session, $name)['error']['code'])->toBe('stopped');
    }
});
