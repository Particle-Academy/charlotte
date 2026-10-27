<?php

declare(strict_types=1);

use Charlotte\Drafts\PageRepository;
use Charlotte\Facades\Charlotte;
use Charlotte\Tests\Support\ScriptedProvider;
use Charlotte\Tests\Support\User;
use Illuminate\Http\Request;

/*
 * The optional Laravel controller kit, driven through the real router. Every
 * route asks the dev-supplied authorization hook; with none, everything is
 * refused. Tenancy comes from the host's resolver.
 */

/** @return array{status: int, body: array, raw: string} */
function http(string $method, string $uri, ?array $body = null, ?User $user = null, string $tenant = 'acme', ?string $raw = null): array
{
    $content = $raw ?? ($body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));
    $request = Request::create('/charlotte'.$uri, $method, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_TENANT' => $tenant], $content);
    app()->instance('request', $request); // what the HTTP kernel does before routing
    // After binding: the auth provider's rebinding hook replaces the resolver.
    $request->setUserResolver(fn () => $user);
    $response = app('router')->dispatch($request);

    return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true) ?? [], 'raw' => (string) $response->getContent()];
}

/** Boot with the routes on, tenancy from a header, and an optional hook. */
function httpApp(?Closure $authorize = null, array $config = [], ?ScriptedProvider $provider = null): void
{
    charlotteApp($config, $provider);
    Charlotte::resolveTenantUsing(fn (Request $request): ?string => $request->header('X-Tenant') ?: null);
    if ($authorize !== null) {
        Charlotte::authorizeUsing($authorize);
    }
    Charlotte::routes(['middleware' => []]);
}

dataset('session routes', [
    'state' => ['GET', ''],
    'events' => ['GET', '/events?after=0&wait=0'],
    'message' => ['POST', '/messages', ['text' => 'hi']],
    'stop' => ['POST', '/stop', []],
    'human ops' => ['POST', '/ops', ['ops' => [], 'basedOn' => 'r0']],
    'accept' => ['POST', '/proposals/01JC0000000000000000000000/accept', []],
    'reject' => ['POST', '/proposals/01JC0000000000000000000000/reject', []],
    'publish' => ['POST', '/publish', ['basedOn' => 'r0']],
]);

test('with no authorization hook, opening a session is refused', function (): void {
    httpApp();

    $response = http('POST', '/sessions', ['page' => 'home', 'brief' => briefArray()], User::create(['name' => 'Ada']));

    expect($response['status'])->toBe(403)
        ->and($response['body']['error']['code'])->toBe('forbidden');
});

test('with no authorization hook, every session route is refused', function (string $method, string $path, ?array $body = null): void {
    httpApp();
    $session = openSession();

    $response = http($method, '/sessions/'.$session->id().$path, $body, User::create(['name' => 'Ada']));

    expect($response['status'])->toBe(403)
        ->and($response['body']['error']['code'])->toBe('forbidden');
})->with('session routes');

test('the hook is asked for the ability, with the tenant, page and session as subject', function (): void {
    $asked = [];
    httpApp(function (mixed $user, string $ability, array $subject) use (&$asked): bool {
        $asked[] = [$ability, $subject];

        return true;
    });
    $user = User::create(['name' => 'Ada']);

    $opened = http('POST', '/sessions', ['page' => 'home', 'brief' => briefArray()], $user);
    http('GET', '/sessions/'.$opened['body']['session'], null, $user);

    expect($opened['status'])->toBe(201)
        ->and($asked[0])->toBe(['open', ['tenant' => 'acme', 'page' => 'home', 'session' => null]])
        ->and($asked[1])->toBe(['view', ['tenant' => 'acme', 'page' => 'home', 'session' => $opened['body']['session']]]);
});

test('a refused ability is refused, and only that one', function (): void {
    httpApp(fn (mixed $user, string $ability): bool => $ability !== 'publish');
    $user = User::create(['name' => 'Ada']);
    $session = openSession();

    expect(http('POST', '/sessions/'.$session->id().'/publish', ['basedOn' => $session->state()['revision']], $user)['status'])->toBe(403)
        ->and(http('GET', '/sessions/'.$session->id(), null, $user)['status'])->toBe(200)
        ->and(app(PageRepository::class)->published('acme', 'home'))->toBeNull();
});

