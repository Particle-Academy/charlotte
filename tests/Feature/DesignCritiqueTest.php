<?php

declare(strict_types=1);

use Charlotte\Critique\CriticMode;
use Charlotte\Critique\Screenshot;
use Charlotte\Facades\Charlotte;
use Charlotte\Guide\BuilderMode;
use Charlotte\Sessions\SessionRecord;
use Charlotte\Tests\Support\ScriptedProvider;
use Prism\Harness\Models\Thread;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/*
 * The render, critique, revise loop (Prism maintainers' recommendation,
 * 2026-11-13). After cms_finish passes its gates, a host-supplied renderer
 * captures the page at 375, 768 and 1280 px; a critic sub-agent on its own
 * nested budget and its own prompt critiques visual design and responsive
 * layout from the screenshots; the builder applies the items once and finishes
 * again. On by default when a renderer is supplied, off otherwise.
 */

/** A tiny valid PNG; the critic only needs bytes, the fake provider reads none. */
function critiquePng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
}

/** Registers a renderer that records each call and returns one screenshot per width. */
function critiqueRenderer(array &$calls): void
{
    Charlotte::screenshotsUsing(function (SessionRecord $session, string $page, string $revision, array $widths) use (&$calls): array {
        $calls[] = ['page' => $page, 'revision' => $revision, 'widths' => $widths];

        return array_map(fn (int $width): Screenshot => Screenshot::png($width, critiquePng()), $widths);
    });
}

/** Builder: build a finishable page and finish it. */
function critiqueBuildTurn(): array
{
    return [
        ['tools' => [['name' => 'cms_get', 'args' => []]]],
        ['tools' => [['name' => 'cms_apply', 'args' => fn (array $calls) => ['ops' => finishablePageOps(), 'basedOn' => json_decode(end($calls)['result'], true)['revision']]]]],
        ['tools' => [['name' => 'cms_finish', 'args' => fn (array $calls) => ['basedOn' => json_decode(end($calls)['result'], true)['revision']]]]],
        ['text' => 'The page is ready.'],
    ];
}

/** Builder: apply one revision and finish again. */
function critiqueRevisionTurn(): array
{
    return [
        ['tools' => [['name' => 'cms_get', 'args' => []]]],
        ['tools' => [['name' => 'cms_apply', 'args' => fn (array $calls) => ['ops' => [
            ['t' => 'set_style', 'id' => 'hero-h', 'breakpoint' => 'base', 'patch' => ['fontSize' => ['value' => 44, 'unit' => 'px']]],
        ], 'basedOn' => json_decode(end($calls)['result'], true)['revision']]]]],
        ['tools' => [['name' => 'cms_finish', 'args' => fn (array $calls) => ['basedOn' => json_decode(end($calls)['result'], true)['revision']]]]],
        ['text' => 'Revised.'],
    ];
}

/** The critic's reply: one call, JSON only. */
function criticReply(string $verdict = 'revise', ?array $items = null): array
{
    $items ??= [
        ['node' => 'hero-h', 'viewport' => 375, 'criterion' => 'visual_design', 'issue' => 'The h1 is the same size as body text, so the hero has no focal point.', 'fix' => 'Raise the h1 to a 40-48px display size.'],
        ['node' => 'no-such-node', 'viewport' => 1280, 'criterion' => 'responsive', 'issue' => 'Not on the page.', 'fix' => 'n/a'],
        ['node' => 'plans-grid', 'viewport' => 768, 'criterion' => 'responsive', 'issue' => 'Two cards leave a wide empty gutter at 768.', 'fix' => 'Widen the gap to 32px at md.'],
    ];

    return [['text' => json_encode(['verdict' => $verdict, 'scores' => ['visual_design' => 62, 'responsive' => 71], 'items' => $items], JSON_THROW_ON_ERROR)]];
}

/**
 * The harness's own price table. The critic has a cost cap, and a tree with
 * an unpriced call cannot enforce one: prism-harness then refuses the child
 * (fail closed), which the nested-budget test shows.
 */
