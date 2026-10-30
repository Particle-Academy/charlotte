<?php

declare(strict_types=1);

namespace Charlotte;

use Charlotte\Brief\Brief;
use Charlotte\Drafts\NodeTypes;
use Charlotte\Drafts\PageRepository;
use Charlotte\Events\EventTypes;
use Charlotte\Events\Ulid;
use Charlotte\Guide\GuideVersion;
use Charlotte\Guide\Guides;
use Charlotte\Http\Authorizer;
use Charlotte\Http\CallbackAuthorizer;
use Charlotte\Laravel\Routes;
use Charlotte\Runtime\CallbackTurnContextFactory;
use Charlotte\Runtime\Turn;
use Charlotte\Runtime\TurnContextFactory;
use Charlotte\Runtime\Turns;
use Prism\Harness\Subagents\RunContext;
use Charlotte\Sessions\SessionRecord;
use Charlotte\Storage\SessionStore;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The entry point. Bound as a singleton; reach it through the
 * {@see \Charlotte\Facades\Charlotte} facade or the container.
 */
final class Charlotte
{
    private const KEY = '/\A[\x21-\x7e]{1,191}\z/';

    /** @var (Closure(mixed): ?string)|null */
    private ?Closure $tenantResolver = null;

    public function __construct(private readonly Container $container) {}

    public function for(Model $participant): PendingCharlotteSession
    {
        return new PendingCharlotteSession($this, $participant);
    }

    public function open(
        Model $participant,
        string $tenant,
        string $page,
        Brief $brief,
        ?Autonomy $autonomy = null,
        ?string $provider = null,
        ?string $model = null,
        ?array $document = null,
    ): CharlotteSession {
        if (preg_match(self::KEY, $tenant) !== 1 || preg_match(self::KEY, $page) !== 1) {
            throw new InvalidArgumentException('Tenant and page keys are 1-191 printable ASCII characters.');
        }
        if ($participant->getKey() === null) {
            throw new InvalidArgumentException('The participant must be a saved model.');
        }
        $config = $this->container->make('config');
        $autonomy ??= Autonomy::from((string) $config->get('charlotte.autonomy', Autonomy::ApproveOnSave->value));
        $provider ??= $config->get('charlotte.provider') ?? $config->get('prism-harness.agent.provider');
        $model ??= $config->get('charlotte.model') ?? $config->get('prism-harness.agent.model');
        if (! is_string($provider) || $provider === '' || ! is_string($model) || $model === '') {
            throw new InvalidArgumentException('Set charlotte.provider and charlotte.model (any Prism provider).');
        }
        $guide = $this->guides()->current();
        $record = new SessionRecord(
            id: Ulid::generate(), tenant: $tenant, page: $page,
            participantType: $participant->getMorphClass(), participantId: (string) $participant->getKey(),
            autonomy: $autonomy, provider: $provider, model: $model, brief: $brief,
            guideHash: $guide->guideHash, guide: $guide->toArray(), status: 'idle',
            stopRequestedAt: null, readyRevision: null,
        );

        $this->container->make(ConnectionInterface::class)->transaction(function () use ($record, $document): void {
            $this->container->make(PageRepository::class)->ensure($record->tenant, $record->page, $document ?? NodeTypes::emptyDocument($record->page));
            $this->container->make(SessionStore::class)->create($record);
            $this->container->make(Workspace::class)->record($record->id, EventTypes::SESSION_STATUS, Workspace::SYSTEM, ['status' => 'idle']);
        });

        return new CharlotteSession($this, $record->id);
    }

    /** A session by id, or null. Pass the tenant the request resolved to; a session from another tenant is not found. */
    public function find(string $id, ?string $tenant = null): ?CharlotteSession
    {
        if (! Ulid::valid($id)) {
            return null;
        }
        $record = $this->container->make(SessionStore::class)->find($id);
        if ($record === null || ($tenant !== null && ! hash_equals($record->tenant, $tenant))) {
            return null;
        }

        return new CharlotteSession($this, $id);
    }

    /**
     * Layer the host's own guidance over the shipped guide.
     *
     *     Charlotte::guide(fn (GuideVersion $base) => $base->append($houseStyle, 'house-style'));
     *
     * @param  Closure(GuideVersion): GuideVersion  $override
     */
    public function guide(Closure $override): void
    {
        $this->guides()->override($override);
    }

    /**
     * The authorization hook every route asks. Without one, every route is
     * refused.
     *
     *     Charlotte::authorizeUsing(fn (User $user, string $ability, array $subject): bool =>
     *         $user->can('charlotte.'.$ability, [$subject['tenant'], $subject['page']]));
     *
     * @param  Closure(mixed, string, array): bool  $callback
     */
    public function authorizeUsing(Closure $callback): void
    {
        $this->container->instance(Authorizer::class, new CallbackAuthorizer($callback));
    }

    /**
     * How a request maps to a tenant (team, site, account). Every route is
     * scoped by it; a session in another tenant is not found. Without one,
     * every route is refused with `tenant_unresolved`.
     *
     *     Charlotte::resolveTenantUsing(fn (Request $request): ?string => (string) $request->user()?->current_team_id);
     *
     * @param  Closure(mixed): ?string  $resolver
     */
    public function resolveTenantUsing(Closure $resolver): void
    {
        $this->tenantResolver = $resolver;
    }

    /**
     * OPTIONAL: the host's run context for each turn (budget, pricing, cost
     * observer). Return null to keep the harness defaults for that turn; throw
     * to refuse the turn before any provider call. Without one, every turn
     * runs on the harness's own configuration. Same as binding a
     * {@see TurnContextFactory}.
     *
     *     Charlotte::contextUsing(fn (Turn $turn): ?RunContext => $budgets->contextFor($turn->session->tenant));
     *
     * @param  Closure(Turn): ?RunContext  $factory
     */
    public function contextUsing(Closure $factory): void
    {
        $this->container->instance(TurnContextFactory::class, new CallbackTurnContextFactory($factory));
    }

    public function tenantFor(mixed $request): ?string
    {
        if ($this->tenantResolver === null) {
            return null;
        }
        $tenant = ($this->tenantResolver)($request);

        return is_string($tenant) && preg_match(self::KEY, $tenant) === 1 ? $tenant : null;
    }

    /**
     * Register the optional controller kit's routes.
     *
     * @param  array{prefix?: string, middleware?: list<string>}  $options
     */
    public function routes(array $options = []): void
    {
        Routes::register($this->container->make('router'), $options);
    }

    public function guides(): Guides
    {
        return $this->container->make(Guides::class);
    }

    public function turns(): Turns
    {
        return $this->container->make(Turns::class);
    }

    public function container(): Container
    {
        return $this->container;
    }
}
