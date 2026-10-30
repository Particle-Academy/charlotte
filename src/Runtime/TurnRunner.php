<?php

declare(strict_types=1);

namespace Charlotte\Runtime;

use Charlotte\Events\EventTypes;
use Charlotte\Guide\BuilderMode;
use Charlotte\Guide\Guides;
use Charlotte\Policy\Refused;
use Charlotte\Sessions\SessionRecord;
use Charlotte\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Contracts\HasErrorCode;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\PrismHarness;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Throwable;

/**
 * Runs one queued turn: the harness session in `charlotte_builder` mode, on
 * the session's provider and model, streamed into the event log.
 *
 * Failures reach the log as a CODE only. Provider messages can carry URLs
 * with keys in them, so no exception message is ever recorded.
 */
final class TurnRunner
{
    /** Sent as the next user message when a run stops at the output limit. */
    public const CONTINUATION = 'Your last response stopped at the output limit before the page was finished. '
        .'Continue from the current draft: keep your reasoning short, send one section per cms_apply as a small batch, '
        .'and call cms_finish when the page is ready.';

    public function __construct(
        private readonly Workspace $workspace,
        private readonly Guides $guides,
        private readonly ModeRegistry $modes,
        private readonly PrismHarness $harness,
        private readonly AgentRuntime $runtime,
        private readonly TurnContextFactory $contexts = new HarnessDefaults,
        private readonly int $maxContinuations = 3,
    ) {}

    /** The page was marked ready at the revision it is at now. */
    private function ready(string $sessionId): bool
    {
        $session = $this->workspace->session($sessionId);

        return $session->readyRevision !== null
            && hash_equals($session->readyRevision, (string) $this->workspace->view($session)['revision']);
    }

    public function run(string $sessionId, string $text): void
    {
        $session = $this->workspace->session($sessionId);
        if ($session->status !== 'queued') {
            return; // already run, or never queued: a redelivered job does nothing
        }
        if ($session->stopped()) {
            $this->workspace->status($session, 'stopped');

            return;
        }
        $this->workspace->status($session, 'running');
        $recorder = new StreamRecorder($this->workspace, $session->id);
        $runId = null;
        try {
            $guide = $this->guides->current();
            $mode = $this->modes->resolve(BuilderMode::NAME);
            if (! hash_equals($session->guideHash, $guide->guideHash)) {
                throw new Refused('guide_mode_stale');
            }
            BuilderMode::assertEmbeds($mode, $guide);

            $harness = $this->harness->session($this->participant($session), $session->scope())
                ->usingMode(BuilderMode::NAME)
                ->usingProvider($session->provider)
                ->usingModel($session->model)
                ->usingCapability('charlotte', ['session' => $session->id]);

            // The host's run context for this turn (budget, pricing, cost
            // observer), or null for the harness defaults. Inside the try: a
            // host refusing admission fails the turn with its code, before
            // any provider call.
            $context = $this->contexts->forTurn(new Turn($session, $harness, $text));

            // One turn may span several runs: a response cut off at the output
            // limit (`length`) before the page is ready is continued, up to
            // maxContinuations times, on the SAME run context, so a host's
            // step and cost caps bound the whole turn. Without this a model
            // that spends its output on reasoning ends the turn with nothing
            // built (flabs runs 146, 148, 149; and lane 358 before Charlotte).
            $prompt = $text;
            $continues = null;
            $continuations = 0;
            while (true) {
                $runId = null;
                foreach ($this->runtime->stream($harness, $prompt, BuilderMode::TOOLS, context: $context) as $event) {
                    if ($event instanceof StreamStartEvent && $runId === null) {
                        $runId = (string) ($harness->run()['id'] ?? '');
                        $this->workspace->record($session->id, EventTypes::RUN_STARTED, Workspace::SYSTEM,
                            ['runId' => $runId, 'mode' => BuilderMode::NAME, 'provider' => $session->provider, 'model' => $session->model]
                            + ($continues === null ? [] : ['continues' => $continues]));
                    }
                    $recorder->record($event);
                }
                $recorder->complete();
                if ($recorder->failed) {
                    $this->fail($session, $runId, 'provider_error');

                    return;
                }
                $finish = $recorder->finishReason ?? 'unknown';
                $recorder->finishReason = null;
                if ($finish === 'length' && ! $this->workspace->session($session->id)->stopped() && ! $this->ready($session->id)) {
                    if ($continuations >= $this->maxContinuations) {
                        $this->fail($session, $runId, 'output_limit');

                        return;
                    }
                    $this->workspace->record($session->id, EventTypes::RUN_FINISHED, Workspace::SYSTEM,
                        ['runId' => $runId, 'finishReason' => $finish]);
                    $continuations++;
                    $continues = $runId;
                    $prompt = self::CONTINUATION;

                    continue;
                }
                $this->workspace->record($session->id, EventTypes::RUN_FINISHED, Workspace::SYSTEM,
                    ['runId' => $runId, 'finishReason' => $finish]);
                break;
            }
            $this->settle($session, 'idle');
        } catch (Throwable $e) {
            $recorder->complete();
            $this->fail($session, $runId, match (true) {
                $e instanceof Refused => $e->reason,
                $e instanceof HasErrorCode => $e->code(),
                default => 'run_failed',
            });
        }
    }

    private function fail(SessionRecord $session, ?string $runId, string $code): void
    {
        $this->workspace->record($session->id, EventTypes::RUN_FAILED, Workspace::SYSTEM, ['runId' => $runId, 'code' => $code]);
        $this->settle($session, 'failed');
    }

    /** A stop requested during the run wins over the run's own outcome. */
    private function settle(SessionRecord $session, string $status): void
    {
        $fresh = $this->workspace->session($session->id);
        $this->workspace->status($fresh, $fresh->stopped() ? 'stopped' : $status);
    }

    private function participant(SessionRecord $session): Model
    {
        $class = Relation::getMorphedModel($session->participantType) ?? $session->participantType;
        if (! is_string($class) || ! is_subclass_of($class, Model::class)) {
            throw new Refused('session_missing');
        }

        return $class::query()->find($session->participantId) ?? throw new Refused('session_missing');
    }
}
