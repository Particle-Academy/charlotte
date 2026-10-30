<?php

declare(strict_types=1);

use Charlotte\Facades\Charlotte;
use Charlotte\Runtime\Turn;
use Charlotte\Runtime\TurnContextFactory;
use Charlotte\Tests\Support\ScriptedProvider;
use Prism\Harness\Contracts\CallCostObserver;
use Prism\Harness\Contracts\HasErrorCode;
use Prism\Harness\Subagents\CallReservation;
use Prism\Harness\Subagents\CallSettlement;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;

/*
 * A host may own each turn's spend: its own RunContext (budget, pricing, cost
 * observer) per turn, without rebuilding the dispatcher, the runner or the
 * runtime. Billing stays optional: with no factory bound, a turn runs exactly
 * as before.
 */

/** A host cost observer that records what it was asked to admit. */
final class RecordingObserver implements CallCostObserver
{
    public array $reserved = [];

    public array $settled = [];

    public function reserving(CallReservation $call, RunContext $run): void
    {
        $this->reserved[] = [$call->provider, $call->model];
    }

    public function settled(CallSettlement $call, RunContext $run): void
    {
        $this->settled[] = $call;
    }
}

final class HostBudgetRefused extends RuntimeException implements HasErrorCode
{
    public function code(): string
    {
        return 'host_budget_exceeded';
    }
}

/** A per-turn root context: capped, priced, observed. */
function hostContext(CallCostObserver $observer): RunContext
{
    return RunContext::root('host-turn', new RunBudget(maxSteps: 10, maxCostUsd: 5.0, maxOutputTokensPerCall: 1024),
        pricing: ['scripted' => ['test-model' => ['inputPerMTok' => 3.0, 'outputPerMTok' => 15.0, 'toolOverheadTokens' => 400]]],
        observer: $observer);
}

test('a host-supplied per-turn context is honoured: its observer admits every provider call', function (): void {
    $provider = new ScriptedProvider([[['text' => 'Built.']]]);
    charlotteApp([], $provider);
    $observer = new RecordingObserver;
    $seen = [];
    app()->instance(TurnContextFactory::class, new class($observer, $seen) implements TurnContextFactory
    {
        public function __construct(private RecordingObserver $observer, private array &$seen) {}

        public function forTurn(Turn $turn): ?RunContext
        {
            $this->seen[] = [$turn->session->tenant, $turn->session->page, $turn->text, $turn->harness->scope()];

            return hostContext($this->observer);
        }
    });
    $session = openSession();

    $session->send('Go.', 'user-7');

    expect($session->record()->status)->toBe('idle')
        ->and($seen)->toBe([['acme', 'home', 'Go.', 'charlotte:'.$session->id()]])
        ->and($observer->reserved)->toBe([['scripted', 'test-model']])
        ->and($observer->settled)->toHaveCount(1)
        ->and($provider->requests)->toHaveCount(1);
});

test('the closure form binds the same hook', function (): void {
    charlotteApp([], new ScriptedProvider([[['text' => 'Built.']]]));
    $observer = new RecordingObserver;
    Charlotte::contextUsing(fn (Turn $turn): RunContext => hostContext($observer));
    $session = openSession();

    $session->send('Go.', 'user-7');

    expect($session->record()->status)->toBe('idle')
        ->and($observer->reserved)->toHaveCount(1);
});

test('a host that refuses admission fails the turn with its code, before any provider call', function (): void {
    $provider = new ScriptedProvider([[['text' => 'Built.']]]);
    charlotteApp([], $provider);
    Charlotte::contextUsing(fn (Turn $turn) => throw new HostBudgetRefused('over budget: secret detail'));
    $session = openSession();

    $session->send('Go.', 'user-7');

    expect($session->record()->status)->toBe('failed')
        ->and(eventsOf($session, 'run.failed')[0]['data'])->toBe(['runId' => null, 'code' => 'host_budget_exceeded'])
        ->and($provider->requests)->toBe([]);
});

test('a factory returning null keeps the harness defaults', function (): void {
    $provider = new ScriptedProvider([[['text' => 'Built.']]]);
    charlotteApp([], $provider);
    Charlotte::contextUsing(fn (Turn $turn): ?RunContext => null);
    $session = openSession();

    $session->send('Go.', 'user-7');

    expect($session->record()->status)->toBe('idle')
        ->and($provider->requests)->toHaveCount(1);
});
