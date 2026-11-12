<?php

declare(strict_types=1);

namespace Charlotte\Laravel;

use Charlotte\Charlotte;
use Charlotte\Data\ArrayDataCatalogue;
use Charlotte\Data\DataCatalogue;
use Charlotte\Drafts\PageRepository;
use Charlotte\Events\EventLog;
use Charlotte\Guide\BuilderMode;
use Charlotte\Guide\GuideSource;
use Charlotte\Guide\Guides;
use Charlotte\Guide\PublishedGuide;
use Charlotte\Http\Authorizer;
use Charlotte\Http\DenyAll;
use Charlotte\Policy\AssetCatalogue;
use Charlotte\Policy\EmptyAssetCatalogue;
use Charlotte\Policy\LinkPolicy;
use Charlotte\Runtime\HarnessDefaults;
use Charlotte\Runtime\PageSubagents;
use Charlotte\Runtime\TurnContextFactory;
use Charlotte\Runtime\TurnDispatcher;
use Charlotte\Runtime\TurnRunner;
use Charlotte\Runtime\Turns;
use Charlotte\Telemetry\UsageTelemetry;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Prism\Harness\AgentRuntime;
use Prism\Harness\PrismHarness;
use Charlotte\Storage\DatabaseEventLog;
use Charlotte\Storage\DatabasePageRepository;
use Charlotte\Storage\ProposalStore;
use Charlotte\Storage\SessionStore;
use Charlotte\Tools\PageTools;
use Charlotte\Transport\LongPoll;
use Charlotte\Transport\Sleeper;
use Charlotte\Transport\UsleepSleeper;
use Charlotte\Workspace;
use Charlotte\SiteWorkspace;
use Charlotte\Checks\NoRenderedEvidence;
use Charlotte\Checks\RenderedEvidenceSource;
use Charlotte\Islands\CmsDescribeIslands;
use Charlotte\Islands\IslandCatalogue;
use Charlotte\Site\ReservedRoutes;
use Charlotte\Site\SiteRepository;
use Charlotte\Storage\DatabaseSiteRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\ServiceProvider;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Subagents\SubagentRunner;
use Prism\Harness\Tools\ToolRegistry;

/**
 * Binds Charlotte's defaults. Every contract here can be rebound by the host
 * (PageRepository, AssetCatalogue, GuideSource, EventLog, LinkPolicy); nothing
 * registers routes unless the host asks.
 */
