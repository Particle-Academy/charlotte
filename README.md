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

## A whole site

Open with `site()` instead of `page()` and the agent builds several pages:
it creates and routes pages, edits a shared `@header` and `@footer` once for
every page, sets the nav, and makes a 404 page. Routes your app serves itself
are refused, so list them:

```php
// config/charlotte.php
'site' => ['reserved_routes' => ['/login', '/register', '/forgot-password', '/reset-password/*', '/dashboard']],

$session = Charlotte::for($request->user())->tenant((string) $team->getKey())->site('marketing')
    ->brief(Brief::fromArray($validated['brief']))->open();

$session->siteExport();                       // {pages[{route, title, description, doc}], header, footer, notFound, islands}
$session->publish($session->site()['revision'], (string) $request->user()->getKey()); // every page and region at once
```

`cms_check` adds site-wide checks: broken internal links, orphan pages, a
missing 404 or home page, and nav consistency.

**Kit islands.** Bind an `Islands\IslandCatalogue` (or let Charlotte read
fancy-cms's `cms_describe` catalogue once it ships one) and the agent can place
`{type: "kit:Tabs", island: true, props}` nodes, each checked against that
type's JSON Schema for every actor.

**Rendered contrast.** `Charlotte::renderedUsing()` hands Charlotte a browser's
measurements (`Checks\RenderedEvidence::fromAxe()` reads axe-core results), so
contrast is judged on the rendered page, islands included. Without one it is
judged on the document, and every check result says which ran.

**How a site turn runs.** The site mode is an orchestrator. It plans the pages,
creates and routes them, and hands each page (and `@header` / `@footer`) to a
`charlotte` page sub-agent through prism-harness's sub-agent API. Each
sub-agent gets 40 steps, a budget nested within the turn's, and a fresh
context. The orchestrator never loads a whole document; it keeps page ids and
outcomes. `charlotte.site.max_steps` bounds the whole tree.

## Reports / dashboards

Charlotte can build report and dashboard pages for your app. **The figures
come from your data, never from the model.** You declare the data streams your
app supplies; the agent binds fancy-cms's infographic nodes (`chart`, `kpi`,
`table`, `leaderboard`) and page inputs to them with `$bind`; you compute the
data per viewer when the page renders.

### 1. Declare the streams

```php
use Charlotte\Data\ArrayDataCatalogue;
use Charlotte\Facades\Charlotte;

Charlotte::dataCatalogue(ArrayDataCatalogue::fromArray([
    // One scope object for every stream. Kinds: set (ids), tree (ids + children), period (from/to).
    'scope' => [
        'people'        => ['kind' => 'set',    'description' => 'User ids'],
        'dealTypes'     => ['kind' => 'set',    'description' => 'Deal type keys'],
        'period'        => ['kind' => 'period', 'description' => 'Inclusive date range'],
        'organizations' => ['kind' => 'tree',   'description' => 'Organization ids; children:true adds child organizations'],
    ],
    'streams' => [
        'metrics' => [
            'description' => 'Pipeline metrics grouped by owner, stage or deal type.',
            'fields' => [                                   // the FULL field set; "?" = may be null
                'group' => 'string',
                'deals_won' => 'integer', 'closed_revenue' => 'number', 'deals_lost' => 'integer',
                'win_rate' => 'number?', 'avg_deal_size' => 'number?',
                'deals_added' => 'integer', 'pipeline_added' => 'number',
                'deals_open' => 'integer', 'open_pipeline' => 'number', 'spr' => 'number?',
            ],
            'totals' => ['deals_won' => 'integer', 'closed_revenue' => 'number', 'win_rate' => 'number?'],   // omit for plain rows
            'params' => [
                'groupBy' => ['owner', 'stage', 'dealType'],                  // a list of allowed values
                'metrics' => ['selects' => 'fields', 'always' => ['group']],  // picks which fields the rows carry
            ],
            'scope'  => ['people', 'dealTypes', 'period', 'organizations'],
        ],
        'revenueByRep'  => ['fields' => ['rep' => 'string', 'revenue' => 'number'], 'scope' => ['people', 'period', 'organizations']],
        'dealsCutEarly' => ['fields' => ['deal' => 'string', 'owner' => 'string', 'closedOn' => 'date', 'value' => 'number'],
                            'scope' => ['people', 'dealTypes', 'period']],
    ],
]));
```

Field types: `string`, `number`, `integer`, `boolean`, `date`. Add `?` (or
write `['type' => 'number', 'nullable' => true]`) when a value may be null. A
malformed declaration throws when you build it, not mid-run.

**Field-selecting params.** At most one param per stream may be declared as
`['selects' => 'fields', 'always' => [...]]`. Its value is a list of the
stream's field names, and the rows then carry that subset plus the `always`
fields. A binding to a column, a total or a repeat field outside the
selection is refused with `binding_invalid`, naming the selection. So is
selecting an unknown field. With no selection, every field is returned. The
value must be a literal list, not a page input, because every binding is
checked against it.

