<?php

declare(strict_types=1);

namespace Charlotte\Laravel;

use Illuminate\Container\Container;
use Illuminate\Routing\Router;

/**
 * The controller kit's routes. Registered only when the host asks:
 * `Charlotte::routes()` or config `charlotte.routes.enabled`.
 */
final class Routes
{
    /** @param array{prefix?: string, middleware?: list<string>} $options */
    public static function register(Router $router, array $options = []): void
    {
        $config = Container::getInstance()->make('config');
        $router->group([
            'prefix' => $options['prefix'] ?? $config->get('charlotte.routes.prefix', 'charlotte'),
            'middleware' => $options['middleware'] ?? $config->get('charlotte.routes.middleware', ['web', 'auth']),
        ], function (Router $router): void {
            $router->post('sessions', [CharlotteController::class, 'open'])->name('charlotte.sessions.open');
            $router->get('sessions/{session}', [CharlotteController::class, 'state'])->name('charlotte.sessions.state');
            $router->get('sessions/{session}/events', [CharlotteController::class, 'events'])->name('charlotte.sessions.events');
            $router->post('sessions/{session}/messages', [CharlotteController::class, 'message'])->name('charlotte.sessions.message');
            $router->post('sessions/{session}/stop', [CharlotteController::class, 'stop'])->name('charlotte.sessions.stop');
            $router->post('sessions/{session}/ops', [CharlotteController::class, 'ops'])->name('charlotte.sessions.ops');
            $router->post('sessions/{session}/proposals/{proposal}/accept', [CharlotteController::class, 'accept'])->name('charlotte.proposals.accept');
            $router->post('sessions/{session}/proposals/{proposal}/reject', [CharlotteController::class, 'reject'])->name('charlotte.proposals.reject');
            $router->post('sessions/{session}/publish', [CharlotteController::class, 'publish'])->name('charlotte.sessions.publish');
        });
    }
}
