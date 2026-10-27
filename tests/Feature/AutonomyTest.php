<?php

declare(strict_types=1);

use Charlotte\Policy\Refused;

/*
 * Autonomy is a dev setting:
 * - full: the agent's edits land on the draft at once.
 * - approve_on_save: they collect as a pending change set that a person
 *   approves (saved to the draft) or rejects (discarded).
 */

test('full autonomy applies each batch to the draft immediately', function (): void {
    charlotteApp(['charlotte.autonomy' => 'full']);
    $session = openSession();
    $r0 = $session->state()['revision'];

    $result = tool($session, 'cms_apply', ['ops' => sectionOps(), 'basedOn' => $r0]);
    $changed = eventsOf($session, 'doc.changed');

    expect($result)->not->toHaveKey('staged')
        ->and($session->state()['doc']['nodes'])->toHaveKeys(['hero', 'hero-h'])
        ->and($changed)->toHaveCount(1)
        ->and($changed[0]['actor'])->toBe(['kind' => 'agent', 'id' => 'charlotte'])
        ->and($changed[0]['data'])->toMatchArray(['revision' => $result['revision'], 'previousRevision' => $r0, 'opCount' => 2])
        ->and($changed[0]['data']['ops'])->toBe(json_decode(json_encode(sectionOps()), true))
        ->and(eventsOf($session, 'proposal.created'))->toBe([]);
});

test('approve_on_save stages the batch; the draft does not move', function (): void {
    charlotteApp(['charlotte.autonomy' => 'approve_on_save']);
    $session = openSession();
    $r0 = $session->state()['revision'];

    $first = tool($session, 'cms_apply', ['ops' => sectionOps(), 'basedOn' => $r0]);
    $second = tool($session, 'cms_apply', ['ops' => sectionOps('about', 'a1', 'About', 2), 'basedOn' => $first['revision']]);
    $view = tool($session, 'cms_get');
    $state = $session->state();

    expect($first['staged'])->toBeTrue()
        ->and($second['proposalId'])->toBe($first['proposalId'])
        ->and($state['revision'])->toBe($r0)
        ->and($state['doc']['nodes'])->toBe([])
        ->and($view['staged'])->toBeTrue()
        ->and($view['revision'])->toBe($second['revision'])
        ->and(array_keys($view['doc']['nodes']))->toBe(['hero', 'hero-h', 'about', 'about-h'])
        ->and($state['proposal'])->toMatchArray(['id' => $first['proposalId'], 'baseRevision' => $r0, 'opCount' => 4])
        ->and(array_column(eventsOf($session, 'proposal.created'), 'data'))->toHaveCount(1)
        ->and(eventsOf($session, 'proposal.updated')[0]['data']['opCount'])->toBe(4)
        ->and(eventsOf($session, 'doc.changed'))->toBe([]);
});

test('approving a change set saves it to the draft and tells the agent', function (): void {
    charlotteApp(['charlotte.autonomy' => 'approve_on_save']);
    $session = openSession();
    $staged = tool($session, 'cms_apply', ['ops' => sectionOps(), 'basedOn' => $session->state()['revision']]);

    $resolved = $session->approve($staged['proposalId'], 'user-7');
    $state = $session->state();
    $resolution = eventsOf($session, 'proposal.resolved')[0];
    $next = tool($session, 'cms_get');

    expect($resolved)->toBe(['state' => 'accepted', 'revision' => $state['revision']])
        ->and($state['doc']['nodes'])->toHaveKeys(['hero', 'hero-h'])
        ->and($state['proposal'])->toBeNull()
        ->and($resolution['actor'])->toBe(['kind' => 'human', 'id' => 'user-7'])
        ->and($resolution['data'])->toMatchArray(['proposalId' => $staged['proposalId'], 'state' => 'accepted'])
        ->and(eventsOf($session, 'doc.changed')[0]['actor'])->toBe(['kind' => 'agent', 'id' => 'charlotte'])
        ->and($next['staged'])->toBeFalse()
        ->and($next['notices'])->toBe([['proposalId' => $staged['proposalId'], 'state' => 'accepted']]);

    // A notice is delivered once.
    expect(tool($session, 'cms_get'))->not->toHaveKey('notices');
});

