<?php

declare(strict_types=1);

namespace Charlotte\Critique;

use Illuminate\Contracts\Config\Repository;

/**
 * `charlotte.critique`, validated once. Every limit is a setting with a
 * conservative default; an out-of-range value is clamped, never trusted.
 */
final readonly class CritiqueSettings
{
    /**
     * @param  list<int>  $widths
     * @param  array<string, string>  $defaultModels
     */
    public function __construct(
        public ?bool $enabled = null,
        public ?string $provider = null,
        public ?string $model = null,
        public array $defaultModels = ['anthropic' => 'claude-haiku-5-5'],
        public int $maxRevisions = 1,
        public int $maxSteps = 1,
        public ?float $maxCostUsd = 0.05,
        public ?int $maxOutputTokens = 2048,
        public int $maxItems = 8,
        public array $widths = [375, 768, 1280],
        /** On a tree with an unmetered run, drop the critic's dollar cap (the step cap bounds it) rather than skip the critique. */
        public bool $allowUncappedWhenUnmetered = true,
        /** The builder's revision message carries the capture of each item's breakpoint, not only the items. */
        public bool $revisionIncludesScreenshot = true,
        /** The tallest capture accepted, in CSS px: one viewport, or one section of a tall page. */
        public int $maxCaptureHeight = 1200,
        /** Sections per width sent to the critic; the rest, from the bottom, are left out and counted. */
        public int $maxCapturesPerWidth = 6,
    ) {}

    public static function fromConfig(Repository $config): self
    {
        $c = (array) $config->get('charlotte.critique', []);
        $string = fn (mixed $v): ?string => is_string($v) && $v !== '' ? $v : null;
        $int = fn (mixed $v, int $default, int $min, int $max): int => is_numeric($v) ? max($min, min($max, (int) $v)) : $default;
        $widths = array_values(array_unique(array_filter(array_map('intval', (array) ($c['widths'] ?? [375, 768, 1280])), fn (int $w) => $w >= 200 && $w <= 4000)));
        $models = [];
        foreach ((array) ($c['default_models'] ?? ['anthropic' => 'claude-haiku-5-5']) as $provider => $model) {
            if (is_string($provider) && is_string($model) && $model !== '') {
                $models[$provider] = $model;
            }
        }
        $cost = $c['max_cost_usd'] ?? 0.05;

        return new self(
            enabled: is_bool($c['enabled'] ?? null) ? $c['enabled'] : null,
            provider: $string($c['provider'] ?? null),
            model: $string($c['model'] ?? null),
            defaultModels: $models,
            maxRevisions: $int($c['max_revisions'] ?? null, 1, 0, 5),
            maxSteps: $int($c['max_steps'] ?? null, 1, 1, 5),
            maxCostUsd: is_numeric($cost) && (float) $cost > 0 ? (float) $cost : null,
            maxOutputTokens: array_key_exists('max_output_tokens', $c) && $c['max_output_tokens'] === null ? null : $int($c['max_output_tokens'] ?? null, 2048, 256, 32768),
            maxItems: $int($c['max_items'] ?? null, 8, 1, 20),
            widths: $widths === [] ? [375, 768, 1280] : $widths,
            allowUncappedWhenUnmetered: is_bool($c['allow_uncapped_when_unmetered'] ?? null) ? $c['allow_uncapped_when_unmetered'] : true,
            revisionIncludesScreenshot: is_bool($c['revision_includes_screenshot'] ?? null) ? $c['revision_includes_screenshot'] : true,
            maxCaptureHeight: $int($c['max_capture_height'] ?? null, 1200, 300, 10000),
            maxCapturesPerWidth: $int($c['max_captures_per_width'] ?? null, 6, 1, 20),
        );
    }

    /** The critic's provider: configured, else the builder session's. */
    public function providerFor(string $sessionProvider): string
    {
        return $this->provider ?? $sessionProvider;
    }

    /** The critic's model: configured, else the provider's cheaper vision default, else the builder's own model. */
    public function modelFor(string $sessionProvider, string $sessionModel): string
    {
        return $this->model ?? $this->defaultModels[$this->providerFor($sessionProvider)] ?? $sessionModel;
    }
}