test('a guest is refused before the hook is asked', function (): void {
    $asked = false;
    httpApp(function () use (&$asked): bool {
        $asked = true;

        return true;
    });

    expect(http('POST', '/sessions', ['page' => 'home', 'brief' => briefArray()])['status'])->toBe(403)
        ->and($asked)->toBeFalse();
});

test('a session in another tenant is not found, on every route', function (string $method, string $path, ?array $body = null): void {
    httpApp(fn (): bool => true);
    $session = openSession(['tenant' => 'acme']);

    $response = http($method, '/sessions/'.$session->id().$path, $body, User::create(['name' => 'Ada']), tenant: 'globex');

    expect($response['status'])->toBe(404)
        ->and($response['body']['error']['code'])->toBe('not_found');
})->with('session routes');

test('a request whose tenant the host cannot resolve is refused', function (): void {
    httpApp(fn (): bool => true);

    $response = http('POST', '/sessions', ['page' => 'home', 'brief' => briefArray()], User::create(['name' => 'Ada']), tenant: '');

    expect($response['status'])->toBe(403)
        ->and($response['body']['error']['code'])->toBe('tenant_unresolved');
});

test('a chat needs nothing but fetch: open, send, then long-poll from a cursor', function (): void {
    httpApp(fn (): bool => true, ['charlotte.autonomy' => 'full'], new ScriptedProvider([[['text' => 'Building the hero now.']]]));
    $user = User::create(['name' => 'Ada']);

    $opened = http('POST', '/sessions', ['page' => 'home', 'brief' => briefArray()], $user);
    $id = $opened['body']['session'];
    $sent = http('POST', "/sessions/{$id}/messages", ['text' => 'Build the page.'], $user);
    $all = http('GET', "/sessions/{$id}/events?after={$opened['body']['cursor']}&wait=0", null, $user);
    $resumed = http('GET', "/sessions/{$id}/events?after={$sent['body']['cursor']}&wait=0", null, $user);
    $tail = http('GET', "/sessions/{$id}/events?after={$all['body']['cursor']}&wait=0", null, $user);

    expect($sent['status'])->toBe(202)
        ->and(array_column($all['body']['events'], 'type'))->toContain('message.created', 'message.completed', 'run.finished')
        ->and($all['body']['events'][0]['seq'])->toBe($opened['body']['cursor'] + 1)
        ->and($resumed['body']['events'][0]['seq'])->toBe($sent['body']['cursor'] + 1)
        ->and($tail['body'])->toBe(['events' => [], 'cursor' => $all['body']['cursor']]);
});

test('empty event data is a JSON object on the wire', function (): void {
    httpApp(fn (): bool => true);
    $session = openSession();
    app(\Charlotte\Workspace::class)->record($session->id(), 'future.thing', ['kind' => 'system', 'id' => 'charlotte'], []);

    $raw = http('GET', '/sessions/'.$session->id().'/events?after=1&wait=0', null, User::create(['name' => 'Ada']))['raw'];

    expect($raw)->toContain('"data":{}');
});

test('state carries the draft with fancy-cms wire records', function (): void {
    httpApp(fn (): bool => true, ['charlotte.autonomy' => 'full']);
    $session = openSession();
    tool($session, 'cms_apply', ['ops' => sectionOps(), 'basedOn' => $session->state()['revision']]);

    $response = http('GET', '/sessions/'.$session->id(), null, User::create(['name' => 'Ada']));

    expect($response['status'])->toBe(200)
        ->and($response['body']['revision'])->toBe($session->state()['revision'])
        ->and($response['raw'])->toContain('"props":{}');
});

