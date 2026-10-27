<?php

declare(strict_types=1);

use Charlotte\Tests\Support\ScriptedProvider;
use Charlotte\Tests\Support\TestApp;
use Charlotte\Tests\Support\User;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Facades\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Streaming\Events\ToolResultEvent;

// Proves the test kernel itself: a harness stream through the scripted
// provider really executes a session-bound tool, with no network.
test('the scripted provider executes harness tools hermetically', function (): void {
    $provider = new ScriptedProvider([[['text' => 'hi', 'tools' => [['name' => 'echo', 'args' => ['value' => 'x']]]]]]);
    $app = TestApp::boot(['prism-harness.agent.modes.probe' => ['system_prompt' => 'p', 'tools' => ['echo'], 'skills' => [], 'max_steps' => 2]], $provider);
    $app->make(ToolRegistry::class)->registerProvider(fn (Session $s) => $s->mode() === 'probe'
        ? [Tool::as('echo')->for('echo')->withStringParameter('value', 'v')->using(fn (string $value): string => 'got '.$value)]
        : []);
    $user = User::create(['name' => 'a']);
    $session = PrismHarness::session($user, 'probe')->usingMode('probe')->usingProvider('scripted')->usingModel('m');
    $results = [];
    foreach ($app->make(AgentRuntime::class)->stream($session, 'go', ['echo']) as $event) {
        if ($event instanceof ToolResultEvent) {
            $results[] = $event->toolResult->result;
        }
    }
    expect($results)->toBe(['got x']);
});
