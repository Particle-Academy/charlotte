<?php

declare(strict_types=1);

namespace Charlotte\Tests\Support;

use Generator;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use RuntimeException;

/**
 * A hermetic Prism provider that EXECUTES the tools a run offers.
 *
 * Prism's own fake replays recorded tool calls and results without running a
 * handler, so a turn through it would never touch Charlotte's tools. This one
 * plays a script: each turn is a list of steps, each step may stream text and
 * call tools by name, and every call goes through the Tool the harness
 * resolved for the session. No network, ever.
 *
 * A step's `tools` entry may be a closure taking the results so far, so a
 * script can pin `basedOn` to a revision it only learns at run time.
 */
final class ScriptedProvider extends Provider
{
    /** @var list<TextRequest> */
    public array $requests = [];

    /** @var list<array{name:string,args:array,result:string}> */
    public array $calls = [];

    /** @param list<list<array{text?:string,tools?:list<array{name:string,args:array|\Closure}>}>> $turns */
    public function __construct(public array $turns = []) {}

    /**
     * The unplayed rest of a script whose call stopped at the request's
     * maxSteps, by run (system prompt), with the message count it stopped at.
     *
     * @var array<string, array{steps: list<array>, messages: int}>
     */
    private array $pending = [];

    /**
     * The steps this call plays, honouring the request's `maxSteps` the way a
     * real provider's tool loop does: a run metered one call at a time
     * (prism-harness's tree budget sends maxSteps 1) gets ONE script step per
     * provider call, and the rest on its next call. Without this a whole
     * multi-step script ran inside one call, so per-call step admission was
     * invisible to every test (the instrument, not the harness, hid it).
     *
     * @return array{0: list<array>, 1: bool} [steps to play now, whether more remain]
     */
    private function next(TextRequest $request): array
    {
        $key = sha1(json_encode(array_map(fn ($m) => $m->content, $request->systemPrompts())) ?: '');
        $count = count($request->messages());
        $pending = $this->pending[$key] ?? null;
        unset($this->pending[$key]);
        $steps = $pending !== null && $count > $pending['messages']
            ? $pending['steps']                              // the same run, continued
            : (array_shift($this->turns) ?? [['text' => 'Done.']]);
        $limit = max(1, $request->maxSteps());
        if (count($steps) <= $limit) {
            return [$steps, false];
        }
        $this->pending[$key] = ['steps' => array_slice($steps, $limit), 'messages' => $count];

        return [array_slice($steps, 0, $limit), true];
    }

    /** Called at the start of every stream call, e.g. to stop a session mid-turn. */
    public ?\Closure $onStream = null;

    public function stream(TextRequest $request): Generator
    {
        $this->requests[] = $request;
        if ($this->onStream !== null) {
            ($this->onStream)();
        }
        [$steps, $more] = $this->next($request);
        $message = 'msg_'.count($this->requests);
        $at = time();

        yield new StreamStartEvent('e-start', $at, $request->model(), 'scripted');
        $tools = [];
        foreach ($request->tools() as $tool) {
            $tools[$tool->name()] = $tool;
        }

        foreach ($steps as $i => $step) {
            if (isset($step['throw'])) {
                throw $step['throw'];
            }
            yield new StepStartEvent('e-step-'.$i, $at);
            $text = $step['text'] ?? '';
            if ($text !== '') {
                yield new TextStartEvent('e-ts-'.$i, $at, $message);
                foreach (str_split($text, 4) as $j => $chunk) {
                    yield new TextDeltaEvent('e-td-'.$i.'-'.$j, $at, $chunk, $message);
                }
                yield new TextCompleteEvent('e-tc-'.$i, $at, $message);
            }
            foreach ($step['tools'] ?? [] as $k => $call) {
                $args = $call['args'] instanceof \Closure ? ($call['args'])($this->calls) : $call['args'];
                $id = 'call_'.$i.'_'.$k;
                yield new ToolCallEvent('e-call-'.$i.'-'.$k, $at, new ToolCall($id, $call['name'], $args), $message);
                $tool = $tools[$call['name']] ?? throw new RuntimeException('Script called an unoffered tool: '.$call['name']);
                $output = $tool->handle(...$args);
                $result = is_string($output) ? $output : (string) json_encode($output);
                $this->calls[] = ['name' => $call['name'], 'args' => $args, 'result' => $result];
                yield new ToolResultEvent('e-result-'.$i.'-'.$k, $at, new ToolResult($id, $call['name'], $args, $result), $message);
            }
            // A step that called tools finishes `tool-calls`, as a real
            // provider's does; Prism drops the tool calls of a step that
            // finished any other way (they were never executed).
            // A step may also END the call with a recorded finish reason, the
            // way a provider does when output hits max_tokens (FinishReason::Length).
            $finish = $step['finish'] ?? (($step['tools'] ?? []) === [] ? FinishReason::Stop : FinishReason::ToolCalls);
            yield new StepFinishEvent('e-sf-'.$i, $at, new Usage(100, 20), [], $finish);
            if (! in_array($finish, [FinishReason::Stop, FinishReason::ToolCalls], true)) {
                yield new StreamEndEvent('e-end', $at, $finish, new Usage(100 * ($i + 1), 20 * ($i + 1)));

                return;
            }
        }

        // Stopped at maxSteps with script left: end the call as a provider does mid tool loop.
        yield new StreamEndEvent('e-end', $at, $more ? FinishReason::ToolCalls : FinishReason::Stop, new Usage(100 * count($steps), 20 * count($steps)));
    }

