<?php

declare(strict_types=1);

use Charlotte\Facades\Charlotte;
use Charlotte\Guide\BuilderMode;
use Charlotte\Guide\GuideVersion;
use Charlotte\Policy\Refused;
use Charlotte\Tests\Support\ScriptedProvider;
use Charlotte\Tests\Support\TestApp;
use Charlotte\Tests\Support\User;
use Charlotte\Workspace;
use Prism\Harness\Facades\PrismHarness;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\Tools\ToolRegistry;

/*
 * A turn: the person's message is recorded, the run goes to the queue (sync
 * here), the harness streams it through whichever Prism provider is
 * configured, and every step lands in the ordered event log.
 */

/** A turn that reads the brief, builds the hero, then says it is done. */
function buildTurn(): array
{
    return [
        ['text' => 'Reading the brief.', 'tools' => [['name' => 'charlotte_brief', 'args' => []]]],
        ['tools' => [['name' => 'cms_apply', 'args' => fn (array $calls) => [
            'ops' => sectionOps(),
            'basedOn' => json_decode($calls[0]['result'], true)['revision'],
        ]]]],
        ['text' => 'The hero is in place.'],
    ];
}

/** Keep only the listed types, in log order. */
function typesAmong(array $events, array $types): array
{
    return array_values(array_filter(array_column($events, 'type'), fn ($t) => in_array($t, $types, true)));
}

test('a turn runs through the configured provider and its steps stream into the log in order', function (): void {
    $provider = new ScriptedProvider([buildTurn()]);
    charlotteApp(['charlotte.autonomy' => 'full'], $provider);
    $session = openSession();

    $cursor = $session->send('Build the page from the brief.', 'user-7');
    $events = $session->events(0)['events'];

    expect(typesAmong($events, ['message.created', 'run.started', 'tool.call', 'tool.result', 'doc.changed', 'message.completed', 'run.finished']))->toBe([
        'message.created', 'run.started',
        'message.completed', 'tool.call', 'tool.result',
        'tool.call', 'doc.changed', 'tool.result',
        'message.completed', 'run.finished',
    ])->and(array_column(eventsOf($session, 'session.status'), 'data'))->toBe([
        ['status' => 'idle'], ['status' => 'queued'], ['status' => 'running'], ['status' => 'idle'],
    ])->and(eventsOf($session, 'run.started')[0]['data'])->toMatchArray(['mode' => BuilderMode::NAME, 'provider' => 'scripted', 'model' => 'test-model'])
        ->and(eventsOf($session, 'message.created')[0]['seq'])->toBe($cursor)
        ->and($provider->requests)->toHaveCount(1)
        ->and($provider->requests[0]->model())->toBe('test-model')
        ->and($session->state()['doc']['nodes'])->toHaveKeys(['hero', 'hero-h']);
});

test('the person\'s message is recorded as theirs before the run starts', function (): void {
    charlotteApp([], new ScriptedProvider([[['text' => 'Hi.']]]));
    $session = openSession();
    $session->send('Make it warmer.', 'user-7');

    $created = eventsOf($session, 'message.created')[0];

    expect($created['actor'])->toBe(['kind' => 'human', 'id' => 'user-7'])
        ->and($created['data'])->toMatchArray(['role' => 'user', 'text' => 'Make it warmer.']);
});

test('text deltas are coalesced and add up to the completed message', function (): void {
    charlotteApp([], new ScriptedProvider([[['text' => 'Reading the brief carefully before building anything at all.']]]));
    $session = openSession();
    $session->send('Go.', 'user-7');

    $completed = eventsOf($session, 'message.completed')[0]['data'];
    $deltas = array_values(array_filter(eventsOf($session, 'message.delta'), fn ($e) => $e['data']['messageId'] === $completed['messageId']));

    expect(implode('', array_column(array_column($deltas, 'data'), 'text')))->toBe($completed['text'])
        ->and($completed['text'])->toBe('Reading the brief carefully before building anything at all.')
        ->and(count($deltas))->toBeLessThan(15); // 15 raw 4-byte chunks arrived
});

test('tool calls and results are recorded size-capped, with the result\'s code and revision', function (): void {
    charlotteApp(['charlotte.autonomy' => 'full'], new ScriptedProvider([[
        ['tools' => [['name' => 'cms_describe', 'args' => []]]],
        ['tools' => [['name' => 'cms_apply', 'args' => ['ops' => [], 'basedOn' => 'r00000000000000000000000000000000']]]],
    ]]));
    $session = openSession();
    $session->send('Go.', 'user-7');

    [$describe, $stale] = eventsOf($session, 'tool.result');

    expect(strlen($describe['data']['summary']))->toBeLessThanOrEqual(1200)
        ->and($describe['data'])->toMatchArray(['tool' => 'cms_describe', 'ok' => true, 'truncated' => true])
        ->and($stale['data'])->toMatchArray(['tool' => 'cms_apply', 'ok' => false, 'code' => 'surface_changed_under_you']);
});

