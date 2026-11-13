<?php

declare(strict_types=1);

namespace Charlotte;

use Charlotte\Brief\Brief;
use Charlotte\Checks\CallbackRenderedEvidenceSource;
use Charlotte\Checks\RenderedEvidenceSource;
use Charlotte\Site\Site;
use Charlotte\Data\Bindings;
use Charlotte\Guide\BuilderMode;
use Charlotte\Data\DataCatalogue;
use Charlotte\Design\Examples;
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
        $id = Ulid::generate();
        $record = new SessionRecord(
            id: $id, tenant: $tenant, page: $page,
            participantType: $participant->getMorphClass(), participantId: (string) $participant->getKey(),
            autonomy: $autonomy, provider: $provider, model: $model, brief: $brief,
            guideHash: $guide->guideHash, guide: $guide->toArray() + ['examples' => Examples::sample($id)], status: 'idle',
            stopRequestedAt: null, readyRevision: null,
        );

        $this->container->make(ConnectionInterface::class)->transaction(function () use ($record, $document): void {
            $this->container->make(PageRepository::class)->ensure($record->tenant, $record->page, $document ?? NodeTypes::emptyDocument($record->page));
            $this->container->make(SessionStore::class)->create($record);
            $this->container->make(Workspace::class)->record($record->id, EventTypes::SESSION_STATUS, Workspace::SYSTEM, ['status' => 'idle']);
        });

        return new CharlotteSession($this, $record->id);
    }

    /**
     * Open a WHOLE-SITE session: the agent creates pages, routes them, edits
     * the shared `@header` / `@footer` once, and sets the nav. The site and
     * its two regions are created if they do not exist; an existing site is
     * reopened as it is. Documents live in the PageRepository under
     * `<site>/<page id>`, so keep plain page keys from looking like that.
     */
    public function openSite(
        Model $participant,
        string $tenant,
        string $site,
        Brief $brief,
        ?Autonomy $autonomy = null,
        ?string $provider = null,
        ?string $model = null,
    ): CharlotteSession {
        if (preg_match(self::KEY, $tenant) !== 1 || preg_match(Site::KEY, $site) !== 1) {
            throw new InvalidArgumentException('Tenant keys are 1-191 printable ASCII characters; site keys 1-128, with no slash.');
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
        $id = Ulid::generate();

        return $this->container->make(ConnectionInterface::class)->transaction(function () use ($participant, $tenant, $site, $brief, $autonomy, $provider, $model, $guide, $id): CharlotteSession {
            $structure = $this->container->make(SiteWorkspace::class)->ensure($tenant, $site);
            $first = $structure->pages[0]['id'] ?? '@header';
            $record = new SessionRecord(
                id: $id, tenant: $tenant, page: $structure->documentKey($first),
                participantType: $participant->getMorphClass(), participantId: (string) $participant->getKey(),
                autonomy: $autonomy, provider: $provider, model: $model, brief: $brief,
                guideHash: $guide->guideHash, guide: $guide->toArray() + ['examples' => Examples::sample($id)], status: 'idle',
                stopRequestedAt: null, readyRevision: null, site: $site,
            );
            $this->container->make(SessionStore::class)->create($record);
            $this->container->make(Workspace::class)->record($record->id, EventTypes::SESSION_STATUS, Workspace::SYSTEM, ['status' => 'idle']);

            return new CharlotteSession($this, $record->id);
        });
    }

    /**
     * OPTIONAL: where rendered reports come from (finding S12). With one,
     * `cms_check` judges contrast on the rendered page, islands included,
     * whenever the report names the revision being checked; otherwise on the
     * document. Return null when you have none. Same as binding a
     * {@see RenderedEvidenceSource}.
     *
     *     Charlotte::renderedUsing(fn (SessionRecord $s, string $page): ?RenderedEvidence =>
     *         RenderedEvidence::fromAxe($inspection['contrast']['violations'], $inspection['revision'], 'axe-core 4.14.0'));
     *
     * @param  Closure(SessionRecord, string): ?RenderedEvidence  $source
     */
    public function renderedUsing(Closure $source): void
    {
        $this->container->instance(RenderedEvidenceSource::class, new CallbackRenderedEvidenceSource($source));
    }

    /**
     * Hand ONE page to Charlotte as a prism-harness sub-agent of the host's
     * own agent. Opens a page session (tenant, page and brief from host
     * context, never from the parent's task) and binds the child address
     * the harness will spawn at, `<parent scope>::sub::<suffix>`, to it.
     *
     * The parent mode declares the sub-agent in its own config:
     *
     *     'subagents' => ['charlotte' => BuilderMode::subagent()]   // mode charlotte, 40 steps, nested budget
     *
     * then, per turn: delegate, run the parent; Charlotte's result reaches the
     * parent as data, `{"draft": {"page", "revision"}}`; read the draft with
     * the returned session. The suffix is the subagent's `scope` config, or
     * its name when it has none. Bindings carry the session's provider and
     * model in harness session state, which expires with the harness's
     * ephemeral TTL: call {@see CharlotteSession::bindAsSubagent()} again
     * before a later parent turn.
     */
    public function delegate(
        Model $participant,
        string $parentScope,
        string $tenant,
        string $page,
        Brief $brief,
        string $scopeSuffix = BuilderMode::PAGE_SUB_NAME,
        ?Autonomy $autonomy = null,
        ?string $provider = null,
        ?string $model = null,
        ?array $document = null,
    ): CharlotteSession {
        if (str_contains($page, '/')) {
            // A slash is how a site's documents are keyed; a delegated page never names one.
            throw new InvalidArgumentException('A delegated page key holds no slash.');
        }
        $session = $this->open($participant, $tenant, $page, $brief, $autonomy, $provider, $model, $document);
        $session->bindAsSubagent($parentScope, $scopeSuffix);

        return $session;
    }

    /**
     * The host's data streams, which report and dashboard pages bind to.
     * `cms_describe` lists them; a binding to anything else is refused. Same
     * as binding a {@see DataCatalogue}; bind a closure instead to vary it
     * per tenant.
     *
     *     Charlotte::dataCatalogue(ArrayDataCatalogue::fromArray([...]));
     */
    public function dataCatalogue(DataCatalogue $catalogue): void
    {
        $this->container->instance(DataCatalogue::class, $catalogue);
    }

    /**
     * Render time, host code: what to fetch for one viewer. One entry per
     * bound query on the page, its params, and its scope NARROWED to what the
     * viewer may see (a binding or page input can never widen it). Fetch each,
     * then pass {@see dataContext()} of the results to fancy-cms's renderer.
     *
     * The requests are fancy-cms's own (`DataRequests::queries()`, kept
     * verbatim under `request`), so Charlotte and fancy-cms can never ask the
     * host for different things.
     *
     * @param  array<string, mixed>  $viewer  the viewer's whole visible scope, every parameter stated
     * @param  array<string, mixed>  $inputs  raw page input values by name (e.g. the request's query)
     * @param  (Closure(string): list<string>)|null  $descendants  a tree id's descendants, for children:true
     * @param  array<string, mixed>  $data  the context bound input options (people, teams) resolve against
     * @param  (Closure(string): ?array{from: string, to: string})|null  $periods  a period input's preset (`30d`) as dates
     * @return list<array{key: string, stream: string, alias: ?string, params: array, fields: list<string>, scope: array<string, mixed>, inputs: list<string>, bindings: list<array>, request: array}>
     */
    public function dataQueries(array $doc, array $viewer, array $inputs = [], ?Closure $descendants = null, array $data = [], ?Closure $periods = null): array
    {
        return Bindings::queries($doc, $this->container->make(DataCatalogue::class), $viewer, $inputs, $descendants, $data, $periods);
    }

    /**
     * The render data context from each query's `{rows, totals?}` by query key.
     *
     * @param  array<string, array{rows?: list<array>, totals?: array}>  $results
     */
    public function dataContext(array $results): array
    {
        return Bindings::context($results, $this->container->make(DataCatalogue::class));
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
