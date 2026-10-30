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

    /** Called at the start of every stream call, e.g. to stop a session mid-turn. */
    public ?\Closure $onStream = null;

    public function stream(TextRequest $request): Generator
    {
        $this->requests[] = $request;
        if ($this->onStream !== null) {
            ($this->onStream)();
        }
        $steps = array_shift($this->turns) ?? [['text' => 'Done.']];
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
            // A step may END the call with a recorded finish reason, the way a
            // provider does when output hits max_tokens (FinishReason::Length).
            $finish = $step['finish'] ?? FinishReason::Stop;
            yield new StepFinishEvent('e-sf-'.$i, $at, new Usage(100, 20), [], $finish);
            if ($finish !== FinishReason::Stop) {
                yield new StreamEndEvent('e-end', $at, $finish, new Usage(100 * ($i + 1), 20 * ($i + 1)));

                return;
            }
        }

        yield new StreamEndEvent('e-end', $at, FinishReason::Stop, new Usage(100 * count($steps), 20 * count($steps)));
    }

    /** The decoded result of the Nth call to a tool. */
    public function result(string $tool, int $nth = 0): array
    {
        $matches = array_values(array_filter($this->calls, fn (array $c) => $c['name'] === $tool));

        return json_decode($matches[$nth]['result'] ?? 'null', true) ?? throw new RuntimeException("No call {$nth} to {$tool}.");
    }
}
