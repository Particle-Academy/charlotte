# charlotte code map

`Charlotte\` is a PHP 8.4 library for Laravel 13. Runtime imports are
illuminate components plus three first-party packages: prism-harness (agent
sessions), prism (tools, stream events) and fancy-cms (PageSession, Agent).
Nothing in `src/` uses Laravel Foundation helpers (`config()`, `app()`);
read config through the container.

## Where things are

- `Charlotte` (singleton, facade `Facades\Charlotte`): `for()->tenant()->page()->brief()->open()`,
  `find($id, $tenant)`, `guide()`, `authorizeUsing()`, `resolveTenantUsing()`, `routes()`.
- `CharlotteSession`: the host-facing handle. Every method re-reads the stored
  session; a held handle never acts on a stale status or stop flag.
- `Workspace`: all page mutations and the events they record, each inside one
  DB transaction. Actor `agent` is fixed for tool writes; human writes carry
  the host's id. Page, tenant and actor never come from tool arguments.
- `Tools\PageTools`: the nine tools and the closed dispatch (`call()`), lifted
  from flabs `SiteTools` with the same argument bounds. `invalid_state` from
  fancy-cms is rethrown (host corruption); other PageSessionExceptions and
  `Policy\Refused` become `{isError, revision, error:{code}}` results.
- `Runtime\Turns` (send/stop), `Runtime\TurnRunner` (one queued turn),
  `Runtime\StreamRecorder` (Prism stream events -> log events). A turn may
  span several runs: a `length` finish before the page is ready is continued
  (`CONTINUATION`, at most `max_continuations`, then `output_limit`) on the one
  run context `forTurn` returned. Never call `forTurn` per continuation: the
  host's caps must bound the whole turn (`LabRunRegressionTest` checks).
- Tool argument errors name `$.arguments.<field>` with `expected` and `got`
  (`PageTools::problem`). A bare `$.arguments` left the model nothing to fix.
- `Runtime\TurnContextFactory` (optional host hook, `Charlotte::contextUsing()`):
  the per-turn prism-harness `RunContext`. Default `HarnessDefaults` returns
  null. `TurnRunner` calls it inside its try, so a host refusal is a coded
  `run.failed` before any provider call.
- `Storage\*`: the default DB implementations. `Drafts\PageRepository` is the
  host-replaceable contract for drafts and the published copy.
- `Http\Api`: the transport contract without a framework. `Laravel\CharlotteController`
  only adds the body limit, JSON decoding, the user and the tenant.
- `Checks\PageChecks` is the lab's file with `facts()`, `copy()`,
  `externalOrigin()` and `isSafeHref()` added; the checks themselves are
  unchanged and `tests/Unit/PageChecksTest.php` keeps the lab's assertions.
- **Site sessions** (`->site($key)`, `Charlotte::openSite`): `SessionRecord::$site`
  is set and `page` is the SELECTED document's key. `Site\Site` is the
  structure (pages: id, kind `page|not_found`, title, route; regions
  `@header`/`@footer`); documents live in the PageRepository under
  `<site>/<id>`. Site and page ids cannot hold `/`, so one site's keys never
  name another's. `Site\SiteRepository` (default `Storage\DatabaseSiteRepository`,
  `charlotte_sites`) holds the structure; `Site\ReservedRoutes` is the host's
  list (config `charlotte.site.reserved_routes`, K13). `SiteWorkspace` does
  create/route/select/nav, the site revision (sha1 of structure + every doc
  revision), site checks, finish and publish; every document write still goes
  through `Workspace` via `SessionRecord::onPage()`. Mode `charlotte_site_builder`
  is an ORCHESTRATOR: `BuilderMode::SITE_TOOLS` (brief, `site_*`, `cms_check`,
  `cms_finish`, `charlotte_page`), no whole-document tools. `charlotte_page`
  spawns a `charlotte` page sub-agent on the selected document; `TurnRunner`
  picks the mode per session. Proposals are keyed by `page_key` for site sessions
  (null for page sessions, as in 0.3).
- **Finish gates**: `Checks\QualityChecks` (`layout_missing`, `link_spacing`,
  `responsive_layout`, `required_image_missing` / `required_image_unavailable`)
  runs in `Checks::run` with the AssetCatalogue's `catalogue()`.
  `Checks::FINISH_GATES` + `gates()` are what `Workspace::finish` and
  `SiteWorkspace::finish` refuse on (`Refused('not_ready', details: defects)`);
  every other error only reports. Both record `checks.result` BEFORE deciding
  and throw after the transaction, so a refusal is logged and nothing is marked.
  `unsupported_claim` is an error for every kind but `quote`; dates compare by
  meaning (`FactChecks::dates()`). Never make `required_image_unavailable` a
  gate: the agent cannot place an asset the host never supplied. Site pages skip
  `Checks::SITE_WIDE` (judged across docs in `siteDefects`); regions skip
  `REGION_SKIP`; a `not_found` page also skips `responsive_layout`
  (`SiteWorkspace::skip()`, `skipFor()` for a page sub-agent's finish).
  Why: fancy-cms emits `display:flex`/`grid` ONLY from a node's `layout`; a
  stack or grid without it is a block (flabs runs 423-463).
  `Checks::locate()` gives every gate defect a `node` (else `page:<doc id>`)
  and `rule` (`Checks::RULES`).
- **The not_ready limit**: `charlotte_sessions.finish_refusals` counts
  consecutive refusals (`Workspace::refuseFinish()`, `limited()`). At
  `charlotte.finish.max_not_ready` the code is `not_ready_limit`; `PageTools`
  then refuses `cms_apply`/`site_nav_set`, and `TurnRunner` does not continue
  a `length` stop. Reset on: successful finish, `Turns::send`, site page
  create/select, `PageSubagents::bind()` and each `charlotte_page` spawn. Any
  new "new work" entry point must reset it too, or an agent stays stopped.
- `Design\Starter`: the responsive skeleton/type/spacing data, in the guide
  as its closing section (`Starter::promptSection()`, appended by
  `PublishedGuide`), so it is in every mode prompt and the guide hash, NOT in
  a tool result (Prism maintainers: tool results are framed as data). Its nav
  id is `SiteWorkspace::NAV_ID` so `site_nav_set` fills it. `FinishGateTest`
  proves the skeleton clears the gates once filled and that fancy-cms's
  CssEmitter renders it as flex and grid, and caps the section under 7000 bytes.
- `Checks\SiteChecks`: broken links, anchors, orphans, unrouted pages, home,
  404, nav consistency, region h1/anchors, duplicate titles, and
  `missing_required_fact` across the whole site.
- **Rendered checks** (S12): `Checks\RenderedEvidenceSource` (default
  `NoRenderedEvidence`; `Charlotte::renderedUsing()`) returns a
  `RenderedEvidence` for a document. `Checks::run` uses it when its revision
  is the checked one (or the site revision) and then replaces document
  contrast verdicts; the result's `contrast` says `rendered` or `document`
  with a reason. A throwing source is `rendered_report_failed`, never a failure.
- **Islands**: `Islands\IslandCatalogue` (default `CmsDescribeIslands::catalogue()`,
  which reads fancy-cms's `{$schema, $defs, types}` catalogue from
  `Agent::describe()['islands']`). Its types are fancy-cms BUILT-INS: bare
  names (`chart`, `kpi`, `table`, `leaderboard`, `input`, which are also
  native node types in fancy-cms), `island: true` implied, never one of
  `SchemaIslandCatalogue::NEVER_ISLANDS`. Host islands are `namespace:Name`
  (`withHost()`, `fromArray()`). `Islands\PropsSchema` is our own JSON Schema
  subset (no dependency), with `$ref` into the catalogue's `$defs`.
  `NodeTypes::forStored()` adds island types already in a stored history, so
  a narrowed catalogue never makes a draft unreadable.
- **Data (report dashboards)**: `Data\DataCatalogue` (default empty
  `ArrayDataCatalogue`; `Charlotte::dataCatalogue()`). `Data\Bindings` finds
  every `$bind` (props at any depth, `repeat.each`), validates paths
  (`<query>.rows|columns.<f>|totals.<f>`, `<as>.<f>`, `<stream>#<alias>`),
  `$params` and `$scope`, and builds render queries. `Data\Scope::narrow()` is
  the only place scope is resolved: intersection with the viewer's stated
  scope, fail-closed on a missing parameter. A param declared
  `{selects: fields, always}` limits a query's columns, totals and repeat
  fields to its literal selection (`Bindings::selection`); a query's params are
  its first binding's (`queryParams`). `$input` references must name a page
  `input` node of a fitting kind (`INPUT_FITS`); a `repeater` gives its
  children `item`/`index`. `Bindings::queries()` DELEGATES to fancy-cms
  `DataRequests::queries()` (parity test: `tests/fixtures/fancy-cms-data-queries.json`,
  a byte copy of fancy-cms's fixture; refresh it when fancy-cms changes it).
  Field types may end in `?`; `context()`
  keeps nulls and never coerces to 0. `FactChecks` skips binding objects and
  checks literal numbers under `FIGURE_KEYS`.
- **Sub-agents** (`Runtime\PageSubagents`, a singleton): host code binds a
  child harness address (`<parent scope>::sub::<suffix>`) to a Charlotte
  session as capability `charlotte {session, scope}` plus provider and model.
  `Charlotte::delegate()` does it for a host's own agent; `TurnRunner` does
  it for a site orchestrator's `::sub::page` child each turn, and `begin()`s
  the turn with the ONE `RunContext` the stream also gets (the host's; a site
  turn without a cost-capped one is refused `site_needs_capped_context` until
  prism-harness G-261/G-262), so `tool()` can offer `charlotte_page` through
  the harness's
  `SubagentRunner` (prism-harness 0.17's uncapped `stream()` drops declared
  sub-agents; when the harness offers its own tool of that name, it replaces
  ours). `site_page_create` / `site_page_select` retire the child's thread so
  each page starts fresh. `PageTools(child: true)` = the nine tools, no site
  tools, and `cms_finish` returns `{draft: {page, revision}}` without setting
  the site's ready revision.

## Invariants a change must keep

- **Nothing publishes except `Workspace::publish()`**, reached only from
  `CharlotteSession::publish()` (directly, or per document through
  `SiteWorkspace::publish()`) and the `publish` route. No tool name may
  contain `publish`; `DraftsTest` checks.
- **Node types exclude `richtext` for every actor** (`Drafts\NodeTypes`).
  Every PageSession here must be built with `NodeTypes::allowed($islandTypes)`
  (new documents) or `NodeTypes::forStored($json, $islandTypes)` (stored
  ones). Host island types always contain a colon, and a bare built-in may
  never be `richtext` or a native element (`NEVER_ISLANDS`), so neither can
  re-admit richtext.
- **`AssetPolicy::assert` runs after every write** (agent, human, approval
  replay, publish). It refuses authored image URLs, dimensions and any CSS
  fetch, escape or comment. **`IslandPolicy::assert` runs after every write
  too** (not publish: nothing changes), on the nodes that write created or
  changed: a host island needs `island: true` and a catalogue entry, a
  built-in may omit the flag, `island: true` elsewhere is refused; props valid
  for the schema, no image URL in props, every `asset` handle in the
  AssetCatalogue.
- **`LayoutPolicy::assert` runs on AGENT writes** (`applyAgent`, both the
  full and staged paths), on the nodes the write created or changed: a
  `stack` or `grid` needs `layout` `stack|grid|free`, refused
  `layout_required` with no default filled in. Human writes are not refused.
- **A tool argument never names a site or tenant**; `page` is a page id
  (regex, no slash) or a region, resolved inside the session's bound site.
- **`LinkPolicy::assertOps` runs on agent ops only**, before the write.
- **Events are appended inside the mutation's transaction.** A rolled-back
  write must leave no event. `DatabaseEventLog::append` locks the session row
  and the unique (session_id, seq) index is the backstop for gap-free seq.
- **No exception message reaches the log or an API body.** `run.failed`
  carries a code (`Refused::reason`, `HasErrorCode::code()`, else
  `run_failed`); API errors use `Refused::MESSAGES` only.
- **Tools bind to a session through harness capability state**, written only
  by host code (`TurnRunner`, `PageSubagents::bind()`), and the provider in
  `CharlotteServiceProvider::boot()` also requires the harness scope to be
  `charlotte:<id>`, or for mode `charlotte` the exact address the capability
  names, on the session's own participant (`PageSubagents::boundSession()`;
  a site's child only at `charlotte:<id>::sub::page`).
- **`BindingPolicy::assert` runs after every write** with the asset and island
  policies, on the nodes the write changed.
- **Telemetry is optional.** `Telemetry\UsageTelemetry` is the only reader of
  Prism telemetry, swallows its own failures, and nothing reads
  `usage.observed`. Never make a turn depend on it. Prism fires its event for
  EVERY generation in the host: check the flags and the session key before
  resolving anything from the container (`TelemetryLazyTest`).
- **Billing is optional.** Nothing may require a `TurnContextFactory`; with
  none bound a page turn passes `context: null` and runs on harness config.
  EXCEPTION until prism-harness G-261 and G-262 ship: a SITE turn refuses
  (`site_needs_capped_context`) without a host context that has `maxCostUsd`,
  because only a capped tree meters each page sub-agent call. Remove the guard
  in `TurnRunner::run()` when both ship.
- **Event types**: adding one means updating `Events\EventTypes::ALL`,
  `resources/schema/events.v1.json`, `tests/fixtures/events.v1.json` and
  `docs/events.md`; `EventContractTest` fails until all agree.

## approve_on_save mechanics

One open change set per session, or per (session, page) on a site
(`charlotte_proposals.page_key`); approval replays onto the proposal's page. Its `working_json` is
a PageSession seeded from the draft, so the agent's revisions stay continuous.
`cms_get` / `cms_changes_since` / `cms_check` read the working copy while a set
is open. Approval REPLAYS the accumulated ops on the current draft (chunks of
1000, actor `agent`); any PageSessionException or Refused during replay rolls
the savepoint back and resolves the set `conflicted`. Resolutions reach the
agent once, as `notices` on the next `cms_get` or `cms_apply`.

## The guide and the builder mode

`CharlotteServiceProvider` merges `charlotte_builder` into prism-harness's
modes by `extend()`ing `ModeRegistry`, so it is built on first resolve, after
host overrides registered in `boot()`. A host-defined mode of that name wins.
`TurnRunner` refuses (`guide_mode_stale`) when the current guide hash differs
from the session's or the mode prompt does not embed it exactly once.

## Tests

Pest, with a hand-built kernel (`tests/Support/TestApp.php`; no testbench):
in-memory SQLite, array cache, sync queue, and `ScriptedProvider`, a Prism
provider that executes the tools the run offers. Step arguments may be a
closure over earlier results, which is how scripts pin `basedOn`. HTTP tests
dispatch through the real router; bind `request` before setting the user
resolver, because the auth provider's rebinding hook replaces it.
