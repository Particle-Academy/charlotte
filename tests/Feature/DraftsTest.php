<?php

declare(strict_types=1);

use Charlotte\Drafts\PageRepository;
use Charlotte\Guide\BuilderMode;
use FancyCms\Exceptions\PageSessionException;

/*
 * Charlotte works on a DRAFT. Publishing is an explicit host action; nothing
 * the agent can do reaches the live page.
 */

test('the agent\'s work, including finishing, never publishes', function (string $autonomy): void {
    charlotteApp(['charlotte.autonomy' => $autonomy]);
    $session = openSession();
    $rev = tool($session, 'cms_apply', ['ops' => sectionOps(), 'basedOn' => $session->state()['revision']])['revision'];
    tool($session, 'cms_finish', ['basedOn' => $rev]);

    expect(app(PageRepository::class)->published('acme', 'home'))->toBeNull()
        ->and($session->state()['published'])->toBeNull()
        ->and(eventsOf($session, 'draft.published'))->toBe([]);
})->with(['full', 'approve_on_save']);

test('no tool can publish', function (): void {
    charlotteApp();
    foreach (BuilderMode::TOOLS as $name) {
        expect($name)->not->toContain('publish');
    }
    expect(fn () => openSession()->tools()->call('cms_publish', []))->toThrow(InvalidArgumentException::class);
});

test('publish is an explicit host action that copies the draft at a pinned revision', function (): void {
    charlotteApp(['charlotte.autonomy' => 'full']);
    $session = openSession();
    $rev = tool($session, 'cms_apply', ['ops' => sectionOps(), 'basedOn' => $session->state()['revision']])['revision'];

    $result = $session->publish($rev, 'user-7');
    $published = app(PageRepository::class)->published('acme', 'home');

    expect($result)->toBe(['revision' => $rev, 'published' => true])
        ->and($published['revision'])->toBe($rev)
        ->and($published['doc'])->toEqual($session->state()['doc'])
        ->and($published['by'])->toBe('user-7')
        ->and(eventsOf($session, 'draft.published')[0])->toMatchArray(['actor' => ['kind' => 'human', 'id' => 'user-7'], 'data' => ['revision' => $rev]]);

    // Later agent edits change the draft, never the published copy.
    tool($session, 'cms_apply', ['ops' => [textOp('more', 'hero', 'More')], 'basedOn' => $rev]);
    expect(app(PageRepository::class)->published('acme', 'home')['revision'])->toBe($rev)
        ->and($session->state()['revision'])->not->toBe($rev);
});

test('publishing a stale revision is refused and publishes nothing', function (): void {
    charlotteApp(['charlotte.autonomy' => 'full']);
    $session = openSession();
    $r0 = $session->state()['revision'];
    tool($session, 'cms_apply', ['ops' => sectionOps(), 'basedOn' => $r0]);

    try {
        $session->publish($r0, 'user-7');
        test()->fail('Expected a stale publish to be refused.');
    } catch (PageSessionException $e) {
        expect($e->getErrorCode())->toBe('surface_changed_under_you');
    }
    expect(app(PageRepository::class)->published('acme', 'home'))->toBeNull();
});

test('two sessions on the same page share one draft', function (): void {
    charlotteApp(['charlotte.autonomy' => 'full']);
    $a = openSession();
    $rev = tool($a, 'cms_apply', ['ops' => sectionOps(), 'basedOn' => $a->state()['revision']])['revision'];
    $b = openSession();

    expect($b->state()['revision'])->toBe($rev)
        ->and($b->id())->not->toBe($a->id());
});

test('tenants are isolated: the same page key under another tenant is another draft', function (): void {
    charlotteApp(['charlotte.autonomy' => 'full']);
    $a = openSession(['tenant' => 'acme']);
    tool($a, 'cms_apply', ['ops' => sectionOps(), 'basedOn' => $a->state()['revision']]);
    $b = openSession(['tenant' => 'globex']);

    expect($b->state()['doc']['nodes'])->toBe([]);
});
