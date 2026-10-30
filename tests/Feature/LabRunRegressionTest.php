<?php

declare(strict_types=1);

use Charlotte\Facades\Charlotte;
use Charlotte\Policy\ArrayAssetCatalogue;
use Charlotte\Runtime\Turn;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Charlotte\Policy\AssetCatalogue;
use Charlotte\Runtime\TurnRunner;
use Charlotte\Tests\Support\ScriptedProvider;
use Prism\Prism\Enums\FinishReason;

/*
 * Regressions from Charlotte's first paid lab run (flabs runs 146-149,
 * 2026-10-30, Charlotte 0.1.0, claude-sonnet-5, 4096 output tokens per call).
 * All four builds ended without a finished page. Evidence was read from a
 * copy of the lab database; nothing here calls a model.
 */

function labRun147(string $part): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../fixtures/lab-run-147-cms-apply.json'), true, 512, JSON_THROW_ON_ERROR)[$part];
}

/** A session set up the way the lab hosts test 39. */
function labRun147Session(): \Charlotte\CharlotteSession
{
    // The lab allowlists the brief's own origins; test 39's signup link is one.
    $app = charlotteApp(['charlotte.autonomy' => 'full', 'charlotte.links.allow_origins' => ['https://app.example.com']]);
    // ...and supplies the test's images; this is run 147's recorded cms_assets.
    $app->instance(AssetCatalogue::class, new ArrayAssetCatalogue([
        'hero' => ['alt' => 'Blank laptop, receipt and calculator on a cream desk.', 'width' => 1536, 'height' => 1024],
        'invoice' => ['alt' => 'Blank invoice paper and a pen beside a closed laptop.', 'width' => 1536, 'height' => 1024],
        'receipt' => ['alt' => 'Blank phone and paper receipt on a cream surface.', 'width' => 1536, 'height' => 1024],
        'tax' => ['alt' => 'Blank accounting papers and an unmarked calculator.', 'width' => 1536, 'height' => 1024],
    ]));

    return openSession();
}

/*
 * Run 147: 8 of 20 cms_apply calls sent `ops` as a JSON STRING (Python-style
 * ": " spacing, so the model wrote it, not a serializer), and every one of the
 * 8 is invalid JSON: one unclosed brace after a heading's `md` style. Each
 * came back invalid_request at `$.arguments` and nothing else, so the model
 * resent the same broken heading six more times, then fell back to one op per
 * call until the page budget refused the next call.
 */
test('run 147: every recorded string-ops call is told the field, that it is not JSON, and the unclosed brace', function (): void {
    $session = labRun147Session();
    $revision = tool($session, 'cms_get')['revision'];

    foreach (labRun147('strings') as $call) {
        $result = tool($session, 'cms_apply', ['ops' => $call['arguments']['ops'], 'basedOn' => $revision]);
        $error = $result['error']['errors'][0];

        expect($result['error']['code'])->toBe('invalid_request')
            ->and($error['path'])->toBe('$.arguments.ops')
            ->and($error['got'])->toContain('not valid JSON')
            ->and($error['got'])->toContain('1 unclosed {')
            ->and($error['got'])->toContain('not a string');
    }
});

test('run 147: the recorded accepted calls still replay, including the array re-send of the refused section', function (): void {
    $session = labRun147Session();
    $revision = tool($session, 'cms_get')['revision'];

    foreach (labRun147('build') as $i => $call) {
        $result = tool($session, 'cms_apply', ['ops' => $call['arguments']['ops'], 'basedOn' => $revision]);
        expect($result)->not->toHaveKey('isError', "call {$i} ({$call['callId']}) was refused: ".json_encode($result['error'] ?? null));
        $revision = $result['revision'];
    }

    expect($session->state()['doc']['nodes'])->toHaveKeys(['header', 'hero', 'features']);
});

test('ops sent as a string of VALID JSON is decoded and applied like an array', function (): void {
    charlotteApp(['charlotte.autonomy' => 'full']);
    $session = openSession();
    $revision = tool($session, 'cms_get')['revision'];

    $result = tool($session, 'cms_apply', ['ops' => json_encode(sectionOps()), 'basedOn' => $revision]);

    expect($result)->not->toHaveKey('isError')
        ->and($session->state()['doc']['nodes'])->toHaveKeys(['hero', 'hero-h']);
});