final class CharlotteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2).'/config/charlotte.php', 'charlotte');

        $this->app->bindIf(ConnectionInterface::class, fn (Application $app) => $app['db']->connection());
        $this->app->singleton(EventLog::class, fn (Application $app) => new DatabaseEventLog($app['db']->connection()));
        $this->app->singleton(PageRepository::class, fn (Application $app) => new DatabasePageRepository($app['db']->connection(),
            fn (): array => $app->make(IslandCatalogue::class)->types()));
        $this->app->singletonIf(SiteRepository::class, fn (Application $app) => new DatabaseSiteRepository($app['db']->connection()));
        // Bound, not singletons, like AssetCatalogue: a host may rebind them per tenant.
        $this->app->bindIf(ReservedRoutes::class, fn (Application $app) => new ReservedRoutes((array) $app['config']->get('charlotte.site.reserved_routes', [])));
        $this->app->bindIf(IslandCatalogue::class, fn () => CmsDescribeIslands::catalogue());
        $this->app->bindIf(RenderedEvidenceSource::class, fn () => new NoRenderedEvidence);
        // Bound, not a singleton: a host may rebind its streams per tenant.
        $this->app->bindIf(DataCatalogue::class, fn () => ArrayDataCatalogue::empty());
        $this->app->singleton(SessionStore::class, fn (Application $app) => new SessionStore($app['db']->connection()));
        $this->app->singleton(ProposalStore::class, fn (Application $app) => new ProposalStore($app['db']->connection()));
        $this->app->singletonIf(AssetCatalogue::class, fn () => new EmptyAssetCatalogue);
        $this->app->singletonIf(LinkPolicy::class, fn (Application $app) => new LinkPolicy((array) $app['config']->get('charlotte.links.allow_origins', [])));
        $this->app->singletonIf(GuideSource::class, fn () => new PublishedGuide);
        $this->app->singleton(Guides::class, fn (Application $app) => new Guides($app->make(GuideSource::class)));
        $this->app->singletonIf(Sleeper::class, fn () => new UsleepSleeper);
        $this->app->bind(LongPoll::class, fn (Application $app) => new LongPoll(
            $app->make(EventLog::class), $app->make(Sleeper::class),
            intervalMs: (int) $app['config']->get('charlotte.transport.poll_interval_ms', 250),
            maxWaitSeconds: (int) $app['config']->get('charlotte.transport.poll_wait_seconds', 20),
        ));
        // Bound, not singletons: AssetCatalogue and LinkPolicy may be rebound
        // at runtime (per tenant, per request) and must be read fresh.
        $this->app->bind(Workspace::class, fn (Application $app) => new Workspace(
            $app['db']->connection(), $app->make(SessionStore::class), $app->make(PageRepository::class),
            $app->make(ProposalStore::class), $app->make(EventLog::class), $app->make(AssetCatalogue::class), $app->make(LinkPolicy::class),
            $app->make(IslandCatalogue::class), $app->make(RenderedEvidenceSource::class), $app->make(DataCatalogue::class),
            max(1, (int) $app['config']->get('charlotte.finish.max_not_ready', 3)),
        ));
        $this->app->bind(SiteWorkspace::class, fn (Application $app) => new SiteWorkspace(
            $app['db']->connection(), $app->make(Workspace::class), $app->make(SessionStore::class), $app->make(SiteRepository::class),
            $app->make(ReservedRoutes::class), max(1, (int) $app['config']->get('charlotte.site.max_pages', 12)),
        ));
        $this->app->singletonIf(TurnDispatcher::class, fn (Application $app) => new QueueTurnDispatcher(
            $app->make(BusDispatcher::class),
            $app['config']->get('charlotte.queue.connection'),
            $app['config']->get('charlotte.queue.queue'),
        ));
        $this->app->bind(Turns::class, fn (Application $app) => new Turns(
            $app->make(Workspace::class), $app->make(SessionStore::class), $app['db']->connection(), $app->make(TurnDispatcher::class),
        ));
        // Optional: a host binds its own to supply each turn's RunContext
        // (budget, pricing, cost observer). The default keeps the harness's.
        $this->app->bindIf(TurnContextFactory::class, fn () => new HarnessDefaults);
        $this->app->bind(TurnRunner::class, fn (Application $app) => new TurnRunner(
            $app->make(Workspace::class), $app->make(Guides::class), $app->make(ModeRegistry::class),
            $app->make(PrismHarness::class), $app->make(AgentRuntime::class), $app->make(TurnContextFactory::class),
            max(0, (int) $app['config']->get('charlotte.max_continuations', 3)),
            $app->make(SiteWorkspace::class),
            $app->make(PageSubagents::class),
        ));
        // A singleton: it holds the site turn in flight for the page sub-agent tool.
        $this->app->singleton(PageSubagents::class, fn (Application $app) => new PageSubagents(
            $app->make(PrismHarness::class), $app->make(SessionStore::class),
            fn (): SubagentRunner => $app->make(SubagentRunner::class), fn (): ModeRegistry => $app->make(ModeRegistry::class),
        ));
        $this->app->singleton(Charlotte::class, fn (Application $app) => new Charlotte($app));
        $this->app->singletonIf(Authorizer::class, fn () => new DenyAll);

        // The builder mode, merged key-wise into prism-harness's modes when the
        // registry is first built (after every provider has booted, so guide
        // overrides registered in a host's boot() are included). A host that
        // defines `charlotte_builder` itself wins.
        $this->app->extend(ModeRegistry::class, function (ModeRegistry $registry, Application $app): ModeRegistry {
            $agent = (array) $app['config']->get('prism-harness.agent', []);
            $modes = is_array($agent['modes'] ?? null) ? $agent['modes'] : [];
            $config = $app['config'];
            $guide = $app->make(Guides::class)->current();
            $options = (array) $config->get('charlotte.provider_options', []);
            $pageSteps = max(1, (int) $config->get('charlotte.max_steps', BuilderMode::PAGE_STEPS));
            // The orchestrator's ceiling is the whole TREE's: page sub-agent
            // budgets nest within it, so it must cover every page and region.
            $siteSteps = (int) ($config->get('charlotte.site.max_steps')
                ?? $pageSteps * (max(1, (int) $config->get('charlotte.site.max_pages', 12)) + 3));
            $modes[BuilderMode::NAME] ??= BuilderMode::config($guide, $pageSteps, $options);
            $modes[BuilderMode::SITE_NAME] ??= BuilderMode::config($guide, $siteSteps, $options, true, $pageSteps);
            $modes[BuilderMode::PAGE_SUB_NAME] ??= BuilderMode::pageConfig($guide, $pageSteps, $options);
            $agent['modes'] = $modes;

            return new ModeRegistry($agent);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
        if ($this->app['config']->get('charlotte.routes.enabled') === true) {
            Routes::register($this->app->make('router'));
        }
        if ($this->app->runningInConsole()) {
            $this->publishes([dirname(__DIR__, 2).'/config/charlotte.php' => $this->app->configPath('charlotte.php')], 'charlotte-config');
            $this->publishes([dirname(__DIR__, 2).'/database/migrations' => $this->app->databasePath('migrations')], 'charlotte-migrations');
        }

        // Tools bind to a page through durable session capability state that
        // only host code writes (the turn runner). The model never names the
        // page, the tenant or itself. Guarded on the mode so unrelated runs pay
        // nothing: ToolRegistry runs every provider on every resolve.
        $this->app->make(ToolRegistry::class)->registerProvider(function (Session $session): array {
            $mode = $session->mode();
            if ($mode === BuilderMode::PAGE_SUB_NAME) {
                // A page sub-agent: bound by host code to one Charlotte session
                // at exactly this address (PageSubagents::boundSession).
                $record = $this->app->make(PageSubagents::class)->boundSession($session);

                return $record === null ? [] : (new PageTools($this->app->make(Workspace::class), $record->id, $this->app->make(SiteWorkspace::class), child: true))->prismTools();
            }
            if (! in_array($mode, [BuilderMode::NAME, BuilderMode::SITE_NAME], true)) {
                return [];
            }
            $capability = $session->capability('charlotte');
            $id = $capability['session'] ?? null;
            if (! is_string($id) || $session->scope() !== 'charlotte:'.$id) {
                return [];
            }

            return (new PageTools($this->app->make(Workspace::class), $id, $this->app->make(SiteWorkspace::class),
                subagents: $mode === BuilderMode::SITE_NAME ? $this->app->make(PageSubagents::class) : null))->prismTools();
        });

        // Optional usage/cost observation. Listens only if the installed Prism
        // has telemetry events at all; records only while both flags are on.
        // Prism fires this for EVERY generation in the host, so nothing of the
        // host's is resolved here: the workspace is built lazily, inside
        // handle(), only once the flags and the session key say the generation
        // is Charlotte's. A host-scoped dependency that throws outside a
        // builder turn must not reach an unrelated agent role.
        if (class_exists(UsageTelemetry::PRISM_EVENT)) {
            $this->app['events']->listen(UsageTelemetry::PRISM_EVENT, function (object $event): void {
                (new UsageTelemetry(
                    fn (): Workspace => $this->app->make(Workspace::class),
                    (array) $this->app['config']->get('charlotte.telemetry.prices', []),
                ))->handle($event);
            });
        }
    }
}
