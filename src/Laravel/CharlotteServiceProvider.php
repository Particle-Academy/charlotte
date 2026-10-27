<?php

declare(strict_types=1);

namespace Charlotte\Laravel;

use Charlotte\Charlotte;
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
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\ServiceProvider;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\Sessions\Session;
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
        $this->app->singleton(PageRepository::class, fn (Application $app) => new DatabasePageRepository($app['db']->connection()));
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
        ));
        $this->app->singletonIf(TurnDispatcher::class, fn (Application $app) => new QueueTurnDispatcher(
            $app->make(BusDispatcher::class),
            $app['config']->get('charlotte.queue.connection'),
            $app['config']->get('charlotte.queue.queue'),
        ));
        $this->app->bind(Turns::class, fn (Application $app) => new Turns(
            $app->make(Workspace::class), $app->make(SessionStore::class), $app['db']->connection(), $app->make(TurnDispatcher::class),
        ));
        $this->app->bind(TurnRunner::class, fn (Application $app) => new TurnRunner(
            $app->make(Workspace::class), $app->make(Guides::class), $app->make(ModeRegistry::class),
            $app->make(PrismHarness::class), $app->make(AgentRuntime::class),
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
            if (! isset($modes[BuilderMode::NAME])) {
                $modes[BuilderMode::NAME] = BuilderMode::config(
                    $app->make(Guides::class)->current(),
                    (int) $app['config']->get('charlotte.max_steps', 40),
                    (array) $app['config']->get('charlotte.provider_options', []),
                );
            }
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
            if ($session->mode() !== BuilderMode::NAME) {
                return [];
            }
            $capability = $session->capability('charlotte');
            $id = $capability['session'] ?? null;
            if (! is_string($id) || $session->scope() !== 'charlotte:'.$id) {
                return [];
            }

            return (new PageTools($this->app->make(Workspace::class), $id))->prismTools();
        });

        // Optional usage/cost observation. Listens only if the installed Prism
        // has telemetry events at all; records only while both flags are on.
        if (class_exists(UsageTelemetry::PRISM_EVENT)) {
            $this->app['events']->listen(UsageTelemetry::PRISM_EVENT, function (object $event): void {
                (new UsageTelemetry(
                    $this->app->make(Workspace::class),
                    (array) $this->app['config']->get('charlotte.telemetry.prices', []),
                ))->handle($event);
            });
        }
    }
}
