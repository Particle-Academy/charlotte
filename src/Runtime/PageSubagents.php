<?php

declare(strict_types=1);

namespace Charlotte\Runtime;

use Charlotte\Design\Examples;
use Charlotte\Guide\BuilderMode;
use Charlotte\Policy\Refused;
use Charlotte\Sessions\SessionRecord;
use Charlotte\Storage\SessionStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\UserMessage;

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
 *
 * Charlotte does not spawn, meter or filter the child itself: the site mode
 * DECLARES its `charlotte_page` sub-agent and prism-harness (>=0.23.2) offers
 * that tool in every stream, nests the child's budget in the parent's tree
 * (min(declared, remaining) steps, every call metered) and resolves only the
 * child mode's tools.
 */
final class PageSubagents
{
    public const CAPABILITY = 'charlotte';

    public function __construct(
        private readonly PrismHarness $harness,
        private readonly SessionStore $sessions,
    ) {}

    /** The address a site orchestrator's page sub-agent runs under. */
    public static function siteChildScope(SessionRecord $session): string
    {
        return $session->scope().'::sub::'.BuilderMode::SITE_SUBAGENT_SCOPE;
    }

    /** Bind the harness session at `$scope` (a sub-agent's address) to this Charlotte session. Host code only. */
    public function bind(SessionRecord $session, Model $participant, string $scope): void
    {
        $this->sessions->resetRefusals($session->id); // a host delegating (again) is new work
        $child = $this->harness->session($participant, $scope)
            ->usingProvider($session->provider)
            ->usingModel($session->model)
            ->usingCapability(self::CAPABILITY, ['session' => $session->id, 'scope' => $scope]);
        if (self::opening($child) === []) {
            $this->seed($child, $session);
        }
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
        $opening = self::opening($child);
        $section = Examples::promptSection($session->examples());
        // A thread holding nothing, or only the examples seed, is already
        // fresh; retiring it would only leave an unlinked row behind.
        if ($opening === []) {
            $this->seed($child, $session);

            return;
        }
        if (count($opening) === 1 && $opening[0] instanceof UserMessage && $opening[0]->content === $section) {
            return;
        }
        $child->newConversation();
        $this->seed($child, $session);
    }

    /**
     * Open a page sub-agent's conversation with the session's worked examples
     * ({@see Examples}), as one user message from Charlotte. The child's
     * prompt is the task its parent writes, so this is the only place they
     * can reach it without entering the system prompt (shared and cached
     * across sessions) or a tool result (framed as data).
     */
    private function seed(Session $child, SessionRecord $session): void
    {
        $section = Examples::promptSection($session->examples());
        if ($section !== '') {
            $child->thread()->record([new UserMessage($section)]);
        }
    }

    /** @return list<Message> at most the first two messages of the thread */
    private static function opening(Session $child): array
    {
        $out = [];
        foreach ($child->thread()->messages() as $message) {
            $out[] = $message;
            if (count($out) === 2) {
                break;
            }
        }

        return $out;
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