test('a person\'s stale edit gets 409 with a snapshot to rebase on', function (): void {
    httpApp(fn (): bool => true, ['charlotte.autonomy' => 'full']);
    $session = openSession();
    $r0 = $session->state()['revision'];
    tool($session, 'cms_apply', ['ops' => sectionOps(), 'basedOn' => $r0]);

    $response = http('POST', '/sessions/'.$session->id().'/ops', ['ops' => [textOp('n', 'hero', 'Mine')], 'basedOn' => $r0], User::create(['name' => 'Ada']));

    expect($response['status'])->toBe(409)
        ->and($response['body']['error']['code'])->toBe('surface_changed_under_you')
        ->and($response['body']['snapshot']['revision'])->toBe($session->state()['revision'])
        ->and($response['body']['snapshot']['doc']['nodes'])->toHaveKeys(['hero', 'hero-h']);
});

test('a person\'s edit is attributed to them', function (): void {
    httpApp(fn (): bool => true);
    $session = openSession();
    $user = User::create(['name' => 'Ada']);

    $response = http('POST', '/sessions/'.$session->id().'/ops', ['ops' => sectionOps(), 'basedOn' => $session->state()['revision']], $user);

    expect($response['status'])->toBe(200)
        ->and(eventsOf($session, 'doc.changed')[0]['actor'])->toBe(['kind' => 'human', 'id' => (string) $user->getKey()]);
});

test('approve and publish over HTTP', function (): void {
    httpApp(fn (): bool => true, ['charlotte.autonomy' => 'approve_on_save']);
    $session = openSession();
    $user = User::create(['name' => 'Ada']);
    $staged = tool($session, 'cms_apply', ['ops' => sectionOps(), 'basedOn' => $session->state()['revision']]);

    $accepted = http('POST', '/sessions/'.$session->id().'/proposals/'.$staged['proposalId'].'/accept', [], $user);
    $again = http('POST', '/sessions/'.$session->id().'/proposals/'.$staged['proposalId'].'/accept', [], $user);
    $published = http('POST', '/sessions/'.$session->id().'/publish', ['basedOn' => $accepted['body']['revision']], $user);

    expect($accepted['body']['state'])->toBe('accepted')
        ->and($again['status'])->toBe(409)
        ->and($again['body']['error']['code'])->toBe('proposal_resolved')
        ->and($published['status'])->toBe(200)
        ->and(app(PageRepository::class)->published('acme', 'home')['revision'])->toBe($accepted['body']['revision']);
});

test('a second message while a turn runs is 409', function (): void {
    httpApp(fn (): bool => true);
    $session = openSession();
    app(\Charlotte\Workspace::class)->status($session->record(), 'running');

    $response = http('POST', '/sessions/'.$session->id().'/messages', ['text' => 'again'], User::create(['name' => 'Ada']));

    expect($response['status'])->toBe(409)
        ->and($response['body']['error']['code'])->toBe('turn_in_progress');
});

test('bodies are bounded, closed and JSON', function (): void {
    httpApp(fn (): bool => true, ['charlotte.routes.max_body_bytes' => 1024]);
    $user = User::create(['name' => 'Ada']);
    $session = openSession();

    expect(http('POST', '/sessions/'.$session->id().'/messages', ['text' => str_repeat('x', 2000)], $user)['status'])->toBe(413)
        ->and(http('POST', '/sessions/'.$session->id().'/messages', null, $user, raw: '{not json')['body']['error']['code'])->toBe('invalid_request')
        ->and(http('POST', '/sessions/'.$session->id().'/messages', ['text' => 'hi', 'role' => 'system'], $user)['status'])->toBe(422)
        ->and(http('POST', '/sessions', ['page' => 'home', 'brief' => ['summary' => 'x', 'facts' => 'none']], $user)['body']['error'])
        ->toMatchArray(['code' => 'invalid_brief', 'path' => '$.facts']);
});

test('the long-poll wait is capped by configuration', function (): void {
    httpApp(fn (): bool => true, ['charlotte.transport.poll_wait_seconds' => 0]);
    $session = openSession();
    $started = microtime(true);

    $response = http('GET', '/sessions/'.$session->id().'/events?after=999&wait=25', null, User::create(['name' => 'Ada']));

    expect($response['body'])->toBe(['events' => [], 'cursor' => 999])
        ->and(microtime(true) - $started)->toBeLessThan(2.0);
});
