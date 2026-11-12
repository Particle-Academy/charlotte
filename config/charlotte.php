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

    // The builder mode's step ceiling (also each `charlotte` page
    // sub-agent's) and Prism provider options, passed
    // through unchanged (e.g. ['cache_control' => ['type' => 'ephemeral']]
    // for Anthropic). A mode named charlotte_builder in prism-harness.agent.modes
    // replaces Charlotte's entirely.
    'max_steps' => (int) env('CHARLOTTE_MAX_STEPS', 40),
    'provider_options' => [],

    // A response cut off at the output-token limit (finishReason `length`)
    // before the page is ready is continued with a short host message, at
    // most this many times per turn; past that the turn fails `output_limit`.
    // Models that reason before acting (Anthropic adaptive thinking) can
    // spend a small per-call output cap on reasoning alone. A host that caps
    // output tightly should also lower reasoning effort in provider_options.
    'max_continuations' => (int) env('CHARLOTTE_MAX_CONTINUATIONS', 3),

    // cms_finish refuses (not_ready) while a finish gate fails. After this
    // many refusals in a row it returns not_ready_limit instead: the agent
    // stops, and edits and finishes are refused until new work starts (a new
    // message, a new page sub-agent, a page selected, a delegation). Min 1.
    'finish' => [
        'max_not_ready' => (int) env('CHARLOTTE_FINISH_MAX_NOT_READY', 3),
    ],

    /*
    | Whole-site sessions (Charlotte::for($user)->tenant($t)->site($key)).
    |
    | reserved_routes: routes YOUR app serves, where a site page would never
    | be shown. Exact routes, or prefixes ending in /*. A Fortify app owns at
    | least ['/login', '/register', '/forgot-password', '/reset-password/*',
    | '/two-factor-challenge', '/user/*', '/email/*']; add every route your
    | app registers outside the site (/dashboard, /settings/*, /api/*, ...).
    | A site page on one of them is refused with route_reserved.
    |
    | max_pages: pages per site, the 404 included. max_steps: the site
    | orchestrator's step ceiling for the WHOLE run tree. The orchestrator
    | hands each page and region to a `charlotte` page sub-agent of
    | charlotte.max_steps steps, and those budgets nest within this one, so
    | null means max_steps x (max_pages + 3): every page, both regions and the
    | orchestrator's own steps.
    */
    'site' => [
        'reserved_routes' => [],
        'max_pages' => (int) env('CHARLOTTE_SITE_MAX_PAGES', 12),
        'max_steps' => env('CHARLOTTE_SITE_MAX_STEPS') === null ? null : (int) env('CHARLOTTE_SITE_MAX_STEPS'),
    ],

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