test('rejecting a change set discards it; the draft is untouched and the agent is told', function (): void {
    charlotteApp(['charlotte.autonomy' => 'approve_on_save']);
    $session = openSession();
    $r0 = $session->state()['revision'];
    $staged = tool($session, 'cms_apply', ['ops' => sectionOps(), 'basedOn' => $r0]);

    $resolved = $session->reject($staged['proposalId'], 'user-7');
    $next = tool($session, 'cms_get');

    expect($resolved)->toBe(['state' => 'rejected', 'revision' => $r0])
        ->and($session->state()['revision'])->toBe($r0)
        ->and($session->state()['doc']['nodes'])->toBe([])
        ->and($next['doc']['nodes'])->toBe([])
        ->and($next['notices'])->toBe([['proposalId' => $staged['proposalId'], 'state' => 'rejected']])
        ->and(eventsOf($session, 'proposal.resolved')[0]['data']['state'])->toBe('rejected')
        ->and(eventsOf($session, 'doc.changed'))->toBe([]);
});

test('after a rejection the next batch opens a new change set', function (): void {
    charlotteApp(['charlotte.autonomy' => 'approve_on_save']);
    $session = openSession();
    $first = tool($session, 'cms_apply', ['ops' => sectionOps(), 'basedOn' => $session->state()['revision']]);
    $session->reject($first['proposalId'], 'user-7');

    $second = tool($session, 'cms_apply', ['ops' => sectionOps('about', 'a1', 'About', 1), 'basedOn' => $session->state()['revision']]);

    expect($second['proposalId'])->not->toBe($first['proposalId']);
});

test('a person\'s edit made while a change set waits is kept when the change set is approved', function (): void {
    charlotteApp(['charlotte.autonomy' => 'approve_on_save']);
    $session = openSession();
    $r0 = $session->state()['revision'];
    $staged = tool($session, 'cms_apply', ['ops' => sectionOps(), 'basedOn' => $r0]);
    $session->applyHuman(sectionOps('contact', 'a5', 'Contact', 2), $r0, 'user-7');

    expect($session->approve($staged['proposalId'], 'user-7')['state'])->toBe('accepted')
        ->and($session->state()['doc']['nodes'])->toHaveKeys(['hero', 'hero-h', 'contact', 'contact-h']);
});

test('a change set that no longer applies resolves as conflicted and writes nothing', function (): void {
    charlotteApp(['charlotte.autonomy' => 'approve_on_save']);
    $session = openSession();
    $base = $session->applyHuman(sectionOps('about', 'a0', 'About us', 1), $session->state()['revision'], 'user-7')['revision'];
    $staged = tool($session, 'cms_apply', ['ops' => [['t' => 'set_node_props', 'id' => 'about-h', 'patch' => ['content' => 'About Acme Ledger']]], 'basedOn' => $base]);
    $humanRev = $session->applyHuman([['t' => 'remove_node', 'id' => 'about-h']], $base, 'user-7')['revision'];

    $resolved = $session->approve($staged['proposalId'], 'user-7');

    expect($resolved)->toBe(['state' => 'conflicted', 'revision' => $humanRev])
        ->and($session->state()['revision'])->toBe($humanRev)
        ->and($session->state()['doc']['nodes'])->not->toHaveKey('about-h')
        ->and(eventsOf($session, 'proposal.resolved')[0]['data']['state'])->toBe('conflicted');
});

test('a change set resolves once, and only from its own session', function (): void {
    charlotteApp(['charlotte.autonomy' => 'approve_on_save']);
    $session = openSession();
    $other = openSession(['page' => 'pricing']);
    $staged = tool($session, 'cms_apply', ['ops' => sectionOps(), 'basedOn' => $session->state()['revision']]);

    $reason = function (Closure $fn): ?string {
        try {
            $fn();

            return null;
        } catch (Refused $e) {
            return $e->reason;
        }
    };

    expect($reason(fn () => $other->approve($staged['proposalId'], 'user-7')))->toBe('proposal_missing')
        ->and($reason(fn () => $session->approve('01JC000000000000000000NONE', 'user-7')))->toBe('proposal_missing');
    $session->reject($staged['proposalId'], 'user-7');
    expect($reason(fn () => $session->approve($staged['proposalId'], 'user-7')))->toBe('proposal_resolved');
});

test('the autonomy is chosen per session, and an unknown value is refused', function (): void {
    charlotteApp(['charlotte.autonomy' => 'approve_on_save']);

    expect(openSession(['autonomy' => 'full'])->state()['session']['autonomy'])->toBe('full')
        ->and(openSession()->state()['session']['autonomy'])->toBe('approve_on_save');
    expect(fn () => openSession(['autonomy' => 'yolo']))->toThrow(ValueError::class);
});