const CRITIQUE_PRICING = ['scripted' => [
    'test-model' => ['inputPerMTok' => 3.0, 'outputPerMTok' => 15.0, 'toolOverheadTokens' => 400],
    'critic-model' => ['inputPerMTok' => 1.0, 'outputPerMTok' => 5.0, 'toolOverheadTokens' => 400, 'imageTokenBound' => 1600],
    'scripted-mini' => ['inputPerMTok' => 1.0, 'outputPerMTok' => 5.0, 'toolOverheadTokens' => 400, 'imageTokenBound' => 1600],
]];

function critiqueApp(ScriptedProvider $provider, array $config = []): void
{
    charlotteApp(['charlotte.autonomy' => 'full', 'charlotte.critique.model' => 'critic-model', 'prism-harness.pricing' => CRITIQUE_PRICING, ...$config], $provider);
}

/** A priced, capped host tree pricing BOTH the builder and the critic model. */
function critiqueTree(float $maxCostUsd = 5.0): RunContext
{
    $tree = RunContext::root('host-tree', new RunBudget(maxSteps: 100, maxCostUsd: $maxCostUsd, maxOutputTokensPerCall: 1024),
        pricing: ['scripted' => [
            'test-model' => ['inputPerMTok' => 3.0, 'outputPerMTok' => 15.0, 'toolOverheadTokens' => 400],
            'critic-model' => ['inputPerMTok' => 1.0, 'outputPerMTok' => 5.0, 'toolOverheadTokens' => 400, 'imageTokenBound' => 1600],
        ]]);
    Charlotte::contextUsing(fn () => $tree);

    return $tree;
}

/** The critic's requests: the ones made in the critic mode's system prompt. */
function criticRequests(ScriptedProvider $provider): array
{
    return array_values(array_filter($provider->requests, fn ($r) => str_contains(implode("\n", array_map(fn (SystemMessage $m) => $m->content, $r->systemPrompts())), CriticMode::MARKER)));
}

test('with no renderer, nothing changes: no capture, no critic, one run', function (): void {
    $provider = new ScriptedProvider([critiqueBuildTurn(), criticReply()]);
    critiqueApp($provider);
    $session = openSession();

    $session->send('Build the page.', 'user-7');

    expect($session->record()->status)->toBe('idle')
        ->and($session->record()->readyRevision)->not->toBeNull()
        ->and(eventsOf($session, 'run.started'))->toHaveCount(1)
        ->and(eventsOf($session, 'critique.result'))->toBe([])
        ->and(eventsOf($session, 'critique.revised'))->toBe([])
        ->and(criticRequests($provider))->toBe([])
        ->and($provider->turns)->toHaveCount(1, 'the critic reply was never consumed');
});

test('with a renderer, the loop runs exactly once: render, critique, one revision, finish again', function (): void {
    // A second critic reply and a second revision are scripted and must never be played.
    $provider = new ScriptedProvider([critiqueBuildTurn(), criticReply(), critiqueRevisionTurn(), criticReply(), critiqueRevisionTurn()]);
    critiqueApp($provider);
    $renders = [];
    critiqueRenderer($renders);
    $session = openSession();

    $session->send('Build the page.', 'user-7');

    $record = $session->record();
    $result = eventsOf($session, 'critique.result');
    $revised = eventsOf($session, 'critique.revised');
    expect($record->status)->toBe('idle')
        ->and($renders)->toHaveCount(1)
        ->and($renders[0]['widths'])->toBe([375, 768, 1280])
        ->and($renders[0]['page'])->toBe('home')
        ->and(criticRequests($provider))->toHaveCount(1)
        ->and(eventsOf($session, 'run.started'))->toHaveCount(2)
        ->and($result)->toHaveCount(1)
        ->and($result[0]['data'])->toMatchArray(['outcome' => 'completed', 'verdict' => 'revise', 'revision' => 'running', 'model' => 'critic-model', 'provider' => 'scripted', 'widths' => [375, 768, 1280]])
        ->and(array_column($result[0]['data']['items'], 'node'))->toBe(['hero-h', 'plans-grid'], 'an item naming no node on the page is dropped')
        ->and($result[0]['data']['dropped'])->toBe(1)
        ->and($result[0]['data']['revisionOf'])->toBe($renders[0]['revision'])
        ->and($revised)->toHaveCount(1)
        ->and($revised[0]['data'])->toMatchArray(['ready' => true, 'revisions' => 1])
        ->and($record->readyRevision)->toBe($session->state()['revision'])
        ->and($record->readyRevision)->not->toBe($renders[0]['revision'])
        ->and($session->state()['doc']['nodes']['hero-h']['style']['base']['fontSize'] ?? null)->not->toBeNull()
        ->and($provider->turns)->toHaveCount(2, 'the second critique and revision were never played');
});

