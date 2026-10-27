<?php

declare(strict_types=1);

namespace Charlotte\Tests\Support;

use Illuminate\Auth\AuthServiceProvider;
use Illuminate\Bus\BusServiceProvider;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Queue\QueueServiceProvider;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Prism\Harness\PrismHarnessServiceProvider;
use Prism\Prism\PrismManager;
use Prism\Prism\PrismServiceProvider;

/**
 * A hand-built Laravel kernel for the suite: the framework this package
 * integrates with, booted directly, with no orchestra/testbench.
 *
 * In-memory SQLite, the sync queue and the array cache, so every test is
 * hermetic and nothing leaves the process. The only provider is
 * {@see ScriptedProvider}, registered under a name of the test's choosing to
 * show that Charlotte takes the provider from configuration.
 */
final class TestApp
{
    public static ?ScriptedProvider $provider = null;

    /** @param array<string, mixed> $config dotted keys merged over the defaults */
    public static function boot(array $config = [], ?ScriptedProvider $provider = null): Application
    {
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'charlotte-tests';
        if (! is_dir($base.'/config')) {
            mkdir($base.'/config', 0777, true);
        }
        $app = new Application($base);
        $app->useConfigPath($base.'/config');
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        $repository = new Repository([
            'app' => ['key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'cipher' => 'AES-256-CBC', 'env' => 'testing', 'debug' => false],
            'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]],
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array', 'serialize' => false]]],
            'queue' => ['default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']]],
            'logging' => ['default' => 'null', 'channels' => ['null' => ['driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class]]],
            'auth' => ['defaults' => ['guard' => 'web'], 'guards' => [], 'providers' => []],
        ]);
        foreach ($config as $key => $value) {
            $repository->set($key, $value);
        }
        $app->instance('config', $repository);

        foreach ([FilesystemServiceProvider::class, DatabaseServiceProvider::class, CacheServiceProvider::class,
            BusServiceProvider::class, QueueServiceProvider::class, AuthServiceProvider::class,
            PrismServiceProvider::class, PrismHarnessServiceProvider::class,
            \Charlotte\Laravel\CharlotteServiceProvider::class] as $serviceProvider) {
            if (class_exists($serviceProvider)) {
                $app->register($serviceProvider);
            }
        }

        // Harness stores and Charlotte's tables, applied straight from the
        // shipped migration files: the same files a host runs.
        Model::unguard();
        self::migrate($app, dirname(__DIR__, 2).'/vendor/particle-academy/prism-harness/database/migrations');
        self::migrate($app, dirname(__DIR__, 2).'/database/migrations');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        self::$provider = $provider ?? new ScriptedProvider;
        $scripted = self::$provider;
        $app->make(PrismManager::class)->extend('scripted', fn () => $scripted);
        $app->boot();

        return $app;
    }

    private static function migrate(Application $app, string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $files = glob($directory.'/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $migration = require $file;
            $migration->up();
        }
    }
}
