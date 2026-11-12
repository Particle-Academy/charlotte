<?php

declare(strict_types=1);

use Charlotte\Telemetry\UsageTelemetry;
use Charlotte\Tests\Support\ScriptedProvider;

/*
 * Cost and usage observation is Prism's courtesy feature. Charlotte runs fully
 * without it, never refuses a provider for want of a price, and turns it on
 * only when the dev enables it AND the installed Prism has it.
 */

function oneStepTurn(): ScriptedProvider
{
    return new ScriptedProvider([[['text' => 'Built.']]]);
}

test('a full turn runs with telemetry absent, and records no usage', function (): void {
    charlotteApp(['prism.telemetry.enabled' => false, 'charlotte.telemetry.enabled' => false], oneStepTurn());
    $session = openSession();
    $session->send('Go.', 'user-7');

    expect($session->record()->status)->toBe('idle')
        ->and(eventsOf($session, 'run.finished'))->toHaveCount(1)
        ->and(eventsOf($session, 'usage.observed'))->toBe([]);
});

test('asking for telemetry while Prism\'s is off is a quiet no-op, not a refusal', function (): void {
    charlotteApp(['prism.telemetry.enabled' => false, 'charlotte.telemetry.enabled' => true], oneStepTurn());
    $session = openSession();
    $session->send('Go.', 'user-7');

    expect($session->record()->status)->toBe('idle')
        ->and(eventsOf($session, 'usage.observed'))->toBe([]);
});

test('with Prism telemetry on, usage is observed even with no price, and nothing is refused', function (): void {
    charlotteApp(['prism.telemetry.enabled' => true, 'charlotte.telemetry.enabled' => true], oneStepTurn());
    $session = openSession();
    $session->send('Go.', 'user-7');

    $usage = eventsOf($session, 'usage.observed');

    expect($session->record()->status)->toBe('idle')
        ->and($usage)->toHaveCount(1)
        ->and($usage[0]['data'])->toBe(['provider' => 'scripted', 'model' => 'test-model', 'inputTokens' => 100, 'outputTokens' => 20,
            'cacheReadTokens' => null, 'cacheWriteTokens' => null, 'costUsd' => null, 'priced' => false]);
});

test('cache-read and cache-write tokens are recorded when the provider reports them (prism#29: is the guide cached yet?)', function (): void {
    charlotteApp(['prism.telemetry.enabled' => true, 'charlotte.telemetry.enabled' => true]);
    $session = openSession();
    $key = 'session:abc:1:charlotte:'.$session->id();

    app('events')->dispatch(new \Prism\Prism\Events\Telemetry\GenerationCompleted(
        new \Prism\Prism\Telemetry\TelemetryContext('trace-1', \Prism\Prism\Enums\TelemetryOperation::Text, 'scripted', 'test-model', microtime(true), sessionId: $key),
        12.0,
        usage: new \Prism\Prism\ValueObjects\Usage(1200, 300, cacheWriteInputTokens: 0, cacheReadInputTokens: 9800),
    ));

    expect(eventsOf($session, 'usage.observed')[0]['data'])->toMatchArray(['inputTokens' => 1200, 'cacheReadTokens' => 9800, 'cacheWriteTokens' => 0]);
});

test('a configured price turns observed usage into a cost', function (): void {
    charlotteApp([
        'prism.telemetry.enabled' => true,
        'charlotte.telemetry.enabled' => true,
        'charlotte.telemetry.prices' => ['scripted' => ['test-model' => ['input' => 3.0, 'output' => 15.0]]],
    ], oneStepTurn());
    $session = openSession();
    $session->send('Go.', 'user-7');

    expect(eventsOf($session, 'usage.observed')[0]['data'])->toMatchArray(['costUsd' => 0.0006, 'priced' => true]);
});

test('the integration is feature-detected: without Prism\'s telemetry events it stays off', function (): void {
    charlotteApp(['prism.telemetry.enabled' => true, 'charlotte.telemetry.enabled' => true]);

    expect(UsageTelemetry::available())->toBeTrue()
        ->and(UsageTelemetry::available(classExists: fn (string $class) => false))->toBeFalse();
});

test('usage from a harness session that is not Charlotte\'s is ignored', function (): void {
    charlotteApp(['prism.telemetry.enabled' => true, 'charlotte.telemetry.enabled' => true]);

    expect(UsageTelemetry::sessionFromKey('session:abc:1:support'))->toBeNull()
        ->and(UsageTelemetry::sessionFromKey('session:abc:1:charlotte:01JC0000000000000000000001'))->toBe('01JC0000000000000000000001');
});
