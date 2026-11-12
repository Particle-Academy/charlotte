<?php

declare(strict_types=1);

namespace Charlotte\Runtime;

use Charlotte\Guide\BuilderMode;
use Charlotte\Policy\Refused;
use Charlotte\Sessions\SessionRecord;
use Charlotte\Storage\SessionStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Closure;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Subagents\Subagent;
use Prism\Harness\Subagents\SubagentRunner;
use Prism\Prism\Tool;

/**
 * Charlotte as a prism-harness SUB-AGENT, on the harness's existing API.
 *
 * prism-harness spawns a child at `<parent scope>::sub::<suffix>`, on the
 * parent's participant, in the declared mode, and gives it nothing else. So
 * the HOST binds that address before the parent runs: the Charlotte session
 * it acts on (as capability state, which no model output reaches), and the
 * provider and model the child runs on. The child's tools then resolve from
 * that binding alone; tenant, page and actor never come from the task text.
 *
 * Two callers: {@see TurnRunner} for a site orchestrator's page sub-agent,
 * and {@see \Charlotte\Charlotte::delegate()} for a host's own agent.
 */
final class PageSubagents
{
    public const CAPABILITY = 'charlotte';

    /** @var array<string, array{parent: Session, tree: RunContext}> site session id => the turn in flight */
    private array $turns = [];

    /**
     * @param  (Closure(): SubagentRunner)|null  $runner
     * @param  (Closure(): ModeRegistry)|null  $modes
     * @param  (Closure(): array<string, mixed>)|null  $config  prism-harness.agent
     */
    public function __construct(
        private readonly PrismHarness $harness,
        private readonly SessionStore $sessions,
        private readonly ?Closure $runner = null,
        private readonly ?Closure $modes = null,
        private readonly ?Closure $config = null,
    ) {}

    /**
     * The root a site turn runs on when the host gives none: the budget
     * prism-harness would have built for the run itself (the mode's ceiling,
     * the harness's own cost, time and output caps), so passing it changes
     * nothing but who holds the reference. The stream and every page
     * sub-agent then share its ledger.
     */
    public function defaultTree(SessionRecord $session, int $maxSteps): RunContext
    {
        $config = $this->config === null ? [] : ($this->config)();
        $number = fn (string $key): ?float => is_numeric($config[$key] ?? null) ? (float) $config[$key] : null;

        return RunContext::root('charlotte-'.$session->id.'-'.bin2hex(random_bytes(6)), new RunBudget(
            maxSteps: $maxSteps,
            maxCostUsd: $number('max_cost_usd'),
            maxSeconds: ($s = $number('max_seconds')) === null ? null : (int) $s,
            maxOutputTokensPerCall: ($t = $number('max_output_tokens_per_call')) === null ? null : (int) $t,
        ));
    }

    /** A site turn starts: its harness session and the run tree its page sub-agents spend from. */
    public function begin(SessionRecord $session, Session $parent, RunContext $tree): void
    {
        $this->turns[$session->id] = ['parent' => $parent, 'tree' => $tree];
    }

    public function end(string $sessionId): void
    {
        unset($this->turns[$sessionId]);
    }

    /**
     * The orchestrator's page sub-agent tool, while one of its turns runs.
     *
     * It is prism-harness's own: the declared {@see Subagent} from the site
     * mode, run by the harness's {@see SubagentRunner} (own address, own
     * thread linked beneath the parent's, budget nested in the tree, a
     * data-only framed result). Charlotte only offers it, because
     * prism-harness 0.17's uncapped `stream()` does not add a mode's declared
     * sub-agents to the run (its `send()` and capped stream do, and then the
     * harness's tool of the same name replaces this one). Reported to the
     * Prism maintainers as a harness gap.
     */
    public function tool(SessionRecord $session): ?Tool
    {
        $turn = $this->turns[$session->id] ?? null;
        if ($turn === null || $this->runner === null || $this->modes === null) {
            return null;
        }
        $subagent = ($this->modes)()->resolve(BuilderMode::SITE_NAME)->subagents[BuilderMode::SITE_SUBAGENT] ?? null;
        if (! $subagent instanceof Subagent) {
            return null;
        }
        $runner = ($this->runner)();

        return (new Tool)
            ->as($subagent->name)
            ->for($subagent->description)
            ->withStringParameter('task', 'What the subagent should do, stated as a complete instruction. It does not see this conversation.')
            ->using(fn (string $task): string => $runner
                ->run($subagent, $turn['parent'], $turn['tree'], (string) ($turn['parent']->run()['id'] ?? $turn['tree']->rootRunId()), $task)
                ->toToolResult());
    }

    /** The address a site orchestrator's page sub-agent runs under. */
    public static function siteChildScope(SessionRecord $session): string
    {
        return $session->scope().'::sub::'.BuilderMode::SITE_SUBAGENT_SCOPE;
    }

    /** Bind the harness session at `$scope` (a sub-agent's address) to this Charlotte session. Host code only. */
    public function bind(SessionRecord $session, Model $participant, string $scope): void
    {
        $this->harness->session($participant, $scope)
            ->usingProvider($session->provider)
            ->usingModel($session->model)
            ->usingCapability(self::CAPABILITY, ['session' => $session->id, 'scope' => $scope]);
    }

    /** Before a site turn: bind the orchestrator's page sub-agent address. */
    public function prepareSite(SessionRecord $session, Model $participant): void
    {
        $this->bind($session, $participant, self::siteChildScope($session));
    }

    /**
     * Start the next page sub-agent on a fresh conversation. Called when the
     * orchestrator selects or creates a page: the child address is fixed, so
     * without this every page would inherit the previous page's transcript,
     * and the whole site would pile up in one context after all.
     */
    public function fresh(SessionRecord $session): void
    {
        if (! $session->isSite()) {
            return;
        }
        $child = $this->harness->session(self::participant($session), self::siteChildScope($session));
        // An empty thread is already fresh; retiring it would only leave an unlinked row behind.
        foreach ($child->thread()->messages() as $ignored) {
            $child->newConversation();

            return;
        }
    }

    /**
     * The Charlotte session a `charlotte`-mode harness session is bound to,
     * or null. Every link must hold: the capability names a stored session,
     * names THIS address, and the harness participant is that session's
     * participant. A capability copied to another address or another
     * participant binds nothing.
     */
    public function boundSession(Session $harness): ?SessionRecord
    {
        $capability = $harness->capability(self::CAPABILITY);
        $id = $capability['session'] ?? null;
        $scope = $capability['scope'] ?? null;
        if (! is_string($id) || ! is_string($scope) || ! hash_equals($scope, $harness->scope())) {
            return null;
        }
        $record = $this->sessions->find($id);
        $participant = $harness->participant();
        if ($record === null || $participant->getMorphClass() !== $record->participantType || (string) $participant->getKey() !== $record->participantId) {
            return null;
        }
        if ($record->isSite() && ! hash_equals(self::siteChildScope($record), $scope)) {
            return null; // a site's pages are built only by its own orchestrator's sub-agent
        }

        return $record;
    }

    public static function participant(SessionRecord $session): Model
    {
        $class = Relation::getMorphedModel($session->participantType) ?? $session->participantType;
        if (! is_string($class) || ! is_subclass_of($class, Model::class)) {
            throw new Refused('session_missing');
        }

        return $class::query()->find($session->participantId) ?? throw new Refused('session_missing');
    }
}
