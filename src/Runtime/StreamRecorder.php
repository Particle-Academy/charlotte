<?php

declare(strict_types=1);

namespace Charlotte\Runtime;

use Charlotte\Events\EventTypes;
use Charlotte\Events\Ulid;
use Charlotte\Workspace;
use Closure;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;

/**
 * Turns the harness stream (Prism's own events, untouched) into log events.
 *
 * Text deltas are COALESCED: at most one `message.delta` per interval, plus a
 * flush when the message completes, so the log's write rate is bounded no
 * matter how finely a provider chunks. Tool arguments and results are capped
 * at 1200 bytes (the lab's cap), cut on a UTF-8 boundary.
 */
final class StreamRecorder
{
    public const CAP_BYTES = 1200;

    private ?string $messageId = null;

    private string $buffer = '';

    private string $text = '';

    private float $lastFlush = 0.0;

    public bool $failed = false;

    public ?string $finishReason = null;

    /** @var Closure(): float */
    private Closure $clock;

    public function __construct(
        private readonly Workspace $workspace,
        private readonly string $session,
        private readonly int $coalesceMs = 250,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? fn (): float => microtime(true);
    }

    public function record(StreamEvent $event): void
    {
        if ($event instanceof TextStartEvent) {
            $this->complete();
            $this->start();
        } elseif ($event instanceof TextDeltaEvent) {
            if ($this->messageId === null) {
                $this->start();
            }
            $this->buffer .= $event->delta;
            $this->text .= $event->delta;
            if ((($this->clock)() - $this->lastFlush) * 1000 >= $this->coalesceMs) {
                $this->flush();
            }
        } elseif ($event instanceof TextCompleteEvent || $event instanceof StepFinishEvent) {
            $this->complete();
        } elseif ($event instanceof ToolCallEvent) {
            $this->complete();
            [$args, $truncated] = self::cap(json_encode($event->toolCall->arguments(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
            $this->append(EventTypes::TOOL_CALL, Workspace::AGENT,
                ['callId' => $event->toolCall->id, 'tool' => $event->toolCall->name, 'args' => $args, 'truncated' => $truncated]);
        } elseif ($event instanceof ToolResultEvent) {
            $raw = $event->toolResult->result;
            $raw = is_string($raw) ? $raw : (json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
            $decoded = json_decode($raw, true);
            $error = is_array($decoded) && ($decoded['isError'] ?? false) === true;
            [$summary, $truncated] = self::cap($raw);
            $this->append(EventTypes::TOOL_RESULT, Workspace::AGENT, [
                'callId' => $event->toolResult->toolCallId, 'tool' => $event->toolResult->toolName,
                'ok' => ! $error && $event->success,
                'code' => $error ? (string) ($decoded['error']['code'] ?? 'tool_error') : ($event->success ? null : 'tool_error'),
                'revision' => is_array($decoded) && is_string($decoded['revision'] ?? null) ? $decoded['revision'] : null,
                'summary' => $summary, 'truncated' => $truncated,
            ]);
        } elseif ($event instanceof ErrorEvent) {
            $this->failed = true;
        } elseif ($event instanceof StreamEndEvent) {
            $this->complete();
            $this->finishReason = $event->finishReason->value;
        }
    }

    /** Close any open message; call when the stream ends or fails. */
    public function complete(): void
    {
        if ($this->messageId === null) {
            return;
        }
        $this->flush();
        if ($this->text !== '') {
            $this->append(EventTypes::MESSAGE_COMPLETED, Workspace::AGENT,
                ['messageId' => $this->messageId, 'role' => 'assistant', 'text' => $this->text]);
        }
        $this->messageId = null;
        $this->text = '';
    }

    private function start(): void
    {
        $this->messageId = 'm_'.Ulid::generate();
        $this->buffer = '';
        $this->text = '';
        $this->lastFlush = ($this->clock)();
    }

    private function flush(): void
    {
        if ($this->buffer !== '' && $this->messageId !== null) {
            $this->append(EventTypes::MESSAGE_DELTA, Workspace::AGENT,
                ['messageId' => $this->messageId, 'role' => 'assistant', 'text' => $this->buffer]);
        }
        $this->buffer = '';
        $this->lastFlush = ($this->clock)();
    }

    private function append(string $type, array $actor, array $data): void
    {
        $this->workspace->record($this->session, $type, $actor, $data);
    }

    /** @return array{0: string, 1: bool} */
    public static function cap(string $value): array
    {
        if (strlen($value) <= self::CAP_BYTES) {
            return [$value, false];
        }

        return [mb_strcut($value, 0, self::CAP_BYTES, 'UTF-8'), true];
    }
}
