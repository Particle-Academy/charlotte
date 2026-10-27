<?php

declare(strict_types=1);

return [

    /*
    | Any Prism provider and model. Charlotte never refuses a provider: cost
    | and billing telemetry is optional (see `telemetry`), so no price table is
    | required to run. Unset falls back to prism-harness.agent.provider/model.
    */
    'provider' => env('CHARLOTTE_PROVIDER'),
    'model' => env('CHARLOTTE_MODEL'),

    /*
    | How the agent's edits land on the DRAFT. Nothing publishes in either
    | mode; publishing is a separate host action.
    |
    |   full             each cms_apply changes the draft at once
    |   approve_on_save  edits collect as a pending change set that a person
    |                    approves (saved to the draft) or rejects
    */
    'autonomy' => env('CHARLOTTE_AUTONOMY', 'approve_on_save'),

    // The builder mode's step ceiling and Prism provider options, passed
    // through unchanged (e.g. ['cache_control' => ['type' => 'ephemeral']]
    // for Anthropic). A mode named charlotte_builder in prism-harness.agent.modes
    // replaces Charlotte's entirely.
    'max_steps' => (int) env('CHARLOTTE_MAX_STEPS', 40),
    'provider_options' => [],

    // External origins an AGENT may link to. Relative paths, anchors,
    // mailto: and tel: are always allowed. e.g. ['https://example.com']
    'links' => [
        'allow_origins' => [],
    ],

    /*
    | OPTIONAL cost and usage observation, through Prism's own telemetry.
    | Off unless BOTH this flag and prism.telemetry.enabled are on, and only
    | when the installed Prism ships its telemetry events (feature-detected).
    | When on, each generation emits a usage.observed event. A missing price
    | reports costUsd null; it never refuses a turn. Nothing else in
    | Charlotte depends on this.
    |
    | prices: provider => model => ['input' => USD per MTok, 'output' => USD per MTok]
    */
    'telemetry' => [
        'enabled' => (bool) env('CHARLOTTE_TELEMETRY', false),
        'prices' => [],
    ],

    'transport' => [
        // Upper bound for ?wait= on the events long-poll; never above 25.
        'poll_wait_seconds' => 20,
        'poll_interval_ms' => 250,
    ],

    // The optional controller kit. Off until you call Charlotte routes or
    // enable it here; every route also requires your authorization hook.
    'routes' => [
        'enabled' => false,
        'prefix' => 'charlotte',
        'middleware' => ['web', 'auth'],
        'max_body_bytes' => 262144,
    ],

    // Where turns run. The model loop never runs inside an HTTP request.
    'queue' => [
        'connection' => env('CHARLOTTE_QUEUE_CONNECTION'),
        'queue' => env('CHARLOTTE_QUEUE'),
    ],
];
