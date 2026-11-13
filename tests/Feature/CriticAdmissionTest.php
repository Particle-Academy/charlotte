<?php

declare(strict_types=1);

use Charlotte\Critique\CriticMode;
use Charlotte\Critique\Screenshot;
use Charlotte\Critique\SpawnAdmission;
use Charlotte\Facades\Charlotte;
use Charlotte\Sessions\SessionRecord;
use Charlotte\Tests\Support\ScriptedProvider;
use Charlotte\Tests\Support\User;
use Prism\Harness\Exceptions\UnacceptableAttachment;
use Prism\Harness\Facades\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Subagents\Subagent;
use Prism\Harness\Subagents\SubagentRunner;
use Prism\Harness\Support\TurnAttachments;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

/*
 * DRIFT GUARD for a temporary duplicate (prism-harness gap G-265).
 *
 * The critic's whole input is screenshots, and SubagentRunner::run() takes no
 * media, so Charlotte spawns the critic itself and repeats the runner's
 * pre-spawn admission (SpawnAdmission) and the harness's media rules
 * (Screenshot). These tests put Charlotte's copy and the harness's original
 * through the SAME refusal cases, so a harness change that Charlotte has not
 * followed fails here instead of in production.
 *
 * Delete this file, SpawnAdmission and the spawn block in DesignLoop once
 * G-265 lands and the critic goes through SubagentRunner::run() with media.
 */

function admissionApp(?ScriptedProvider $provider = null): void
{
    charlotteApp([
        'prism-harness.agent.provider' => 'scripted',
        'prism-harness.agent.model' => 'test-model',
    ], $provider ?? new ScriptedProvider([]));
}

function admissionParent(): Session
{
    return PrismHarness::session(User::create(['name' => 'Ada']), 'charlotte:probe-'.bin2hex(random_bytes(4)));
}

function criticSubagent(int $steps = 1, ?float $cost = null): Subagent
{
    return new Subagent(CriticMode::NAME, 'probe', CriticMode::NAME, new RunBudget(maxSteps: $steps, maxCostUsd: $cost), CriticMode::SCOPE);
}

/**
 * The harness's refusal cases for spawning a sub-agent, each a fresh tree, so
 * Charlotte's copy and the runner see identical state.
 *
 * @return array<string, Closure(): array{0: RunContext, 1: Subagent}>
 */
function admissionCases(): array
{
    return [
        'step budget exhausted' => function (): array {
            $tree = RunContext::root('t', new RunBudget(maxSteps: 3));
            $tree->ledger->recordSteps(3);

            return [$tree, criticSubagent()];
        },
        'tree cancelled' => function (): array {
            $tree = RunContext::root('t', new RunBudget(maxSteps: 10));
            $tree->ledger->cancel('stopped by the person');

            return [$tree, criticSubagent()];
        },
        'cost budget exhausted' => function (): array {
            $tree = RunContext::root('t', new RunBudget(maxSteps: 10, maxCostUsd: 0.01));
            $tree->ledger->recordCost(0.02);

            return [$tree, criticSubagent()];
        },
        'cost cap unenforceable on an unmetered tree' => function (): array {
            $tree = RunContext::root('t', new RunBudget(maxSteps: 10, maxCostUsd: 1.0));
            $tree->ledger->recordCost(null);

            return [$tree, criticSubagent()];
        },
        'nesting too deep' => function (): array {
            $tree = RunContext::root('t', new RunBudget(maxSteps: 50));
            for ($depth = 1; $depth < RunContext::MAX_DEPTH; $depth++) {
                $tree = $tree->forChild(criticSubagent(5), 'run_'.$depth, null);
            }

            return [$tree, criticSubagent()];
        },
        'child narrowed to zero steps' => fn (): array => [RunContext::root('t', new RunBudget(maxSteps: 10)), criticSubagent(0)],
    ];
}

test('Charlotte\'s pre-spawn admission refuses exactly what SubagentRunner refuses, with the same outcome', function (): void {
    admissionApp();
    $runner = app(SubagentRunner::class);

    foreach (admissionCases() as $name => $case) {
        [$tree, $subagent] = $case();
        $harness = $runner->run($subagent, admissionParent(), $tree, 'run_parent', 'Probe.')->outcome->value;

        [$tree, $subagent] = $case();
        $parent = admissionParent();
        [$charlotte, $child] = SpawnAdmission::check($tree, $subagent, 'run_parent', $parent->thread()->getKey());

        expect($charlotte)->toBe($harness, $name)
            ->and($child)->toBeNull($name);
    }
});