**Null is no value.** Keep nulls in your rows; never send 0 in their place.
fancy-cms renders null as no value: an empty table cell or chart point, a
skipped leaderboard row, or a KPI's empty state (its `emptyText`, for example
an em dash). Charlotte's data context keeps every row, and fills a field a row
lacks with null.

### 2. What the agent writes

`cms_describe` returns the catalogue under `data`. The agent binds paths, with
optional `$params` and `$scope`:

```json
{"label": "Revenue", "format": "currency",
 "value": {"$bind": "metrics.totals.closed_revenue",
           "$params": {"groupBy": "owner", "metrics": ["closed_revenue", "win_rate"]},
           "$scope": {"period": {"$input": "period"}, "organizations": {"ids": ["org-7"], "children": true}}}}
```

A table in the same query binds `metrics.rows`, and a chart binds
`metrics.columns.group` and `metrics.columns.win_rate`. A binding to
`metrics.columns.deals_lost` would be refused, because the selection does not
include it.

The paths are:

- `<stream>.rows`: the list of rows, for a table, a leaderboard or `repeat.each`.
- `<stream>.columns.<field>`: one field's values across the rows, for chart categories and series.
- `<stream>.totals.<field>`: one total, for streams that declare totals.
- `<as>.<field>`: inside a node repeating over `<stream>.rows`.

To bind one stream twice with different params, give it an alias:
`metrics#byStage.rows`.

A write that binds an unknown stream, field, param or scope parameter is
refused `binding_invalid`, naming the field and what exists. That applies to
every actor. With no catalogue bound, nothing can be bound.

**Fact checks:** a bound figure is evidence, not an `unsupported_claim`. A
number the agent types into a data prop is still checked against the brief.

### 3. Render for a viewer: scope only narrows

You state everything the viewer may see. Charlotte intersects each binding's
scope (and any page input) with it, so the page can only narrow the view,
never widen it. If the viewer scope leaves out a parameter, the call throws
instead of reading it as unrestricted.

```php
$queries = Charlotte::dataQueries($doc, viewer: [
    'people' => $visiblePeopleIds, 'dealTypes' => $visibleDealTypes,
    'period' => ['from' => '2026-01-01', 'to' => '2026-12-31'],
    'organizations' => $visibleOrgIdsIncludingChildren,
], inputs: $request->query(), descendants: fn (string $org) => $orgs->descendantIds($org));

$results = [];
foreach ($queries as $q) {
    // ['key' => 'metrics', 'stream' => 'metrics', 'params' => ['groupBy' => 'owner', 'metrics' => ['closed_revenue', 'win_rate']],
    //  'fields' => ['group', 'closed_revenue', 'win_rate'], 'scope' => [...narrowed]]
    $results[$q['key']] = $streams->fetch($q['stream'], $q['params'], $q['fields'], $q['scope']);   // ['rows' => [...], 'totals' => [...]], nulls kept
}
$rendered = \FancyCms\Render\PageRenderer::render($doc, Charlotte::dataContext($results));   // fancy-cms's $data argument
```

A `set` or `tree` that narrows to `[]`, or a `period` that narrows to `null`,
means the viewer sees nothing for that parameter. Return no rows.

### 4. Hand a brief off from your own agent

Charlotte runs as a prism-harness **sub-agent** of your agent, using the
harness's existing sub-agent API. Declare it in your mode:

```php
// config/prism-harness.php
'modes' => ['reports' => [
    'system_prompt' => 'You are the reports assistant.', 'tools' => [...], 'max_steps' => 60,
    'subagents' => ['charlotte' => \Charlotte\Guide\BuilderMode::subagent()],   // mode charlotte, 40 steps, nested budget
]],
```

Before each parent turn, bind the page. Tenant, page, actor and brief come
from your context, never from the parent's task text:

```php
$parent = PrismHarness::session($user, 'reports:'.$report->id)->usingMode('reports');
$page = Charlotte::delegate($user, $parent->scope(), tenant: (string) $team->id, page: 'q3-pipeline',
    brief: Brief::fromArray(['summary' => 'Q3 pipeline dashboard for the sales leads.', 'facts' => []]));

$parent->send('Build me a Q3 pipeline dashboard.');
// The parent calls `charlotte` with a task. The page is saved as a draft, and
// the result reaches the parent as data: {"draft": {"page": "q3-pipeline", "revision": "r..."}}.
$page->state();                          // read the draft by that reference
$page->publish($page->state()['revision'], (string) $user->id);   // publishing stays host code
```

Every Charlotte tool acts only on the bound page. A task or brief that names
another tenant's page reaches nothing: there is no tenant or page argument to
pass, and an undeclared one is refused. The child's run is its own harness
run, linked beneath the parent's thread. Call
`$page->bindAsSubagent($parent->scope())` again before a later parent turn,
because the binding's provider and model live in harness session state.

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