test('a malformed argument names its field, what was expected and what arrived', function (array $arguments, string $path, string $got): void {
    charlotteApp(['charlotte.autonomy' => 'full']);
    $session = openSession();
    $revision = tool($session, 'cms_get')['revision'];
    $arguments = array_map(fn ($v) => $v === '@rev' ? $revision : $v, $arguments);

    $result = tool($session, 'cms_apply', $arguments);

    expect($result['isError'])->toBeTrue()
        ->and($result['error']['code'])->toBe('invalid_request')
        ->and($result['error']['errors'][0])->toMatchArray(['index' => null, 'path' => $path])
        ->and($result['error']['errors'][0]['got'])->toContain($got)
        ->and($result['error']['errors'][0]['expected'])->toBeString()->not->toBe('')
        ->and($result['error']['errors'][0]['hint'])->toBeString();
})->with([
    'ops is a string that is not JSON' => [['ops' => 'insert the hero', 'basedOn' => '@rev'], '$.arguments.ops', 'not valid JSON'],
    'ops is a JSON object, not a list' => [['ops' => '{"t":"insert_node"}', 'basedOn' => '@rev'], '$.arguments.ops', 'a string holding JSON object'],
    'ops is a number' => [['ops' => 3, 'basedOn' => '@rev'], '$.arguments.ops', 'number'],
    'basedOn is missing' => [['ops' => []], '$.arguments.basedOn', 'missing'],
    'basedOn is empty' => [['ops' => [], 'basedOn' => ''], '$.arguments.basedOn', 'empty string'],
    'an undeclared field' => [['ops' => [], 'basedOn' => '@rev', 'actor' => 'human'], '$.arguments.actor', 'undeclared field'],
]);

test('a JSON-string ops is held to the same fancy-cms validation as an array', function (): void {
    charlotteApp(['charlotte.autonomy' => 'full']);
    $session = openSession();
    $revision = tool($session, 'cms_get')['revision'];

    // The recorded run-147 omission (no style), delivered the string way.
    $ops = '[{"t": "insert_node", "node": {"id": "h2", "type": "heading", "parent": null, "order": "a", "props": {"content": "Hi", "level": 2}}}]';
    $result = tool($session, 'cms_apply', ['ops' => $ops, 'basedOn' => $revision]);

    expect($result['error']['code'])->toBe('invalid_operation')
        ->and($result['error']['errors'][0]['path'])->toBe('$.ops.0.node.style');
});

/*
 * Run 146 (and 148/149 per the lab): the third call spent 3962 of its 4096
 * output tokens on adaptive thinking and stopped at the limit
 * (finishReason=length) with no tool call. The turn ended there, the
 * session went idle, and no cms_finish ever came. The pre-Charlotte builder
 * failed the same way (lane 358, run 144).
 *
 * The script replays the recorded call sequence: brief, describe and assets
 * in one step; five icon searches; then a step that produces nothing and ends
 * at the output limit.
 */
function labRun146Turn(): array
{
    return [
        ['tools' => [['name' => 'charlotte_brief', 'args' => []], ['name' => 'cms_describe', 'args' => []], ['name' => 'cms_assets', 'args' => []]]],
        ['tools' => array_map(fn (string $q) => ['name' => 'cms_icons', 'args' => ['query' => $q, 'cursor' => 0]], ['dumbbell', 'clock', 'map', 'check', 'users'])],
        ['finish' => FinishReason::Length],
    ];
}

/** A continuation that builds a section and finishes the page. */
function finishingTurn(): array
{
    return [
        ['tools' => [['name' => 'cms_get', 'args' => []]]],
        ['tools' => [['name' => 'cms_apply', 'args' => fn (array $calls) => [
            'ops' => sectionOps(),
            'basedOn' => json_decode(end($calls)['result'], true)['revision'],
        ]]]],
        ['tools' => [['name' => 'cms_finish', 'args' => fn (array $calls) => [
            'basedOn' => json_decode(end($calls)['result'], true)['revision'],
        ]]]],
        ['text' => 'Ready for review.'],
    ];
}