test('the revision cap is configurable, and 0 turns the loop off', function (): void {
    $provider = new ScriptedProvider([critiqueBuildTurn(), criticReply(), critiqueRevisionTurn(), criticReply(), critiqueRevisionTurn(), criticReply()]);
    critiqueApp($provider, ['charlotte.critique.max_revisions' => 2]);
    $renders = [];
    critiqueRenderer($renders);
    $session = openSession();
    $session->send('Build the page.', 'user-7');

    expect($renders)->toHaveCount(2)
        ->and(eventsOf($session, 'critique.revised'))->toHaveCount(2)
        ->and($provider->turns)->toHaveCount(1);

    $off = new ScriptedProvider([critiqueBuildTurn(), criticReply()]);
    critiqueApp($off, ['charlotte.critique.max_revisions' => 0]);
    $renders = [];
    critiqueRenderer($renders);
    $session = openSession();
    $session->send('Build the page.', 'user-7');

    expect($renders)->toBe([])->and(criticRequests($off))->toBe([]);
});

test('a supplied renderer can still be switched off', function (): void {
    $provider = new ScriptedProvider([critiqueBuildTurn(), criticReply()]);
    critiqueApp($provider, ['charlotte.critique.enabled' => false]);
    $renders = [];
    critiqueRenderer($renders);
    $session = openSession();

    $session->send('Build the page.', 'user-7');

    expect($renders)->toBe([])->and(eventsOf($session, 'critique.result'))->toBe([]);
});

test('a pass verdict, or no item that names a node, revises nothing', function (): void {
    $provider = new ScriptedProvider([critiqueBuildTurn(), criticReply('pass', [])]);
    critiqueApp($provider);
    $renders = [];
    critiqueRenderer($renders);
    $session = openSession();
    $session->send('Build the page.', 'user-7');

    expect(eventsOf($session, 'critique.result')[0]['data'])->toMatchArray(['verdict' => 'pass', 'revision' => 'not_needed'])
        ->and(eventsOf($session, 'run.started'))->toHaveCount(1)
        ->and(eventsOf($session, 'critique.revised'))->toBe([]);

    $provider = new ScriptedProvider([critiqueBuildTurn(), criticReply('revise', [['node' => 'ghost', 'viewport' => 375, 'criterion' => 'visual_design', 'issue' => 'x', 'fix' => 'y']])]);
    critiqueApp($provider);
    critiqueRenderer($renders);
    $session = openSession();
    $session->send('Build the page.', 'user-7');

    expect(eventsOf($session, 'critique.result')[0]['data'])->toMatchArray(['items' => [], 'dropped' => 1, 'revision' => 'not_needed'])
        ->and(eventsOf($session, 'run.started'))->toHaveCount(1);
});

test('the critic receives the screenshots, and nothing of the builder: not its prompt, guide, examples or conversation', function (): void {
    $provider = new ScriptedProvider([critiqueBuildTurn(), criticReply(), critiqueRevisionTurn()]);
    critiqueApp($provider);
    $renders = [];
    critiqueRenderer($renders);
    $session = openSession();
    $session->send('Build the page. SECRET-OPERATOR-NOTE', 'user-7');

    $critic = criticRequests($provider);
    expect($critic)->toHaveCount(1);
    $request = $critic[0];
    $system = implode("\n", array_map(fn (SystemMessage $m) => $m->content, $request->systemPrompts()));
    $everything = $system."\n".implode("\n", array_map(fn ($m) => is_string($m->content ?? null) ? $m->content : '', $request->messages()));
    $messages = $request->messages();
    $last = end($messages);
    $guide = Charlotte::guides()->current()->guide;

    expect($request->model())->toBe('critic-model')
        ->and($last)->toBeInstanceOf(UserMessage::class)
        ->and($last->images())->toHaveCount(3)
        ->and($request->tools())->toBe([])
        ->and($system)->toBe(CriticMode::prompt())
        ->and($everything)->not->toContain(BuilderMode::PREAMBLE)
        ->and($everything)->not->toContain(mb_substr($guide, 0, 200))
        ->and($everything)->not->toContain('SECRET-OPERATOR-NOTE')
        ->and($everything)->not->toContain('Acme Ledger is a')
        ->and($everything)->toContain('hero-h');
    foreach ($session->record()->examples() as $example) {
        expect($everything)->not->toContain($example);
    }
});

