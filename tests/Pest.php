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

/** A cost-capped, priced host run context, bound for every turn. */
function cappedTree(float $maxCostUsd = 5.0, int $maxSteps = 100): \Prism\Harness\Subagents\RunContext
{
    $tree = \Prism\Harness\Subagents\RunContext::root('host-tree', new \Prism\Harness\Subagents\RunBudget(maxSteps: $maxSteps, maxCostUsd: $maxCostUsd, maxOutputTokensPerCall: 1024),
        pricing: ['scripted' => ['test-model' => ['inputPerMTok' => 3.0, 'outputPerMTok' => 15.0, 'toolOverheadTokens' => 400]]]);
    Charlotte::contextUsing(fn () => $tree);

    return $tree;
}

/** The reserved routes a Fortify host supplies (finding K13). */
const FORTIFY_ROUTES = ['/login', '/register', '/forgot-password', '/reset-password/*', '/two-factor-challenge'];

/** Opens a SITE session on site "acme-site" of tenant "acme". */
function openSite(array $options = []): CharlotteSession
{
    $user = $options['user'] ?? User::create(['name' => 'Ada']);
    $pending = Charlotte::for($user)
        ->tenant($options['tenant'] ?? 'acme')
        ->site($options['site'] ?? 'acme-site')
        ->brief(Brief::fromArray($options['brief'] ?? briefArray()));
    if (isset($options['autonomy'])) {
        $pending->autonomy($options['autonomy']);
    }

    return $pending->open();
}

/** A small kit island catalogue in the normalized shape. */
function kitIslands(): array
{
    return [
        ['type' => 'kit:Tabs', 'description' => 'Tabbed panels of text.', 'props' => [
            'type' => 'object', 'required' => ['tabs'], 'additionalProperties' => false,
            'properties' => [
                'tabs' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 8, 'items' => [
                    'type' => 'object', 'required' => ['value', 'label'], 'additionalProperties' => false,
                    'properties' => [
                        'value' => ['type' => 'string', 'pattern' => '^[a-z0-9-]{1,32}$'],
                        'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 60],
                        'body' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 2000]],
                    ],
                ]],
                'defaultTab' => ['type' => 'string'],
            ],
        ]],
        ['type' => 'kit:Gallery', 'description' => 'Images from the host catalogue.', 'props' => [
            'type' => 'object', 'required' => ['items'],
            'properties' => [
                'items' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['asset'],
                    'properties' => ['asset' => ['type' => 'string'], 'caption' => ['type' => 'string']]]],
                'href' => ['type' => 'string'],
            ],
        ]],
    ];
}

/** An island insert op. */
function islandOp(string $id, string $type, array $props, ?string $parent = null, string $order = 'a5', bool $island = true): array
{
    $node = ['id' => $id, 'type' => $type, 'parent' => $parent, 'order' => $order, 'props' => $props, 'style' => ['base' => []]];
    if ($island) {
        $node['island'] = true;
    }

    return ['t' => 'insert_node', 'node' => $node];
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

/**
 * The guide's worked design examples, label => ops, from each
 * `**<label>:**` paragraph followed by a json block. Line endings are
 * normalised first: a Windows checkout has the guide with CRLF.
 *
 * @return array<string, list<array>>
 */
function guideDesignExamples(): array
{
    $text = str_replace("\r\n", "\n", \Charlotte\Guide\PublishedGuide::charlotteGuide());
    preg_match_all('/\*\*(Warm local shop|Clean B2B product):\*\*[^`]*```json\n(.*?)\n```/s', $text, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as [, $label, $json]) {
        $out[$label] = json_decode('['.preg_replace('/\}\n\{"t"/', '},{"t"', str_replace("\n ", '', $json)).']', true, 64, JSON_THROW_ON_ERROR);
    }

    return $out;
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

/**
 * A page that clears every cms_finish gate: a meta description, stacks and
 * grids with their layout, a section spacing scale, a hero with copy and a
 * 44 px button, and a grid of two cards, 1 then 2 at md (no orphan, no
 * empty cell). Its copy carries briefArray()'s facts and nothing else.
 */
function finishablePageOps(string $heading = 'Acme Ledger', string $prefix = ''): array
{
    $px = fn (int $v): array => ['value' => $v, 'unit' => 'px'];
    $key = fn (string $id): string => $prefix === '' ? $id : $prefix.'-'.$id;
    $node = fn (string $id, string $type, ?string $parent, string $order, array $props = [], array $style = [], ?string $layout = null): array => ['t' => 'insert_node',
        'node' => ['id' => $key($id), 'type' => $type, 'parent' => $parent === null ? null : $key($parent), 'order' => $order, 'props' => $props, 'style' => $style + ['base' => []]]
            + ($layout === null ? [] : ['layout' => $layout])];
    $section = ['base' => ['paddingY' => $px(48), 'paddingX' => $px(20)], 'md' => ['paddingY' => $px(72), 'paddingX' => $px(32)]];

    return [
        ['t' => 'set_meta', 'patch' => ['title' => $heading, 'seo' => ['description' => 'Bookkeeping for food trucks.']]],
        $node('hero', 'section', null, 'a0', [], $section, 'stack'),
        $node('hero-h', 'heading', 'hero', 'a0', ['content' => $heading, 'level' => 1]),
        $node('hero-t', 'text', 'hero', 'a1', ['content' => 'Bookkeeping for food trucks.']),
        $node('hero-b', 'button', 'hero', 'a2', ['label' => 'See the plans', 'href' => '#plans'], ['base' => ['paddingY' => $px(14)]]),
        $node('plans', 'section', null, 'a1', ['anchor' => 'plans'], $section, 'stack'),
        $node('plans-grid', 'grid', 'plans', 'a0', [], ['base' => ['columns' => 1, 'gap' => $px(24)], 'md' => ['columns' => 2]], 'grid'),
        $node('plan-a', 'card', 'plans-grid', 'a0', [], ['base' => ['gap' => $px(12)]], 'stack'),
        $node('plan-a-t', 'text', 'plan-a', 'a0', ['content' => 'Starter plan: $19/month']),
        $node('plan-b', 'card', 'plans-grid', 'a1', [], ['base' => ['gap' => $px(12)]], 'stack'),
        $node('plan-b-t', 'text', 'plan-b', 'a0', ['content' => 'Write to hello@example.com']),
    ];
}
