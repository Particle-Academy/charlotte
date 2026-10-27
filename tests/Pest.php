<?php

declare(strict_types=1);

use Charlotte\Brief\Brief;
use Charlotte\CharlotteSession;
use Charlotte\Facades\Charlotte;
use Charlotte\Tests\Support\ScriptedProvider;
use Charlotte\Tests\Support\TestApp;
use Charlotte\Tests\Support\User;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;

// Every test boots its own kernel through TestApp::boot(); drop it afterwards
// so no container, facade or in-memory database leaks into the next test.
uses()->afterEach(function (): void {
    Facade::clearResolvedInstances();
    Container::setInstance(null);
})->in(__DIR__);

/** A booted app with Charlotte on the scripted provider. */
function charlotteApp(array $config = [], ?ScriptedProvider $provider = null): Application
{
    return TestApp::boot([
        'charlotte.provider' => 'scripted',
        'charlotte.model' => 'test-model',
        ...$config,
    ], $provider);
}

function briefArray(): array
{
    return [
        'summary' => 'Landing page for Acme Ledger, bookkeeping for food trucks.',
        'facts' => [
            ['id' => 'price-starter', 'kind' => 'price', 'label' => 'Starter plan', 'value' => '$19/month', 'required' => true],
            ['id' => 'contact', 'kind' => 'contact', 'value' => 'hello@example.com', 'required' => true],
        ],
        'cta' => ['label' => 'Start free', 'href' => '/signup'],
    ];
}

/** Opens a session on page "home" of tenant "acme" for a fresh participant. */
function openSession(array $options = []): CharlotteSession
{
    $user = $options['user'] ?? User::create(['name' => 'Ada']);
    $pending = Charlotte::for($user)
        ->tenant($options['tenant'] ?? 'acme')
        ->page($options['page'] ?? 'home')
        ->brief(Brief::fromArray($options['brief'] ?? briefArray()));
    if (isset($options['autonomy'])) {
        $pending->autonomy($options['autonomy']);
    }

    return $pending->open();
}

/** Calls a tool the way the model does and decodes the JSON result. */
function tool(CharlotteSession $session, string $name, array $arguments = []): array
{
    return json_decode($session->tools()->call($name, $arguments), true, 512, JSON_THROW_ON_ERROR);
}

/** A section with one heading. */
function sectionOps(string $id = 'hero', string $order = 'a0', string $heading = 'Acme Ledger', int $level = 1): array
{
    return [
        ['t' => 'insert_node', 'node' => ['id' => $id, 'type' => 'section', 'parent' => null, 'order' => $order, 'props' => [], 'style' => ['base' => []]]],
        ['t' => 'insert_node', 'node' => ['id' => $id.'-h', 'type' => 'heading', 'parent' => $id, 'order' => 'a0', 'props' => ['content' => $heading, 'level' => $level], 'style' => ['base' => []]]],
    ];
}

/** One text node under a parent. */
function textOp(string $id, string $parent, string $content, string $order = 'a1'): array
{
    return ['t' => 'insert_node', 'node' => ['id' => $id, 'type' => 'text', 'parent' => $parent, 'order' => $order, 'props' => ['content' => $content], 'style' => ['base' => []]]];
}

/** Event types for a session, in order. */
function eventTypes(CharlotteSession $session, int $after = 0): array
{
    return array_column($session->events($after)['events'], 'type');
}

/** The events of one type. */
function eventsOf(CharlotteSession $session, string $type): array
{
    return array_values(array_filter($session->events(0)['events'], fn (array $e) => $e['type'] === $type));
}
