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
  counts, phone numbers, emails, dates, years and quotes that no fact supports
  are flagged (`unsupported_claim`). No model is involved.
- **Page checks.** Heading outline, alt text, contrast where it can be
  computed, link safety, placeholder copy and meta, lifted from the Fancy lab
  that measured them.
- **Finish gates and a responsive starter.** `cms_finish` refuses (`not_ready`,
  with the defects) a page whose stacks and grids have no `layout`, whose links
  run together, that never changes at tablet or desktop width, that lacks an
  image the brief requires or a meta description, or that states a figure or
  date the brief does not give. Each defect names its node and rule; after
  `charlotte.finish.max_not_ready` refusals in a row (3) the agent stops and
  hands the defects back. An agent cannot write a stack or grid without a
  `layout`. The builder prompt carries a responsive skeleton, type scale and
  spacing scale to start from, as fancy-cms data.
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

**Component nodes and islands.** By default Charlotte reads fancy-cms's own
island catalogue (`{$schema, $defs, types}`). That gives the agent the
built-in nodes `chart`, `kpi`, `table`, `leaderboard` and `input`, placed
under those bare names; `island: true` is implied. To add your own islands
beside them, use `namespace:Name` types, which are placed with `island: true`:

```php
app()->instance(\Charlotte\Islands\IslandCatalogue::class,
    \Charlotte\Islands\CmsDescribeIslands::withHost([['type' => 'kit:Tabs', 'description' => 'Tabbed panels', 'props' => $schema]]));
```

Every node of a catalogue type is checked against its JSON Schema, with the
catalogue's `$defs`, for every actor. An unknown type, `richtext`, and
`island: true` on a native element are refused.

**Rendered contrast.** `Charlotte::renderedUsing()` hands Charlotte a browser's
measurements (`Checks\RenderedEvidence::fromAxe()` reads axe-core results), so
contrast is judged on the rendered page, islands included. Without one it is
judged on the document, and every check result says which ran.

**Render, critique, revise.** Hand Charlotte a renderer and a page turn gets
one design revision before it ends:

```php
Charlotte::screenshotsUsing(fn (SessionRecord $s, string $page, string $revision, array $widths): array =>
    array_map(fn (int $w) => Screenshot::png($w, $browser->png($previewUrl($page, $revision), $w)), $widths));
```

Once `cms_finish` passes its gates, the ready revision is captured at 375, 768
and 1280 px. A critic sub-agent (mode `charlotte_critic`: its own prompt, no
tools, one step) looks at the captures and an outline of the page's nodes, and
critiques visual design and responsive layout only, calibrated on the same
anchor scale a reviewer uses but never with a reviewer's prompt. It returns
items that each name a node on the page; the builder gets those items (never
the critic's scores), applies them, and calls `cms_finish` again. One
revision per turn by default (`charlotte.critique.max_revisions`).

The critic runs on prism-harness's sub-agent API with its own nested budget
(`charlotte.critique.max_steps`, `max_cost_usd`, `max_output_tokens`): it gets
the smaller of that and what the turn's tree has left, its thread sits beneath
the builder's, and its spend lands in the turn's ledger under the host's caps.
On a turn with no price table the dollar cap cannot be enforced, so the critic
is bounded by its steps and output tokens and reports its cost as `null`. Its
model defaults to a cheaper vision model for the provider
(`claude-haiku-5-5` on Anthropic; `charlotte.critique.model` to choose).
`critique.result` and `critique.revised` record the verdict, items, cost and
whether the revision ran. With no renderer nothing changes; with one, set
`charlotte.critique.enabled` to `false` to turn it off. Page sessions only:
a site's page sub-agents are not critiqued yet.

**How a site turn runs.** The site mode is an orchestrator. It plans the pages,
creates and routes them, and hands each page (and `@header` / `@footer`) to a
`charlotte` page sub-agent through prism-harness's sub-agent API: the site
mode declares the sub-agent and the harness offers its tool, in capped and
uncapped turns alike. Each sub-agent gets 40 steps or what the tree has left,
whichever is smaller, and a fresh context. The harness meters every parent
and child call against the one tree, so a child's steps and spend land in
the turn's budget, and a child resolves only the page tools. The orchestrator
never loads a whole document; it keeps page ids and outcomes.
`charlotte.site.max_steps` bounds the whole tree. A host `RunContext` is
optional for site turns, as for page turns.

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
- `item.<field>` and `index`: inside a `repeater` whose `items` binds `<stream>.rows`.

To bind one stream twice with different params, give it an alias:
`metrics#byStage.rows`.

**Page inputs.** An `input` node (`{"name": "period", "kind": "period"}`;
the kinds are period, daterange, person, select and multiselect) is a control
the visitor sets. A binding reads it with `{"$input": "period"}` as a `$scope`
or `$params` value. The name must belong to an input on the same page, and
the input's kind must fit what it feeds:

- a period or daterange input feeds a `period` scope;
- a person, select or multiselect input feeds a `set` or `tree` scope;
- only a select input feeds a param.

Two inputs can't share a name. An input's option list binds like any other
data: `"options": {"$bind": "people.rows"}`, where `people` is a stream with
`value` and `label` fields.

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
], inputs: $request->query(),                       // raw input values; fancy-cms checks them against each input
   descendants: fn (string $org) => $orgs->descendantIds($org),
   data: ['people' => ['rows' => $peopleOptions]],    // what bound input options resolve against
   periods: fn (string $preset) => $calendar->range($preset));   // '30d' => ['from' => ..., 'to' => ...]

$results = [];
foreach ($queries as $q) {
    // ['key' => 'metrics', 'stream' => 'metrics', 'params' => ['groupBy' => 'owner', 'metrics' => ['closed_revenue', 'win_rate']],
    //  'fields' => ['group', 'closed_revenue', 'win_rate'], 'scope' => [...narrowed]]
    $results[$q['key']] = $streams->fetch($q['stream'], $q['params'], $q['fields'], $q['scope']);   // ['rows' => [...], 'totals' => [...]], nulls kept
}
$rendered = \FancyCms\Render\PageRenderer::render($doc, Charlotte::dataContext($results));   // fancy-cms's $data argument
```

A `set` or `tree` that narrows to `[]`, or a `period` that narrows to `null`,
means the viewer sees nothing for that parameter. Return no rows. An empty
multiselect, or a period preset your `periods` callback doesn't know, narrows
nothing.

**The requests are fancy-cms's own.** `dataQueries` calls fancy-cms's
`DataRequests::queries()` and keeps each request verbatim under `request`.
You get the same queries, in the same order, keyed `<stream>` or
`<stream>#<alias>`, with every `{"$input"}` filled from the page's inputs.
Charlotte adds three things to each request: the checked `params`, the
`fields` to return, and the narrowed `scope`. A parity test holds this
against fancy-cms's shared fixture.

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
- `particle-academy/prism-harness` (0.23.2 or later) and
  `particle-academy/fancy-cms`, installed with Charlotte.

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
