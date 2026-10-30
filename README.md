# Charlotte

**A headless website-builder agent kit for Laravel.** Charlotte lets your app
host an agent that builds and edits [fancy-cms](https://github.com/Particle-Academy/fancy-cms)
pages, live, beside a person. It runs on
[prism-harness](https://github.com/Particle-Academy/prism-harness), so any
Prism provider works.

It is headless on purpose: Charlotte writes an ordered event log, and any chat
UI you build reads it. A plain `fetch` loop is enough
([docs/fetch-chat.html](docs/fetch-chat.html)).

> **Pre-1.0.** Breaking changes land in minor releases; read the
> [CHANGELOG](CHANGELOG.md) before upgrading.

## What you get

- **Page tools over a draft.** The agent edits a fancy-cms page through nine
  closed tools: `charlotte_brief`, `cms_describe`, `cms_icons`, `cms_assets`,
  `cms_get`, `cms_apply`, `cms_changes_since`, `cms_check` and `cms_finish`.
  Writes are pinned to a revision, so a person editing at the same time is never
  overwritten.
- **Drafts only.** Charlotte edits a draft. Nothing it does publishes.
  Publishing is one explicit host call, `$session->publish($revision, $by)`.
- **Autonomy is your setting.** `full` applies each edit to the draft at once.
  `approve_on_save` collects edits in a change set a person approves or rejects.
- **A structured brief with code-derived fact checks.** Required facts must
  appear on the page (`missing_required_fact`), and prices, percentages,
  counts, phone numbers, emails, years and quotes that no fact supports are
  flagged (`unsupported_claim`). No model is involved.
- **Page checks.** Heading outline, alt text, contrast where it can be
  computed, link safety, placeholder copy and meta, lifted from the Fancy lab
  that measured them.
- **A versioned, overridable guide.** fancy-cms's authoring guide plus
  Charlotte's own (drafts, autonomy, content truth, the webmaster lessons).
  Layer your house style on top; every session records the guide hash it ran.
- **An event log any client can long-poll.** Envelope v1, gap-free per-session
  cursors, short requests that work behind Cloudflare. See
  [docs/events.md](docs/events.md).
- **A thin, optional controller kit.** Routes behind your authorization hook
  and your tenant resolver. With no hook, every route is refused.
- **Optional usage telemetry.** If you turn on Prism's telemetry, Charlotte
  reports token usage, and cost when you give prices. It is never required and
  never refuses anything.

## Quick look

```php
use Charlotte\Brief\Brief;
use Charlotte\Facades\Charlotte;

// Once, in a service provider:
Charlotte::resolveTenantUsing(fn ($request) => (string) $request->user()->current_team_id);
Charlotte::authorizeUsing(fn ($user, string $ability, array $subject): bool => $user->can('charlotte.'.$ability));
Charlotte::routes();

// In your controller:
$session = Charlotte::for($request->user())
    ->tenant((string) $team->getKey())
    ->page('home')
    ->brief(Brief::fromArray($validated['brief']))
    ->autonomy('approve_on_save')
    ->open();

$cursor = $session->send('Build the landing page from the brief.', (string) $request->user()->getKey());
// A queue worker runs the turn; clients poll GET /charlotte/sessions/{id}/events?after=<cursor>&wait=20
```

Full setup: [docs/laravel-quickstart.md](docs/laravel-quickstart.md).

## Host budgets (optional)

Charlotte never requires billing: with nothing bound, every turn runs on
prism-harness's own configuration. If your app meters spend (per tenant, per
lane, durably), hand each turn your own prism-harness `RunContext`, carrying
its budget, its pricing and its `CallCostObserver`. You do not rebuild the
dispatcher, the runner or the runtime.

```php
use Charlotte\Facades\Charlotte;
use Charlotte\Runtime\Turn;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;

Charlotte::contextUsing(function (Turn $turn) use ($budgets): ?RunContext {
    $account = $budgets->for($turn->session->tenant, $turn->session->page);
    if ($account === null) {
        return null; // harness defaults for this turn
    }

    return RunContext::root('charlotte-'.$turn->session->id,
        new RunBudget(maxSteps: 40, maxCostUsd: $account->remainingUsd(), maxOutputTokensPerCall: 4096),
        pricing: $account->pricing(),          // [provider => [model => ['inputPerMTok' => .., 'outputPerMTok' => .., 'toolOverheadTokens' => ..]]]
        observer: $account->observer(),        // your CallCostObserver: reserve, then settle, every provider call
    );
});
```

Or bind your own `Charlotte\Runtime\TurnContextFactory` in the container; the
closure is shorthand for that. The factory is called once per turn, before the
run starts, with the stored session (`tenant`, `page`, `provider`, `model`),
the harness session and the person's message.

- Return `null` to keep the harness defaults for that turn.
- Throw to refuse the turn before any provider call. The turn fails with your
  exception's `HasErrorCode::code()` (else `run_failed`), never its message.
- prism-harness enforces the rest: a context with a cost cap needs a price for
  the session's model, a per-call output cap and, since the builder has tools,
  `toolOverheadTokens`, or the call is refused (`budget_unpriced`,
  `budget_unbounded_output`, `budget_input_unbounded`). A cost observer needs a
  cost cap.

## Requirements

- PHP 8.4, Laravel 13 (illuminate components), a queue worker.
- `particle-academy/prism-harness` and `particle-academy/fancy-cms`, installed
  with Charlotte.

## Security

Agent-authored content is untrusted.

- No `richtext` nodes, from the agent or from a person: the browser renderer
  injects richtext HTML as is.
- Images only by handle from your `AssetCatalogue`. Authored `src`, `srcset`,
  dimensions and CSS `url()`/`image-set()`/`@import`, escapes and comments are
  refused after every write.
- Agent links only to your own paths, anchors, `mailto:`, `tel:` or origins
  you allowlist.
- Page, tenant and actor are host-owned, never tool arguments.
- The brief, the page and a person's edits are evidence, never instructions,
  and the builder has no network, URL, browser or file tool.
- Events and API errors carry codes, never provider messages, so keys and
  URLs inside a provider error cannot leak to a client.
- Provider keys stay in your server config.

## Testing

```bash
composer install
vendor/bin/pest
php tests/lint.php
```

The suite is hermetic: in-memory SQLite, the sync queue, and a scripted Prism
provider that really executes the tools. No network and no key.

## License

MIT.