test('run 146: a turn cut off at the output limit continues instead of going idle unfinished', function (): void {
    $provider = new ScriptedProvider([labRun146Turn(), finishingTurn()]);
    charlotteApp(['charlotte.autonomy' => 'full'], $provider);
    $session = openSession();

    $session->send('Build the page from the approved brief. Read charlotte_brief and call cms_finish when the draft is ready.', 'lab');

    $started = eventsOf($session, 'run.started');
    $finished = eventsOf($session, 'run.finished');
    expect($provider->requests)->toHaveCount(2);
    $last = $provider->requests[1]->messages();

    expect($provider->requests)->toHaveCount(2)
        ->and($finished[0]['data']['finishReason'])->toBe('length')
        ->and($started)->toHaveCount(2)
        ->and($started[1]['data']['continues'])->toBe($started[0]['data']['runId'])
        ->and(end($last)->content)->toBe(TurnRunner::CONTINUATION)
        ->and(eventsOf($session, 'draft.ready'))->toHaveCount(1)
        ->and($session->record()->readyRevision)->not->toBeNull()
        ->and($session->record()->status)->toBe('idle');
});

test('continuations run on the host\'s one run context, so its caps bound the whole turn', function (): void {
    $provider = new ScriptedProvider([labRun146Turn(), finishingTurn()]);
    charlotteApp(['charlotte.autonomy' => 'full'], $provider);
    $contexts = 0;
    $context = RunContext::root('lab-turn', new RunBudget(maxSteps: 10, maxCostUsd: 5.0, maxOutputTokensPerCall: 4096),
        pricing: ['scripted' => ['test-model' => ['inputPerMTok' => 2.0, 'outputPerMTok' => 10.0, 'toolOverheadTokens' => 474]]]);
    Charlotte::contextUsing(function (Turn $turn) use (&$contexts, $context): RunContext {
        $contexts++;

        return $context;
    });
    $session = openSession();

    $session->send('Build it.', 'lab');

    expect($contexts)->toBe(1)
        ->and($provider->requests)->toHaveCount(2)
        ->and($context->ledger->steps())->toBe(2)
        ->and($session->record()->status)->toBe('idle');
});

test('continuations are bounded: a turn that keeps hitting the limit fails with output_limit', function (): void {
    $cut = [['text' => 'Thinking.', 'finish' => FinishReason::Length]];
    $provider = new ScriptedProvider([$cut, $cut, $cut, $cut, $cut, $cut]);
    charlotteApp(['charlotte.autonomy' => 'full', 'charlotte.max_continuations' => 2], $provider);
    $session = openSession();

    $session->send('Build it.', 'lab');

    expect($provider->requests)->toHaveCount(3)
        ->and(eventsOf($session, 'run.failed')[0]['data']['code'])->toBe('output_limit')
        ->and($session->record()->status)->toBe('failed');
});

test('a turn that hits the limit after the page is ready is not continued', function (): void {
    $done = [...array_slice(finishingTurn(), 0, 3), ['text' => 'A long summary', 'finish' => FinishReason::Length]];
    $provider = new ScriptedProvider([$done]);
    charlotteApp(['charlotte.autonomy' => 'full'], $provider);
    $session = openSession();

    $session->send('Build it.', 'lab');

    expect($provider->requests)->toHaveCount(1)
        ->and(eventsOf($session, 'run.finished')[0]['data']['finishReason'])->toBe('length')
        ->and($session->record()->status)->toBe('idle');
});

test('a stop requested during a cut-off turn is not continued', function (): void {
    $provider = new ScriptedProvider([[['text' => 'Thinking.', 'finish' => FinishReason::Length]], finishingTurn()]);
    $app = charlotteApp(['charlotte.autonomy' => 'full'], $provider);
    $session = openSession();
    $provider->onStream = fn () => $session->stop('lab');

    $session->send('Build it.', 'lab');

    expect($provider->requests)->toHaveCount(1)
        ->and($session->record()->status)->toBe('stopped');
});
