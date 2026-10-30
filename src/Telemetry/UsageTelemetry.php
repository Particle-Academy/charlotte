<?php

declare(strict_types=1);

namespace Charlotte\Telemetry;

use Charlotte\Events\EventTypes;
use Charlotte\Events\Ulid;
use Charlotte\Workspace;
use Closure;
use Illuminate\Container\Container;
use Throwable;

/**
 * OPTIONAL usage and cost observation, riding on Prism's own telemetry.
 *
 * Feature-detected: it listens only when the installed Prism ships its
 * `GenerationCompleted` telemetry event, and records only while BOTH
 * `prism.telemetry.enabled` and `charlotte.telemetry.enabled` are on. Each
 * generation in a Charlotte session becomes one `usage.observed` event.
 *
 * It never refuses anything. A model with no configured price reports
 * `costUsd: null` and `priced: false`; the turn is unaffected. Nothing else in
 * Charlotte reads these events, so removing telemetry changes no behaviour.
 */
final class UsageTelemetry
{
    public const PRISM_EVENT = 'Prism\Prism\Events\Telemetry\GenerationCompleted';

    /**
     * The workspace may be passed as a resolver, and the listener does: it is
     * built only after the flags and the session key say this generation is
     * Charlotte's. Prism fires GenerationCompleted for EVERY generation in the
     * host, and a host may scope Charlotte's dependencies (LinkPolicy,
     * AssetCatalogue, PageRepository) to a builder turn, so resolving them for
     * an unrelated generation would throw into an unrelated agent role.
     *
     * @param  Workspace|(Closure(): Workspace)  $workspace
     * @param  array<string, array<string, array{input: float|int, output: float|int}>>  $prices
     */
    public function __construct(private readonly Workspace|Closure $workspace, private readonly array $prices = []) {}

    /** @param (Closure(string): bool)|null $classExists */
    public static function available(?Closure $classExists = null): bool
    {
        $classExists ??= fn (string $class): bool => class_exists($class);

        return $classExists(self::PRISM_EVENT) && self::enabled();
    }

    public static function enabled(): bool
    {
        $config = Container::getInstance()->make('config');

        return (bool) $config->get('prism.telemetry.enabled', false) && (bool) $config->get('charlotte.telemetry.enabled', false);
    }

    /** The Charlotte session id in a harness session key (`...:charlotte:<ulid>`), or null. */
    public static function sessionFromKey(?string $key): ?string
    {
        if ($key === null || preg_match('/:charlotte:([0-7][0-9A-HJKMNP-TV-Z]{25})\z/', $key, $m) !== 1) {
            return null;
        }

        return Ulid::valid($m[1]) ? $m[1] : null;
    }

    /** Listener for Prism's GenerationCompleted. Never throws into the run. */
    public function handle(object $event): void
    {
        try {
            if (! self::enabled()) {
                return;
            }
            $context = $event->context ?? null;
            $session = self::sessionFromKey($context->sessionId ?? null);
            $usage = $event->usage ?? null;
            if ($session === null || $usage === null) {
                return;
            }
            $provider = (string) $context->provider;
            $model = (string) $context->model;
            $input = (int) $usage->promptTokens;
            $output = (int) $usage->completionTokens;
            $price = $this->prices[$provider][$model] ?? null;
            $cost = null;
            if (is_array($price) && is_numeric($price['input'] ?? null) && is_numeric($price['output'] ?? null)) {
                $cost = round(($input * (float) $price['input'] + $output * (float) $price['output']) / 1_000_000, 8);
            } elseif (is_float($usage->cost ?? null) || is_int($usage->cost ?? null)) {
                $cost = (float) $usage->cost; // a cost the provider itself reported
            }
            $workspace = $this->workspace instanceof Closure ? ($this->workspace)() : $this->workspace;
            $workspace->record($session, EventTypes::USAGE_OBSERVED, Workspace::SYSTEM, [
                'provider' => $provider, 'model' => $model, 'inputTokens' => $input, 'outputTokens' => $output,
                'costUsd' => $cost, 'priced' => $cost !== null,
            ]);
        } catch (Throwable) {
            // Observation is a courtesy. It must never fail a turn.
        }
    }
}