test('the critic prompt critiques only visual design and responsive layout, against the anchor scale, and differs from a final reviewer\'s', function (): void {
    $prompt = CriticMode::prompt();

    expect($prompt)->toContain('visual design')
        ->and($prompt)->toContain('responsive')
        ->and($prompt)->toContain(CriticMode::ANCHORS)
        ->and($prompt)->not->toContain('brief_fit')
        ->and($prompt)->not->toContain('accessibility (')
        ->and($prompt)->not->toContain('editability');
});

test('the builder gets the items as framed data, never the critic\'s scores', function (): void {
    $provider = new ScriptedProvider([critiqueBuildTurn(), criticReply(), critiqueRevisionTurn()]);
    critiqueApp($provider);
    $renders = [];
    critiqueRenderer($renders);
    $session = openSession();
    $session->send('Build the page.', 'user-7');

    $builder = array_values(array_filter($provider->requests, fn ($r) => $r->model() === 'test-model'));
    $revisionMessage = null;
    $lastBuilder = end($builder);
    foreach ($lastBuilder->messages() as $message) {
        if ($message instanceof UserMessage && str_contains($message->content, 'hero-h')) {
            $revisionMessage = $message->content;
        }
    }

    expect($revisionMessage)->not->toBeNull()
        ->and($revisionMessage)->toContain('cms_finish')
        ->and($revisionMessage)->toContain('data')
        ->and($revisionMessage)->toContain('plans-grid')
        ->and($revisionMessage)->not->toContain('no-such-node')
        ->and($revisionMessage)->not->toContain('62')
        ->and($revisionMessage)->not->toContain('scores');
});

test('the critic\'s spend nests in the turn\'s tree, and its thread sits beneath the builder\'s', function (): void {
    $provider = new ScriptedProvider([critiqueBuildTurn(), criticReply(), critiqueRevisionTurn()]);
    critiqueApp($provider);
    $tree = critiqueTree();
    $renders = [];
    critiqueRenderer($renders);
    $session = openSession();

    $session->send('Build the page.', 'user-7');

    $result = eventsOf($session, 'critique.result')[0]['data'];
    $builderThread = Thread::query()->where('scope', 'charlotte:'.$session->id())->first();
    $criticThread = Thread::query()->where('scope', 'charlotte:'.$session->id().'::sub::'.CriticMode::SCOPE)->first();
    // 100 input + 20 output tokens at the critic's price: the ScriptedProvider's usage per call.
    $criticCost = (100 * 1.0 + 20 * 5.0) / 1_000_000;

    expect($result['costUsd'])->toEqualWithDelta($criticCost, 1e-9)
        ->and($result['steps'])->toBe(1)
        ->and($tree->ledger->costUsd())->toBeGreaterThan($criticCost)
        ->and($criticThread)->not->toBeNull()
        ->and($criticThread->parent_thread_id)->toBe($builderThread->getKey())
        ->and($criticThread->root_run_id)->toBe('host-tree');
});

test('billing stays optional: with no host context the critic still runs on one tree, bounded by steps, its cost unknown', function (): void {
    $provider = new ScriptedProvider([critiqueBuildTurn(), criticReply(), critiqueRevisionTurn()]);
    charlotteApp(['charlotte.autonomy' => 'full', 'charlotte.critique.model' => 'critic-model'], $provider);
    $renders = [];
    critiqueRenderer($renders);
    $session = openSession();

    $session->send('Build the page.', 'user-7');

    $result = eventsOf($session, 'critique.result')[0]['data'];
    $builderThread = Thread::query()->where('scope', 'charlotte:'.$session->id())->first();
    $criticThread = Thread::query()->where('scope', 'charlotte:'.$session->id().'::sub::'.CriticMode::SCOPE)->first();
    expect($result)->toMatchArray(['outcome' => 'completed', 'costUsd' => null, 'steps' => 1, 'revision' => 'running'])
        ->and($criticThread->parent_thread_id)->toBe($builderThread->getKey())
        ->and($criticThread->root_run_id)->toStartWith('charlotte-'.$session->id());
});

