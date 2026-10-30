<?php

declare(strict_types=1);

use Charlotte\Policy\LinkPolicy;
use Charlotte\Telemetry\UsageTelemetry;
use Charlotte\Tests\Support\ScriptedProvider;
use Illuminate\Database\ConnectionInterface;
use Prism\Prism\Events\Telemetry\GenerationCompleted;
use Prism\Prism\Telemetry\TelemetryContext;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\ValueObjects\Usage;

/*
 * Telemetry is OPTIONAL and must cost an unrelated agent role nothing. Prism's
 * GenerationCompleted fires for EVERY generation in the host, not only for
 * Charlotte's. A host whose Charlotte dependencies are strictly scoped (fancy-labs
 * resolves LinkPolicy from a per-turn scope that throws outside a builder turn)
 * must never see them resolved for a generation that is not Charlotte's, and
 * never at all while Charlotte's own telemetry flag is off.
 */

function generationFor(?string $sessionKey): GenerationCompleted
{
    return new GenerationCompleted(
        new TelemetryContext('trace-1', TelemetryOperation::Text, 'scripted', 'test-model', microtime(true), sessionId: $sessionKey),
        12.0,
        usage: new Usage(100, 20),
    );
}

/**
 * A host LinkPolicy that only exists inside a builder turn, as fancy-labs' does.
 * Counts its resolutions: "never resolved" is the claim, not merely "the
 * throw was swallowed".
 */
function scopedHostDependencies(): ArrayObject
{
    $resolved = new ArrayObject;
    app()->bind(LinkPolicy::class, function () use ($resolved): never {
        $resolved->append(true);
        throw new RuntimeException('LinkPolicy is only available inside a builder turn.');
    });

    return $resolved;
}

function charlotteEventCount(): int
{
    return app(ConnectionInterface::class)->table('charlotte_events')->count();
}

test('with telemetry on, a generation outside any Charlotte session resolves no host dependency and writes nothing', function (): void {
    charlotteApp(['prism.telemetry.enabled' => true, 'charlotte.telemetry.enabled' => true]);
    openSession();
    $before = charlotteEventCount();
    $resolved = scopedHostDependencies();

    app('events')->dispatch(generationFor('session:abc:1:support'));
    app('events')->dispatch(generationFor(null));

    expect(charlotteEventCount())->toBe($before)
        ->and(count($resolved))->toBe(0);
});

test('with Charlotte telemetry off, even a Charlotte session\'s generation resolves nothing and writes nothing', function (): void {
    charlotteApp(['prism.telemetry.enabled' => true, 'charlotte.telemetry.enabled' => false]);
    $key = 'session:abc:1:'.openSession()->record()->scope();
    $before = charlotteEventCount();
    $resolved = scopedHostDependencies();

    app('events')->dispatch(generationFor($key));

    expect(charlotteEventCount())->toBe($before)
        ->and(count($resolved))->toBe(0);
});

test('a Charlotte session\'s generation is still observed, with the workspace resolved only then', function (): void {
    charlotteApp(['prism.telemetry.enabled' => true, 'charlotte.telemetry.enabled' => true], new ScriptedProvider);
    $session = openSession();

    app('events')->dispatch(generationFor('session:abc:1:'.$session->record()->scope()));

    expect(eventsOf($session, 'usage.observed'))->toHaveCount(1)
        ->and(eventsOf($session, 'usage.observed')[0]['data'])->toMatchArray(['inputTokens' => 100, 'outputTokens' => 20]);
});

test('the listener swallows a failure resolving the workspace for its own session too', function (): void {
    charlotteApp(['prism.telemetry.enabled' => true, 'charlotte.telemetry.enabled' => true]);
    $session = openSession();
    $key = 'session:abc:1:'.$session->record()->scope();
    $before = charlotteEventCount();
    $resolved = scopedHostDependencies();

    app('events')->dispatch(generationFor($key));

    expect(charlotteEventCount())->toBe($before)
        ->and(count($resolved))->toBe(1)
        ->and(UsageTelemetry::sessionFromKey($key))->toBe($session->id());
});