    /** @var list<TextRequest> the requests that came through text() (sub-agent runs use send, not stream) */
    public array $textRequests = [];

    /**
     * The non-streaming path: a harness `send()`, which is how prism-harness
     * runs a sub-agent. The same script format; the provider runs the tool
     * loop itself, as a real provider handler does. A step's `text` may be a
     * closure over the calls so far (a child replying with the reference its
     * last tool returned).
     */
    public function text(TextRequest $request): TextResponse
    {
        $this->requests[] = $request;
        $this->textRequests[] = $request;
        [$steps, $more] = $this->next($request);
        $tools = [];
        foreach ($request->tools() as $tool) {
            $tools[$tool->name()] = $tool;
        }
        $meta = new Meta('scripted-'.count($this->requests), $request->model());
        $done = [];
        $transcript = [];
        $allCalls = $allResults = [];
        $text = '';
        foreach ($steps as $i => $step) {
            $text = ($step['text'] ?? '') instanceof \Closure ? ($step['text'])($this->calls) : (string) ($step['text'] ?? '');
            $calls = $results = [];
            foreach ($step['tools'] ?? [] as $k => $call) {
                $args = $call['args'] instanceof \Closure ? ($call['args'])($this->calls) : $call['args'];
                $id = 'tcall_'.count($this->requests).'_'.$i.'_'.$k;
                // A model may name a tool it was not offered; it gets an error
                // back and nothing runs, as from a real provider.
                $tool = $tools[$call['name']] ?? null;
                $output = $tool === null ? 'Error: tool ['.$call['name'].'] was not offered.' : $tool->handle(...$args);
                $result = is_string($output) ? $output : (string) json_encode($output);
                $this->calls[] = ['name' => $call['name'], 'args' => $args, 'result' => $result];
                $calls[] = new ToolCall($id, $call['name'], $args);
                $results[] = new ToolResult($id, $call['name'], $args, $result);
            }
            array_push($allCalls, ...$calls);
            array_push($allResults, ...$results);
            // A step may END the call with a recorded finish reason, as a
            // provider does when output hits max_tokens (FinishReason::Length).
            $finish = $step['finish'] ?? ($calls === [] ? FinishReason::Stop : FinishReason::ToolCalls);
            $done[] = new Step($text, $finish, $calls, $results, [], new Usage(100, 20), $meta, $request->messages(), []);
            $transcript[] = new AssistantMessage($text, $calls);
            if ($results !== []) {
                $transcript[] = new ToolResultMessage($results);
            }
        }

        // Stopped at maxSteps with script left: report the tool calls, so the caller's loop continues.
        $last = $done === [] ? null : end($done)->finishReason;

        return new TextResponse(collect($done), $text, $more ? FinishReason::ToolCalls : ($last === FinishReason::Length ? FinishReason::Length : FinishReason::Stop),
            $more ? end($done)->toolCalls : $allCalls, $more ? end($done)->toolResults : $allResults,
            new Usage(100 * count($done), 20 * count($done)), $meta, collect([...$request->messages(), ...$transcript]));
    }

    /** The decoded result of the Nth call to a tool. */
    public function result(string $tool, int $nth = 0): array
    {
        $matches = array_values(array_filter($this->calls, fn (array $c) => $c['name'] === $tool));

        return json_decode($matches[$nth]['result'] ?? 'null', true) ?? throw new RuntimeException("No call {$nth} to {$tool}.");
    }
}