test('the critic\'s own nested budget bounds it: over budget, no critic call and no revision', function (): void {
    $provider = new ScriptedProvider([critiqueBuildTurn(), criticReply(), critiqueRevisionTurn()]);
    critiqueApp($provider, ['charlotte.critique.max_cost_usd' => 0.0000001]);
    critiqueTree();
    $renders = [];
    critiqueRenderer($renders);
    $session = openSession();

    $session->send('Build the page.', 'user-7');

    $result = eventsOf($session, 'critique.result')[0]['data'];
    expect($session->record()->status)->toBe('idle')
        ->and($result['outcome'])->toBe('exhausted')
        ->and($result['revision'])->toBe('skipped')
        ->and(criticRequests($provider))->toBe([])
        ->and(eventsOf($session, 'run.started'))->toHaveCount(1);
});

test('the critic\'s model is configurable and defaults to a cheaper vision model for the provider', function (): void {
    $provider = new ScriptedProvider([critiqueBuildTurn(), criticReply(), critiqueRevisionTurn()]);
    charlotteApp(['charlotte.autonomy' => 'full', 'charlotte.critique.default_models' => ['scripted' => 'scripted-mini'], 'prism-harness.pricing' => CRITIQUE_PRICING], $provider);
    $renders = [];
    critiqueRenderer($renders);
    $session = openSession();
    $session->send('Build the page.', 'user-7');

    expect(criticRequests($provider)[0]->model())->toBe('scripted-mini')
        ->and(config('charlotte.critique.default_models'))->toBe(['scripted' => 'scripted-mini']);
    expect((require dirname(__DIR__, 2).'/config/charlotte.php')['critique']['default_models']['anthropic'])->toBe('claude-haiku-5-5');
});

test('a renderer that fails, or answers the wrong widths, costs nothing and fails nothing', function (): void {
    $provider = new ScriptedProvider([critiqueBuildTurn(), criticReply()]);
    critiqueApp($provider);
    Charlotte::screenshotsUsing(fn () => throw new RuntimeException('browser down: secret-host:9222'));
    $session = openSession();
    $session->send('Build the page.', 'user-7');

    $result = eventsOf($session, 'critique.result')[0]['data'];
    expect($session->record()->status)->toBe('idle')
        ->and($result)->toMatchArray(['outcome' => 'render_failed', 'revision' => 'skipped'])
        ->and(json_encode($result))->not->toContain('secret-host')
        ->and(criticRequests($provider))->toBe([]);

    $provider = new ScriptedProvider([critiqueBuildTurn(), criticReply()]);
    critiqueApp($provider);
    Charlotte::screenshotsUsing(fn (SessionRecord $s, string $p, string $r, array $widths) => [Screenshot::png(375, critiquePng())]);
    $session = openSession();
    $session->send('Build the page.', 'user-7');

    expect(eventsOf($session, 'critique.result')[0]['data']['outcome'])->toBe('render_failed')
        ->and(criticRequests($provider))->toBe([]);
});

test('a critic that answers outside its format revises nothing', function (): void {
    $provider = new ScriptedProvider([critiqueBuildTurn(), [['text' => 'Looks great, maybe bigger headings?']]]);
    critiqueApp($provider);
    $renders = [];
    critiqueRenderer($renders);
    $session = openSession();
    $session->send('Build the page.', 'user-7');

    expect(eventsOf($session, 'critique.result')[0]['data'])->toMatchArray(['outcome' => 'invalid_output', 'revision' => 'skipped'])
        ->and(eventsOf($session, 'run.started'))->toHaveCount(1);
});

test('a page that is not ready is never critiqued', function (): void {
    $provider = new ScriptedProvider([[['text' => 'I will not build anything.']], criticReply()]);
    critiqueApp($provider);
    $renders = [];
    critiqueRenderer($renders);
    $session = openSession();
    $session->send('Build the page.', 'user-7');

    expect($renders)->toBe([])->and(eventsOf($session, 'critique.result'))->toBe([]);
});