test('a tree the harness would spawn on, Charlotte admits too, with the same nested child budget', function (): void {
    admissionApp(new ScriptedProvider([[['text' => '{"verdict": "pass", "items": []}']]]));
    $tree = RunContext::root('t', new RunBudget(maxSteps: 20, maxCostUsd: null));
    $tree->ledger->recordSteps(4);

    $parent = admissionParent();
    [$outcome, $child] = SpawnAdmission::check($tree, criticSubagent(), 'run_parent', $parent->thread()->getKey());
    $expected = $tree->forChild(criticSubagent(), 'run_parent', $parent->thread()->getKey());

    expect($outcome)->toBeNull()
        ->and($child)->not->toBeNull()
        ->and($child->budget)->toEqual($expected->budget)
        ->and($child->depth)->toBe(1)
        ->and($child->ledger)->toBe($tree->ledger, 'the ONE ledger, never a copy')
        ->and(app(SubagentRunner::class)->run(criticSubagent(), admissionParent(), $tree, 'run_parent', 'Probe.')->outcome->value)->toBe('completed');
});

test('tool scoping: the critic is offered exactly the tools the runner would give the same declared sub-agent', function (): void {
    // The runner resolves the CHILD mode's tools (toolNames null); so must Charlotte.
    $provider = new ScriptedProvider([[['text' => '{"verdict": "pass", "items": []}']]]);
    admissionApp($provider);
    app(SubagentRunner::class)->run(criticSubagent(), admissionParent(), RunContext::root('t', new RunBudget(maxSteps: 5)), 'run_parent', 'Probe.');
    $byRunner = array_map(fn ($tool) => $tool->name(), $provider->requests[0]->tools());

    $provider = new ScriptedProvider([critiqueBuildTurn(), [['text' => '{"verdict": "pass", "items": []}']]]);
    charlotteApp(['charlotte.autonomy' => 'full', 'charlotte.critique.model' => 'critic-model'], $provider);
    $renders = [];
    critiqueRenderer($renders);
    openSession()->send('Build the page.', 'user-7');
    $critic = array_values(array_filter($provider->requests, fn ($r) => str_contains(implode("\n", array_map(fn (SystemMessage $m) => $m->content, $r->systemPrompts())), CriticMode::MARKER)));

    expect($critic)->toHaveCount(1)
        ->and(array_map(fn ($tool) => $tool->name(), $critic[0]->tools()))->toBe($byRunner)
        ->and($byRunner)->toBe([]);
});

test('media bounds: every attachment the harness refuses, a Screenshot refuses too', function (): void {
    charlotteApp();
    $path = tempnam(sys_get_temp_dir(), 'charlotte-shot');
    file_put_contents($path, capturePng(375, 800));

    $refusedByHarness = [
        'a URL' => fn () => Image::fromUrl('https://example.com/shot.png', 'image/png'),
        'a local path' => fn () => Image::fromLocalPath($path, 'image/png'),
        'empty raw content' => fn () => Image::fromRawContent('', 'image/png'),
        'empty base64' => fn () => Image::fromBase64('', 'image/png'),
        'an empty file id' => fn () => Image::fromFileId(''),
    ];
    try {
        foreach ($refusedByHarness as $name => $make) {
            expect(fn () => TurnAttachments::admit('Critique.', [$make()]))->toThrow(UnacceptableAttachment::class)
                ->and(fn () => new Screenshot(375, $make()))->toThrow(InvalidArgumentException::class);
        }
    } finally {
        @unlink($path);
    }

    // And the other way round: what a Screenshot admits, the harness admits.
    expect(TurnAttachments::admit('Critique.', [Screenshot::png(375, capturePng(375, 800))->image]))->toHaveCount(1);
    // Stricter on purpose: a provider file id passes the harness, but has no pixels to check for native resolution.
    expect(TurnAttachments::admit('Critique.', [Image::fromFileId('file_abc')]))->toHaveCount(1)
        ->and(fn () => new Screenshot(375, Image::fromFileId('file_abc')))->toThrow(InvalidArgumentException::class);
});

test('a critique run passes media the harness refuses nowhere: a renderer that tries is render_failed, before any spawn', function (): void {
    $provider = new ScriptedProvider([critiqueBuildTurn(), criticReply()]);
    critiqueApp($provider);
    Charlotte::screenshotsUsing(fn (SessionRecord $s, string $p, string $r, array $widths): array => array_map(
        fn (int $w) => new Screenshot($w, Image::fromUrl('https://example.com/'.$w.'.png', 'image/png')), $widths));
    $session = openSession();
    $session->send('Build the page.', 'user-7');

    expect(eventsOf($session, 'critique.result')[0]['data']['outcome'])->toBe('render_failed')
        ->and(criticRequests($provider))->toBe([]);
});
