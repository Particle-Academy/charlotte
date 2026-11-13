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
    // stops, and edits and finishes are refused until new work starts. A page
    // sub-agent's count is per run, so each child gets the full allowance; a
    // top-level turn's resets on a new message or a page created or selected. Min 1.
    'finish' => [
        'max_not_ready' => (int) env('CHARLOTTE_FINISH_MAX_NOT_READY', 3),

        // The design gates grid_orphan, hero_thin, tap_target and bare_list.
        // While true (the default) they are reported as WARNINGS marked
        // `staged` and never refuse cms_finish, so a run shows how often the
        // agent fixes them unprompted. Set false to make them blocking.
        'staged_gates' => (bool) env('CHARLOTTE_FINISH_STAGED_GATES', true),
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

    /*
    | Render, critique, revise (page sessions). Needs a renderer from the
    | host (Charlotte::screenshotsUsing()); without one none of this runs.
    | Once cms_finish passes its gates, the ready revision is captured at
    | `widths`, a design critic sub-agent (mode charlotte_critic, its own
    | prompt, no tools) critiques visual design and responsive layout from
    | the captures, and the builder applies its items and calls cms_finish
    | again, at most `max_revisions` times per turn.
    |
    | enabled: null = on whenever a renderer is supplied; false = off.
    | provider/model: the critic's. Unset: the session's provider, and
    | `default_models[provider]` (a cheaper vision-capable model), else the
    | builder's own model.
    | max_steps / max_cost_usd / max_output_tokens: the critic's OWN budget.
    | It nests in the turn's run tree: it gets the smaller of these and what
    | the tree has left, and its spend counts against the turn's caps.
    | max_items: critique items kept (the rest are counted as `dropped`).
    |
    | allow_uncapped_when_unmetered: on a run tree with an unmetered call (no
    | price for some model, billing off) a dollar cap cannot be enforced, and
    | prism-harness refuses a capped child there. true (default): the critic
    | runs WITHOUT its dollar cap, bounded by max_steps (1), and
    | critique.result records costCap `dropped_unmetered`. false: no critic
    | runs on such a tree (outcome `unmetered`).
    | revision_includes_screenshot: the builder's revision message carries the
    | capture of each item's breakpoint as well as the items (default true);
    | false sends the items as text only, for an A/B in a host lab.
    | max_capture_height: the tallest capture accepted, in CSS px. Renderers
    | capture one viewport at a time at native resolution (or one section of
    | a tall page), never one tall strip scaled down.
    | max_captures_per_width: sections per width sent to the critic; the rest,
    | from the bottom of the page, are left out and counted.
    */
    'critique' => [
        'enabled' => env('CHARLOTTE_CRITIQUE') === null ? null : (bool) env('CHARLOTTE_CRITIQUE'),
        'provider' => env('CHARLOTTE_CRITIC_PROVIDER'),
        'model' => env('CHARLOTTE_CRITIC_MODEL'),
        'default_models' => ['anthropic' => 'claude-haiku-5-5'],
        'max_revisions' => (int) env('CHARLOTTE_CRITIQUE_MAX_REVISIONS', 1),
        'max_steps' => 1,
        'max_cost_usd' => (float) env('CHARLOTTE_CRITIQUE_MAX_COST_USD', 0.05),
        'max_output_tokens' => 2048,
        'max_items' => 8,
        'widths' => [375, 768, 1280],
        'allow_uncapped_when_unmetered' => (bool) env('CHARLOTTE_CRITIQUE_ALLOW_UNCAPPED_WHEN_UNMETERED', true),
        'revision_includes_screenshot' => (bool) env('CHARLOTTE_CRITIQUE_REVISION_SCREENSHOT', true),
        'max_capture_height' => 1200,
        'max_captures_per_width' => 6,
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
