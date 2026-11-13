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

/** Registers a renderer that records each call and returns one viewport capture per width, at native resolution. */
function critiqueRenderer(array &$calls): void
{
    Charlotte::screenshotsUsing(function (SessionRecord $session, string $page, string $revision, array $widths) use (&$calls): array {
        $calls[] = ['page' => $page, 'revision' => $revision, 'widths' => $widths];

        return array_map(fn (int $width): Screenshot => Screenshot::png($width, capturePng($width, 800)), $widths);
    });
}

/** The builder's revision message: the one user message that opens the revision run. */
function revisionUserMessage(ScriptedProvider $provider): ?UserMessage
{
    foreach (array_reverse($provider->requests) as $request) {
        if ($request->model() !== 'test-model') {
            continue;
        }
        foreach ($request->messages() as $message) {
            if ($message instanceof UserMessage && str_contains($message->content, 'A design critic')) {
                return $message;
            }
        }
    }

    return null;
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

    return [['text' => json_encode(['verdict' => $verdict, 'items' => $items], JSON_THROW_ON_ERROR)]];
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
        ->and($result[0]['data'])->not->toHaveKey('scores')
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

test('the critic prompt names observable defects in visual design and responsive layout, and differs from a final reviewer\'s', function (): void {
    $prompt = CriticMode::prompt();

    expect($prompt)->toContain('visual design')
        ->and($prompt)->toContain('responsive')
        ->and($prompt)->toContain('observable defect')
        ->and($prompt)->toContain('the hero has no supporting visual at 1280')
        ->and($prompt)->not->toContain('brief_fit')
        ->and($prompt)->not->toContain('accessibility (')
        ->and($prompt)->not->toContain('editability');
});

test('the critic prompt sets no score target and never asks to raise a score', function (): void {
    $prompt = mb_strtolower(CriticMode::prompt());

    // Prism maintainers: a critic told to move a page up a scale teaches the
    // builder to please the judge. It names defects it can see; nothing scores.
    foreach (['score', 'scale', 'rating', 'rate ', 'calibrat', 'raise', 'higher', 'move this page', 'up that', '/100', '0-100', ' 85', ' 95', ' 70'] as $word) {
        expect($prompt)->not->toContain($word);
    }
    expect(defined(CriticMode::class.'::ANCHORS'))->toBeFalse();
});

test('the builder gets the items as framed data, and the critic has no scores to leak', function (): void {
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
    expect($result)->toMatchArray(['outcome' => 'completed', 'costUsd' => null, 'steps' => 1, 'revision' => 'running', 'costCap' => 'dropped_unmetered'])
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
    Charlotte::screenshotsUsing(fn (SessionRecord $s, string $p, string $r, array $widths) => [Screenshot::png(375, capturePng(375, 800))]);
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

// --- Unmetered trees: dropping the critic's dollar cap is explicit config -------

test('on an unmetered tree the critic\'s dollar cap is dropped only because config allows it, and the event says so; one step stays the bound', function (): void {
    $provider = new ScriptedProvider([critiqueBuildTurn(), criticReply(), critiqueRevisionTurn()]);
    charlotteApp(['charlotte.autonomy' => 'full', 'charlotte.critique.model' => 'critic-model'], $provider);
    $renders = [];
    critiqueRenderer($renders);
    $session = openSession();
    $session->send('Build the page.', 'user-7');

    $result = eventsOf($session, 'critique.result')[0]['data'];
    expect($result)->toMatchArray(['outcome' => 'completed', 'costCap' => 'dropped_unmetered', 'costUsd' => null, 'steps' => 1])
        ->and($result['budget'])->toBe(['maxCostUsd' => null, 'maxSteps' => 1])
        ->and((require dirname(__DIR__, 2).'/config/charlotte.php')['critique']['allow_uncapped_when_unmetered'])->toBeTrue();
});

test('with allow_uncapped_when_unmetered off, an unmetered tree runs no critic at all', function (): void {
    $provider = new ScriptedProvider([critiqueBuildTurn(), criticReply(), critiqueRevisionTurn()]);
    charlotteApp(['charlotte.autonomy' => 'full', 'charlotte.critique.model' => 'critic-model', 'charlotte.critique.allow_uncapped_when_unmetered' => false], $provider);
    $renders = [];
    critiqueRenderer($renders);
    $session = openSession();
    $session->send('Build the page.', 'user-7');

    $result = eventsOf($session, 'critique.result')[0]['data'];
    expect($session->record()->status)->toBe('idle')
        ->and($result)->toMatchArray(['outcome' => 'unmetered', 'costCap' => 'refused_unmetered', 'revision' => 'skipped'])
        ->and(criticRequests($provider))->toBe([])
        ->and(Thread::query()->where('scope', 'charlotte:'.$session->id().'::sub::'.CriticMode::SCOPE)->exists())->toBeFalse()
        ->and(eventsOf($session, 'run.started'))->toHaveCount(1);
});

test('on a metered tree the critic\'s dollar cap applies, whatever the unmetered setting', function (): void {
    $provider = new ScriptedProvider([critiqueBuildTurn(), criticReply(), critiqueRevisionTurn()]);
    critiqueApp($provider, ['charlotte.critique.allow_uncapped_when_unmetered' => false]);
    critiqueTree();
    $renders = [];
    critiqueRenderer($renders);
    $session = openSession();
    $session->send('Build the page.', 'user-7');

    $result = eventsOf($session, 'critique.result')[0]['data'];
    expect($result)->toMatchArray(['outcome' => 'completed', 'costCap' => 'applied', 'steps' => 1])
        ->and($result['budget'])->toBe(['maxCostUsd' => 0.05, 'maxSteps' => 1]);
});

// --- The revision: node-located items AND the capture each one concerns -------

test('the builder\'s revision carries the items and the capture of each item\'s breakpoint, at native resolution', function (): void {
    $provider = new ScriptedProvider([critiqueBuildTurn(), criticReply(), critiqueRevisionTurn()]);
    critiqueApp($provider);
    $renders = [];
    critiqueRenderer($renders);
    $session = openSession();
    $session->send('Build the page.', 'user-7');

    $message = revisionUserMessage($provider);
    $result = eventsOf($session, 'critique.result')[0]['data'];
    expect($message)->not->toBeNull()
        ->and($message->content)->toContain('hero-h')
        ->and($message->content)->toContain('375-1')
        ->and($message->content)->toContain('768-1')
        ->and(array_map(fn ($image) => $image->rawContent(), $message->images()))->toBe([capturePng(375, 800), capturePng(768, 800)], 'the 375 and 768 captures, byte for byte; 1280 concerns no item')
        ->and($result['revisionIncludesScreenshot'])->toBeTrue()
        ->and($result['revisionImages'])->toBe(['375-1', '768-1']);
});

test('revision_includes_screenshot off sends the items as text only, for the lab\'s A/B', function (): void {
    $provider = new ScriptedProvider([critiqueBuildTurn(), criticReply(), critiqueRevisionTurn()]);
    critiqueApp($provider, ['charlotte.critique.revision_includes_screenshot' => false]);
    $renders = [];
    critiqueRenderer($renders);
    $session = openSession();
    $session->send('Build the page.', 'user-7');

    $message = revisionUserMessage($provider);
    $result = eventsOf($session, 'critique.result')[0]['data'];
    expect($message)->not->toBeNull()
        ->and($message->content)->toContain('hero-h')
        ->and($message->images())->toBe([])
        ->and($result)->toMatchArray(['revisionIncludesScreenshot' => false, 'revisionImages' => [], 'revision' => 'running'])
        ->and(eventsOf($session, 'critique.revised')[0]['data']['ready'])->toBeTrue();
});

test('an item that cites a capture region gets that capture only', function (): void {
    $provider = new ScriptedProvider([critiqueBuildTurn(), criticReply('revise', [
        ['node' => 'plans-grid', 'region' => '375-2', 'criterion' => 'responsive', 'issue' => 'The two cards squeeze to 150px each at 375.', 'fix' => 'One column below md.'],
    ]), critiqueRevisionTurn()]);
    critiqueApp($provider);
    Charlotte::screenshotsUsing(fn (SessionRecord $s, string $p, string $r, array $widths): array => [
        Screenshot::png(375, capturePng(375, 812), part: 1),
        Screenshot::png(375, capturePng(375, 700), part: 2),
        Screenshot::png(768, capturePng(768, 1024)),
        Screenshot::png(1280, capturePng(1280, 800)),
    ]);
    $session = openSession();
    $session->send('Build the page.', 'user-7');

    $result = eventsOf($session, 'critique.result')[0]['data'];
    expect($result['items'][0])->toMatchArray(['node' => 'plans-grid', 'viewport' => 375, 'region' => '375-2'])
        ->and($result['revisionImages'])->toBe(['375-2'])
        ->and(array_map(fn ($image) => $image->rawContent(), revisionUserMessage($provider)->images()))->toBe([capturePng(375, 700)]);
});

// --- Critic inputs: per viewport, native resolution, located items -------------

test('the critic gets every capture per viewport at native resolution, in width then section order, labelled', function (): void {
    $provider = new ScriptedProvider([critiqueBuildTurn(), criticReply(), critiqueRevisionTurn()]);
    critiqueApp($provider);
    Charlotte::screenshotsUsing(fn (SessionRecord $s, string $p, string $r, array $widths): array => [
        Screenshot::png(1280, capturePng(1280, 800)),
        Screenshot::png(375, capturePng(375, 700), part: 2),
        Screenshot::png(375, capturePng(375, 812), part: 1),
        Screenshot::png(768, capturePng(1536, 2048)), // device pixel ratio 2: native, not downscaled
    ]);
    $session = openSession();
    $session->send('Build the page.', 'user-7');

    $result = eventsOf($session, 'critique.result')[0]['data'];
    $messages = criticRequests($provider)[0]->messages();
    $last = end($messages);
    expect($result['outcome'])->toBe('completed')
        ->and(array_column($result['captures'], 'label'))->toBe(['375-1', '375-2', '768-1', '1280-1'])
        ->and($result['captures'][2])->toBe(['label' => '768-1', 'part' => 1, 'pixelHeight' => 2048, 'pixelWidth' => 1536, 'scale' => 2, 'width' => 768])
        ->and($result['capturesOmitted'])->toBe(0)
        ->and(array_map(fn ($image) => $image->rawContent(), $last->images()))->toBe([capturePng(375, 812), capturePng(375, 700), capturePng(1536, 2048), capturePng(1280, 800)])
        ->and($last->content)->toContain('375-2')
        ->and($last->content)->toContain('section 2 of 2');
});

test('a downscaled capture is refused where it is built, and a tall strip is never sent', function (): void {
    // A whole 375 px page squeezed into a 1568 px-tall image comes out narrower than 375.
    expect(fn () => Screenshot::png(375, capturePng(140, 1568)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Screenshot::png(1280, capturePng(1000, 800)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Screenshot::png(375, capturePng(1500, 3200)))->toThrow(InvalidArgumentException::class, 'device pixel ratio');

    $provider = new ScriptedProvider([critiqueBuildTurn(), criticReply()]);
    critiqueApp($provider);
    Charlotte::screenshotsUsing(fn (SessionRecord $s, string $p, string $r, array $widths): array => array_map(fn (int $w) => Screenshot::png($w, capturePng($w, 4000)), $widths));
    $session = openSession();
    $session->send('Build the page.', 'user-7');

    expect(eventsOf($session, 'critique.result')[0]['data'])->toMatchArray(['outcome' => 'render_failed', 'renderProblem' => 'capture_too_tall', 'revision' => 'skipped'])
        ->and(criticRequests($provider))->toBe([]);

    // The ceiling is a setting: a host that wants taller sections says so.
    $provider = new ScriptedProvider([critiqueBuildTurn(), criticReply('pass', [])]);
    critiqueApp($provider, ['charlotte.critique.max_capture_height' => 4000]);
    Charlotte::screenshotsUsing(fn (SessionRecord $s, string $p, string $r, array $widths): array => array_map(fn (int $w) => Screenshot::png($w, capturePng($w, 4000)), $widths));
    $session = openSession();
    $session->send('Build the page.', 'user-7');

    expect(eventsOf($session, 'critique.result')[0]['data']['outcome'])->toBe('completed');
});

test('sections past max_captures_per_width are left out, from the bottom, and counted', function (): void {
    $provider = new ScriptedProvider([critiqueBuildTurn(), criticReply(), critiqueRevisionTurn()]);
    critiqueApp($provider, ['charlotte.critique.max_captures_per_width' => 2]);
    Charlotte::screenshotsUsing(function (SessionRecord $s, string $p, string $r, array $widths): array {
        $shots = [];
        foreach ($widths as $w) {
            foreach ([1, 2, 3] as $part) {
                $shots[] = Screenshot::png($w, capturePng($w, 800), part: $part);
            }
        }

        return $shots;
    });
    $session = openSession();
    $session->send('Build the page.', 'user-7');

    $result = eventsOf($session, 'critique.result')[0]['data'];
    $messages = criticRequests($provider)[0]->messages();
    expect(array_column($result['captures'], 'label'))->toBe(['375-1', '375-2', '768-1', '768-2', '1280-1', '1280-2'])
        ->and($result['capturesOmitted'])->toBe(3)
        ->and(end($messages)->images())->toHaveCount(6);
});

test('sections must be numbered 1..n per width, or the render is refused', function (): void {
    $provider = new ScriptedProvider([critiqueBuildTurn(), criticReply()]);
    critiqueApp($provider);
    Charlotte::screenshotsUsing(fn (SessionRecord $s, string $p, string $r, array $widths): array => [
        Screenshot::png(375, capturePng(375, 800), part: 2),
        Screenshot::png(768, capturePng(768, 800)),
        Screenshot::png(1280, capturePng(1280, 800)),
    ]);
    $session = openSession();
    $session->send('Build the page.', 'user-7');

    expect(eventsOf($session, 'critique.result')[0]['data'])->toMatchArray(['outcome' => 'render_failed', 'renderProblem' => 'sections_out_of_order'])
        ->and(criticRequests($provider))->toBe([]);
});

test('every critic item must cite a real node AND a breakpoint or capture region; the rest are dropped and counted by reason', function (): void {
    $provider = new ScriptedProvider([critiqueBuildTurn(), criticReply('revise', [
        ['node' => 'hero-h', 'viewport' => 375, 'criterion' => 'visual_design', 'issue' => 'The h1 is body size at 375.', 'fix' => '40px.'],
        ['node' => 'plans-grid', 'region' => '768-1', 'criterion' => 'responsive', 'issue' => 'A wide empty gutter at 768.', 'fix' => 'Gap 32px.'],
        ['node' => 'hero-h', 'criterion' => 'visual_design', 'issue' => 'Somewhere the h1 is small.', 'fix' => 'Bigger.'],
        ['node' => 'hero-h', 'viewport' => 1024, 'issue' => 'Not a width we captured.', 'fix' => 'x'],
        ['node' => 'hero-h', 'region' => '375-9', 'issue' => 'No such capture.', 'fix' => 'x'],
        ['node' => 'hero-h', 'viewport' => 1280, 'region' => '375-1', 'issue' => 'Breakpoint and region disagree.', 'fix' => 'x'],
        ['node' => 'ghost', 'viewport' => 375, 'issue' => 'Not on the page.', 'fix' => 'x'],
        ['node' => 'hero-h', 'viewport' => 375, 'issue' => '   ', 'fix' => 'x'],
    ]), critiqueRevisionTurn()]);
    critiqueApp($provider);
    $renders = [];
    critiqueRenderer($renders);
    $session = openSession();
    $session->send('Build the page.', 'user-7');

    $result = eventsOf($session, 'critique.result')[0]['data'];
    expect(array_column($result['items'], 'node'))->toBe(['hero-h', 'plans-grid'])
        ->and($result['items'][0])->toMatchArray(['viewport' => 375, 'region' => null])
        ->and($result['items'][1])->toMatchArray(['viewport' => 768, 'region' => '768-1'])
        ->and($result['dropped'])->toBe(6)
        ->and($result['droppedReasons'])->toBe(['incomplete' => 1, 'over_cap' => 0, 'unknown_node' => 1, 'unlocated' => 4]);
});

// --- Telemetry for the A/B: before and after, both readable --------------------

test('the A/B record: the ready revision before the revision and the one after, each readable as a document', function (): void {
    $provider = new ScriptedProvider([critiqueBuildTurn(), criticReply(), critiqueRevisionTurn()]);
    critiqueApp($provider);
    $renders = [];
    critiqueRenderer($renders);
    $session = openSession();
    $session->send('Build the page.', 'user-7');

    $result = eventsOf($session, 'critique.result')[0]['data'];
    $revised = eventsOf($session, 'critique.revised')[0]['data'];
    expect($revised)->toMatchArray(['before' => $result['revisionOf'], 'after' => $session->record()->readyRevision, 'ready' => true, 'changed' => true, 'revisions' => 1]);

    $before = $session->documentAt($revised['before']);
    $after = $session->documentAt($revised['after']);
    expect($before)->not->toBeNull()
        ->and((array) ($before['nodes']['hero-h']['style']['base']['fontSize'] ?? []))->not->toBe(['value' => 44, 'unit' => 'px'])
        ->and((array) ($after['nodes']['hero-h']['style']['base']['fontSize'] ?? []))->toBe(['value' => 44, 'unit' => 'px'])
        ->and($after)->toEqual($session->state()['doc'])
        ->and($before)->not->toEqual($after)
        ->and($session->documentAt('r'.str_repeat('0', 32)))->toBeNull();
});

test('a revision run that leaves the page unfinished records no after revision', function (): void {
    $provider = new ScriptedProvider([critiqueBuildTurn(), criticReply(), [
        ['tools' => [['name' => 'cms_get', 'args' => []]]],
        ['tools' => [['name' => 'cms_apply', 'args' => fn (array $calls) => ['ops' => [
            ['t' => 'set_style', 'id' => 'hero-h', 'breakpoint' => 'base', 'patch' => ['fontSize' => ['value' => 44, 'unit' => 'px']]],
        ], 'basedOn' => json_decode(end($calls)['result'], true)['revision']]]]],
        ['text' => 'Applied; not finishing.'],
    ]]);
    critiqueApp($provider);
    $renders = [];
    critiqueRenderer($renders);
    $session = openSession();
    $session->send('Build the page.', 'user-7');

    $result = eventsOf($session, 'critique.result')[0]['data'];
    $revised = eventsOf($session, 'critique.revised')[0]['data'];
    expect($revised)->toMatchArray(['before' => $result['revisionOf'], 'after' => null, 'ready' => false, 'changed' => true])
        ->and($revised['revision'])->toBe($session->state()['revision'])
        ->and($session->documentAt($revised['revision']))->toEqual($session->state()['doc']);
});
