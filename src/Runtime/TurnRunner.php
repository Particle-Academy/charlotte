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
    public function __construct(
        private readonly Workspace $workspace,
        private readonly Guides $guides,
        private readonly ModeRegistry $modes,
        private readonly PrismHarness $harness,
        private readonly AgentRuntime $runtime,
    ) {}

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

            foreach ($this->runtime->stream($harness, $text, BuilderMode::TOOLS) as $event) {
                if ($event instanceof StreamStartEvent && $runId === null) {
                    $runId = (string) ($harness->run()['id'] ?? '');
                    $this->workspace->record($session->id, EventTypes::RUN_STARTED, Workspace::SYSTEM,
                        ['runId' => $runId, 'mode' => BuilderMode::NAME, 'provider' => $session->provider, 'model' => $session->model]);
                }
                $recorder->record($event);
            }
            $recorder->complete();
            if ($recorder->failed) {
                $this->fail($session, $runId, 'provider_error');

                return;
            }
            $this->workspace->record($session->id, EventTypes::RUN_FINISHED, Workspace::SYSTEM,
                ['runId' => $runId, 'finishReason' => $recorder->finishReason ?? 'unknown']);
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