test('a second message while a turn runs is refused, and nothing is recorded for it', function (): void {
    charlotteApp();
    $session = openSession();
    app(Workspace::class)->status($session->record(), 'running');

    expect(fn () => $session->send('Again', 'user-7'))->toThrow(Refused::class, 'already running');
    expect(eventsOf($session, 'message.created'))->toBe([]);
});

test('stop ends a run within one tool call', function (): void {
    $session = null;
    $provider = new ScriptedProvider([[
        ['tools' => [['name' => 'cms_get', 'args' => []]]],
        ['tools' => [['name' => 'cms_apply', 'args' => function (array $calls) use (&$session): array {
            $session->stop('user-7'); // the person presses Stop mid-run

            return ['ops' => sectionOps(), 'basedOn' => json_decode($calls[0]['result'], true)['revision']];
        }]]],
    ]]);
    charlotteApp(['charlotte.autonomy' => 'full'], $provider);
    $session = openSession();
    $session->send('Go.', 'user-7');

    expect($provider->result('cms_apply')['error']['code'])->toBe('stopped')
        ->and($session->state()['doc']['nodes'])->toBe([])
        ->and($session->record()->status)->toBe('stopped')
        ->and(array_column(eventsOf($session, 'session.status'), 'data'))->toContain(['status' => 'stopping']);
});

test('a new message after a stop clears the stop and runs', function (): void {
    charlotteApp([], new ScriptedProvider([[['tools' => [['name' => 'cms_get', 'args' => []]]]]]));
    $session = openSession();
    $session->stop('user-7');
    $session->send('Carry on.', 'user-7');

    expect($session->record()->stopped())->toBeFalse()
        ->and(TestApp::$provider->result('cms_get'))->not->toHaveKey('isError');
});

test('a failed run is recorded as a code, never as the provider\'s message', function (): void {
    charlotteApp([], new ScriptedProvider([[['throw' => new RuntimeException('401 from https://api.example.test/v1?key=sk-secret-123')]]]));
    $session = openSession();
    $session->send('Go.', 'user-7');

    $failed = eventsOf($session, 'run.failed');
    $log = json_encode($session->events(0));

    expect($failed)->toHaveCount(1)
        ->and($failed[0]['data']['code'])->toBe('run_failed')
        ->and($log)->not->toContain('sk-secret')
        ->and($log)->not->toContain('api.example.test')
        ->and($session->record()->status)->toBe('failed');
});

test('a guide changed after the builder mode was built refuses the run before any provider call', function (): void {
    $provider = new ScriptedProvider([[['text' => 'never']]]);
    charlotteApp([], $provider);
    app(ModeRegistry::class)->resolve(BuilderMode::NAME); // the mode is built now
    Charlotte::guide(fn (GuideVersion $g) => $g->append('Late house style.', 'late'));
    $session = openSession();
    $session->send('Go.', 'user-7');

    expect(eventsOf($session, 'run.failed')[0]['data']['code'])->toBe('guide_mode_stale')
        ->and($provider->requests)->toBe([]);
});

test('a guide override registered before the first turn is what the agent runs with', function (): void {
    $provider = new ScriptedProvider([[['text' => 'ok']]]);
    charlotteApp([], $provider);
    Charlotte::guide(fn (GuideVersion $g) => $g->append('House style: plain words.', 'house'));
    $session = openSession();
    $session->send('Go.', 'user-7');

    $system = implode("\n", array_map(fn ($m) => $m->content, $provider->requests[0]->systemPrompts()));

    expect($system)->toContain('House style: plain words.')
        ->and($session->record()->guide['overrides'][0]['label'])->toBe('house');
});

test('provider and model are per-session configuration', function (): void {
    $provider = new ScriptedProvider([[['text' => 'ok']]]);
    TestApp::boot(['charlotte.provider' => 'scripted', 'charlotte.model' => 'm-default'], $provider);
    $session = Charlotte::for(User::create(['name' => 'Bo']))->tenant('acme')->page('home')
        ->brief(briefArray())->provider('scripted')->model('m-override')->open();
    $session->send('Go.', 'user-7');

    expect($provider->requests[0]->model())->toBe('m-override');
});

test('a builder-mode harness session without Charlotte\'s capability gets no Charlotte tools', function (): void {
    charlotteApp();
    $session = openSession();
    $harness = PrismHarness::session(User::create(['name' => 'Eve']), 'charlotte:'.$session->id())->usingMode(BuilderMode::NAME);

    expect(app(ToolRegistry::class)->resolve(BuilderMode::TOOLS, $harness))->toBe([]);

    // And a capability naming another session's id does not match this scope.
    $harness->usingCapability('charlotte', ['session' => '01JC0000000000000000000000']);
    expect(app(ToolRegistry::class)->resolve(BuilderMode::TOOLS, $harness))->toBe([]);
});
