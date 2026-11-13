# Changelog

Notable changes to `particle-academy/charlotte`.

**BREAKING** marks anything that can stop working on upgrade. This package is
pre-1.0, so breaking changes land in MINOR releases; read those entries before
upgrading.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [Unreleased]

## [0.4.0] - 2026-11-12

Whole sites, kit islands and rendered contrast (lab plan
`lab-full-kit-site-benchmark.md`, phase P2; findings K13, S10, S12), then
report dashboards and Charlotte as a prism-harness sub-agent (plan
`report-dashboards.md`, phase 3; charlotte#1).

**Upgrading, dashboards and sub-agents:** nothing to do unless you use them.
Two behaviours change for everyone: **every `$bind` is now checked against
the host `DataCatalogue`**, and with none bound (the default) a write that
adds or changes a binding is refused `binding_invalid`. A host that never
bound data sees no difference; one that let pages bind `repeat.each` must
declare those streams. And a **literal number in a data prop** (`value`,
`delta`, `rows`, `series`, `data`, `categories` …) is now an
`unsupported_claim` (kind `figure`) unless the brief carries it.

**BREAKING, `cms_finish` now refuses a page that is not ready** (flabs runs
423-463: 23 of 23 Charlotte pages had their facts right and were judged "bare
default"). It returns `isError` with code `not_ready` and the failing defects,
and marks nothing ready, while any of these errors remains: `layout_missing`,
`link_spacing`, `responsive_layout`, `required_image_missing`,
`meta_description`, `unsupported_claim`, and the design gates `grid_orphan`,
`hero_thin`, `tap_target` and `bare_list` (flabs runs 504-512: every page
passed structure and failed "professional" on those faults). Every other error is still only
reported, as before. **What to do:** nothing in a host, unless your own tests
finish deliberately bare pages; those now get `not_ready`. An agent reads the
defects and fixes them. And **`unsupported_claim` is now an ERROR** for every
kind except `quote` (still a warning), so `cms_check` summaries count more
errors.

**BREAKING, an agent may not write a `stack` or `grid` without `layout`.**
`cms_apply` refuses it with `layout_required`, naming the node
(`$.nodes.<id>.layout`) and the allowed values (`"stack"`, `"grid"`,
`"free"`); no default is filled in. **What to do:** nothing in a host; human
writes are not refused. A host script that drives the tools with layout-less
stacks must add the layout.

**Upgrading, also:** run the second new migration
(`2026_11_12_000000_add_charlotte_finish_refusals`: an unsigned
`charlotte_sessions.finish_refusals`, default 0, and a nullable
`finish_refusals_run`).

**Upgrading:** run the new migration (`2026_11_11_000000_add_charlotte_sites`:
a `charlotte_sites` table, nullable `charlotte_sessions.site_key` and
`charlotte_proposals.page_key`). **The guide text changed** (new sections),
so the guide hash changes: a session opened before the upgrade refuses its
next turn with `guide_mode_stale`, as on any guide change. Open a new session.
Page sessions otherwise behave as before: same nine tools, same events, same
proposals. **`Workspace`'s constructor gained two parameters** (island
catalogue, rendered-report source); only hosts that construct it by hand
instead of resolving it from the container need to change.

### Added
- **Whole-site sessions.** `Charlotte::for($user)->tenant($t)->site($key)->brief($b)->open()`
  (or `POST /sessions {site, brief}`) opens a session that builds several
  pages. It runs in a new harness mode, `charlotte_site_builder`, an
  ORCHESTRATOR (Prism maintainers, msg_ccda3aeb: no 120-step turn): its tools
  are `charlotte_brief`, `site_get`, `site_page_create`, `site_page_select`,
  `site_page_route`, `site_nav_set`, `cms_check`, `cms_finish` and
  `charlotte_page`, which spawns a `charlotte` page sub-agent for the selected
  page or region (40 steps, budget nested within the turn's). The
  orchestrator never loads a whole document; it keeps page ids and outcomes.
  Each page sub-agent starts on a fresh conversation. Each page has its own
  document and a real route; `@header` and `@footer` are shared regions edited
  once for every page; one page of kind `not_found` is the 404;
  `site_nav_set` writes the header nav as links to the pages' routes. The
  `cms_*` tools act on the selected document. `cms_finish` and `publish()`
  take the SITE revision and cover every page and region.
  `CharlotteSession::site()` and `siteExport()` return the structure and the
  site in the lab kit-site `site.json` shape. `charlotte.site.max_steps` is the
  whole tree's ceiling and now defaults to `max_steps x (max_pages + 3)`.
- **Reserved routes (K13).** `charlotte.site.reserved_routes`: routes the host
  serves itself (Fortify's `/login`, `/register`, `/forgot-password` …), exact
  or `/prefix/*`. A page there is refused with `route_reserved`, naming the
  route and the entry it matched. Malformed routes are `route_invalid`.
- **Site-wide checks** (`Checks\SiteChecks`), in `cms_check` and `cms_finish` on
  a site: `site_link_broken`, `site_anchor_broken`, `site_orphan_page`,
  `site_page_unrouted`, `site_missing_home`, `site_missing_404`,
  `site_404_no_home_link`, `site_nav_missing`, `site_nav_incomplete`,
  `site_nav_duplicate`, `site_nav_in_page`, `site_region_h1`,
  `site_region_anchor`, `site_title_duplicate`; required facts are judged
  across the whole site.
- **Rendered contrast (S12).** `Charlotte::renderedUsing()` / a bound
  `Checks\RenderedEvidenceSource` supplies `Checks\RenderedEvidence` (built
  from axe-core results with `RenderedEvidence::fromAxe()`). When its revision
  matches, contrast is judged on the rendered page, islands included, and
  replaces the document's contrast verdicts. Every `checks.result` now
  carries `contrast: {source: "rendered" | "document", ...}` saying which ran.
  No browser and no dependency in Charlotte: the host or lab supplies it.
- **Kit islands (S10).** `Islands\IslandCatalogue`, defaulting to
  `CmsDescribeIslands::catalogue()`, which reads fancy-cms's `describe()`
  island catalogue when the installed fancy-cms has one (none does yet) and
  is empty otherwise. A host may bind `Islands\SchemaIslandCatalogue` now.
  `cms_describe` returns `islands {source, types, place}`. Island nodes are
  checked after every write, for every actor (`Islands\IslandPolicy`): catalogue
  type iff `island: true`, props valid for the type's JSON Schema
  (`island_invalid` with the field's path), no image URL in props, asset
  handles from the host catalogue.
- Copy inside island props counts for `missing_required_fact` and
  `unsupported_claim`.
- Event type `site.changed`; site sessions add `page` to `doc.changed` and
  `proposal.*`. See `docs/events.md`.
- Tool refusals may carry agent-facing details beside the fixed message (for
  example `route` and `reserved` on `route_reserved`), and a refusal without
  its own revision now reports the current one.
- **Host data catalogue (report dashboards).** `Data\DataCatalogue`, built
  with `Data\ArrayDataCatalogue::fromArray([...])` and bound with
  `Charlotte::dataCatalogue()`: named streams with row fields and types,
  optional totals, enum params, and the scope parameters each accepts
  (`set`, `tree`, `period`). `cms_describe` returns it under `data`. A
  binding is fancy-cms's `{"$bind", "$source"?}` plus optional `$params` and
  `$scope`; paths are `<stream>.rows`, `<stream>.columns.<field>`,
  `<stream>.totals.<field>`, `<as>.<field>` inside a repeat, and
  `<stream>#<alias>` binds a stream twice. Every write, by any actor, is
  checked (`Data\BindingPolicy`, `binding_invalid` with the field's path and
  what exists); `cms_check` reports stale bindings as `invalid_binding`.
- **Scope only narrows.** `Data\Scope::narrow()` intersects a binding's (or
  a page input's) scope with the viewer's whole visible scope, which the host
  states; a parameter the viewer scope omits fails closed. Host render-time
  helpers: `Charlotte::dataQueries($doc, $viewer, $inputs, $descendants)` (one
  narrowed query per bound stream) and `Charlotte::dataContext($results)`
  (the `$data` context for fancy-cms's renderer, with `columns` derived).
- **Field-selecting params.** A stream param declared
  `['selects' => 'fields', 'always' => ['group']]` (at most one per stream)
  takes a literal list of the stream's field names; bindings to columns,
  totals or repeat fields outside the selection plus `always` are refused
  `binding_invalid` naming the selection, as is selecting an unknown field.
  `dataQueries` passes the selection in `params` and adds `fields`, what each
  query's rows must carry (every declared field without a selection).
- **Nullable field types**: `'number?'` or `['type' => 'number', 'nullable' => true]`,
  normalised to `number?`. Null is no value, never 0: `dataContext` (now given
  the catalogue) keeps every row and fills a missing declared field with null
  in its column; fancy-cms renders null as an empty cell or chart point, a
  skipped leaderboard row or a kpi's empty state. `cms_describe` tells the
  agent so, and to set `emptyText`.
- **Fact checks treat bindings as evidence**: a bound figure is never an
  `unsupported_claim`, nor are dates inside its `$scope`; a literal figure is.
- **Charlotte as a prism-harness sub-agent.** Mode `charlotte` (the page
  guide, the nine page tools, 40 steps, nothing that publishes). A host's
  agent declares it with `'subagents' => ['charlotte' => BuilderMode::subagent()]`
  and hands it a page with `Charlotte::delegate($user, $parentScope, $tenant,
  $page, $brief)`, which binds the child address to that page in host
  context; `CharlotteSession::bindAsSubagent()` re-binds before a later turn.
  The child's `cms_finish` returns the draft by reference,
  `{"draft": {"page", "revision"}}`. Its run is its own harness run, linked
  beneath the parent's thread. A child capability copied to another address
  or participant binds no tools.
- **fancy-cms's real island catalogue and nodes.** Charlotte reads the
  `{$schema, $defs, types}` catalogue (`resources/islands/catalogue.json`,
  `Agent::describe()['islands']`): the built-ins `chart`, `kpi`, `table`,
  `leaderboard` and `input` are bare names with `island: true` implied, and
  their props are validated with the catalogue's `$defs` (`PropsSchema` now
  resolves `$ref`). It is the default `IslandCatalogue`;
  `CmsDescribeIslands::withHost([...])` adds host islands, which stay
  `namespace:Name` with `island: true`. The pre-release guess at the shape
  (a map or list of `namespace:Name` entries) is gone. Unknown types,
  `richtext` and `island: true` on a native element are still refused.
  `IslandCatalogue` gained `defs()` and `builtin()`; `cms_describe` islands
  carry `defs`.
- **Page inputs.** `input` nodes are placed and configured like any built-in.
  A binding's `{"$input": name}` must name an input on the page whose kind
  fits (period/daterange for a period scope; person/select/multiselect for a
  set or tree scope; select for a param), and input names are unique, all as
  `binding_invalid`. A `repeater`'s children may bind `item.<field>` and
  `index`.
- **`dataQueries` delegates to fancy-cms's `DataRequests::queries()`**, so the
  two cannot drift: one request per distinct (stream, alias, params, scope),
  keyed `<stream>` or `<stream>#<alias>`, `{"$input"}` filled from the page's
  inputs, each kept verbatim under `request`, plus Charlotte's checked
  `params`, `fields` and narrowed `scope`. New arguments: `data` (for bound
  input options) and `periods` (a period preset as dates). Narrowing takes a
  person/select value as one id and an empty multiselect as no narrowing. A
  parity test runs against fancy-cms's own `tests/fixtures/data-queries.json`.
  Needs fancy-cms 0.9.0 (page inputs and data queries); see Changed.
- **One run tree per site turn.** The orchestrator's stream and every page
  sub-agent run on the turn's `RunContext` (the host's, or the harness
  default): prism-harness meters every parent and child call against it, a
  child gets min(40, the steps the tree has left), its steps and spend land in
  the tree, and a child resolves only the `charlotte` mode's nine tools. The
  `charlotte_page` tool is the harness's own declared sub-agent tool.
- Tool handlers pass every argument to the closed dispatch, so an undeclared
  one (`tenant`, `page`) is reported as `$.arguments.<field>: no such field`
  instead of failing inside the PHP call with a generic error.
- `draft.ready` from one page of a site carries `page`.
- **Finish gates** (`Checks\QualityChecks`, `Checks::FINISH_GATES`,
  `Checks::gates()`), in `cms_check` and enforced by `cms_finish`:
  - `layout_missing`: a `stack` or `grid` with no `layout`, or another
    container using `direction`, `gap`, `columns`, `columnMin`, `justify`,
    `align` or `wrap` with none. fancy-cms emits `display:flex`/`grid` only
    from `layout`, so those styles did nothing; this was 18 of the 23 lab pages.
    The message names the `set_layout` op to send.
  - `link_spacing`: two or more links or buttons side by side in a container
    that is not a stack or grid with a gap at base (nav and footer links that
    "run together").
  - `responsive_layout`: a content section with no `md` or `lg` style
    anywhere in it, or a page with no multi-column layout at `md` or at `lg`
    (a grid's columns, a `columnMin` grid judged at 704 and 960 px, or a row
    stack, counting only children that make a column: a row of buttons is not
    a layout). Header, footer and nav landmarks do not count; a site's regions
    and its 404 page are not held to it.
  - `required_image_missing`: an image the brief requires that no page
    places. Required = the brief's new `images` list, plus image files its text
    names (`/images/buns.jpg`) that the host catalogue has under that name. One
    the catalogue lacks is `required_image_unavailable`, a WARNING and never a
    gate: the agent cannot place what the host never supplied. On a site it is
    judged across all pages.
- **Brief `images`**: an optional list of asset handles the page must place.
- **Date claims.** `unsupported_claim` kind `date`: a day and month, or a
  month and year, the brief does not give (run 426 added "From 1 February
  2027"). Dates compare by meaning: "18 March 2027", "March 18, 2027", "18th
  of March" and "31 Jan" match their brief spellings.
- **Located defects.** Every finish-gate defect carries `node` (a node id,
  or the page handle `page:<doc id>` for page-level ones, with `field` for
  meta) and `rule` (the rule it breaks, `Checks::RULES`). A page-wide
  `responsive_layout` names the container to change.
- **The not_ready limit.** Charlotte counts consecutive `not_ready` results
  (`charlotte_sessions.finish_refusals`): per harness run for a page
  sub-agent (`finish_refusals_run`), so two successive children on the same
  page each get the full allowance, and session-wide for a top-level turn. At
  `charlotte.finish.max_not_ready` (default 3, minimum 1) `cms_finish` returns
  `not_ready_limit` with the remaining `defects`, `refusals`, `limit` and, for
  a page sub-agent, its `draft` reference; `cms_apply` and `site_nav_set` are
  refused `not_ready_limit` too, and a turn at the limit is not continued
  after an output-limit stop. The agent stops and hands the defects back
  instead of retrying. The count starts again on a successful finish, a new
  message, a page created or selected, or a delegation bound; a new child run
  always starts from zero.
- **Cache tokens in telemetry.** `usage.observed` carries `cacheReadTokens`
  and `cacheWriteTokens` from Prism's usage (null when the provider does not
  report them), so prompt caching (prism#29) shows when it starts working.
- **The responsive starter** (`Design\Starter`), in every builder mode's
  system prompt as the guide's closing "Starter" section (so it is in the
  guide hash and the cacheable prompt, and read as instructions rather than as
  tool-result data): a skeleton of ops (`header`, `page`, `footer`: header row with a
  wrapping nav, a hero on the brand tint with a `hero-actions` button row and
  a two-column layout at lg, a features grid of 1 then 3 from md, a CTA band
  in white on the primary colour, a footer row), a type scale (display, h2,
  h3, lead, body, small, eyebrow per breakpoint), a card style, `navLink` and
  `button` styles that clear the 44 px tap target, and `columns`, grid column
  counts by item count. All fancy-cms StyleProps, no CSS; fancy-cms's
  CssEmitter renders it as real flex and grid layouts (tested). The
  skeleton's nav is `site-nav`, so on a site `site_nav_set` fills it. (Runs
  504-512 showed the first skeleton's 2-column features grid at md was itself
  the 2+1 orphan every page shipped.)
- **Design gates** (`Checks\DesignChecks`, flabs runs 504-512), each locatable
  by `node` and `rule`: `grid_orphan` (a grid's columns at base, md or lg do
  not divide its visible items, or exceed them; names the breakpoint and the
  count to use), `hero_thin` (the section holding the h1 lacks supporting copy,
  or both a call to action and a visual), `tap_target` (a link or button under
  44 px tall at a breakpoint, computed from font size, line height, padding
  and fancy-cms's button defaults; it agrees with the 19-21 px the lab
  measured), `bare_list` (a list of 4 or more items). And a `hero_visual`
  WARNING, not a gate: a hero with no image, frame, shape or island (a lone
  icon does not count). Regions and the 404 page skip the hero checks.
- The guide teaches responsive composition (why `layout` matters, columns per
  breakpoint, no orphan cards, the type scale), the starter, what
  `cms_finish` refuses, and a brand character drawn from the brief (palette
  with a tint, type pairing, one signature motif, supplied images or a shape
  composition, section rhythm), with one worked example that the tests apply.

### Changed

- **Requires `particle-academy/prism-harness` `>=0.23.2 <2.0`** (was
  `>=0.17 <1.0`). 0.23.2 fixes G-261 (an uncapped stream dropped declared
  sub-agents) and G-262 (a shared tree did not count the parent's steps), and
  has no breaking changes. **Consumer action:** `composer update
  particle-academy/prism-harness particle-academy/charlotte`. Widening the
  upper bound only adds candidates.
- **Site turns need no special host context.** Charlotte's own `charlotte_page`
  tool and its cost-capped-only guard are gone: the harness offers its
  declared sub-agent tool in every stream and enforces the tree's steps and
  spend before every parent and child call. **What to do:** nothing.
- **Requires `particle-academy/fancy-cms` `>=0.9.0 <2.0`** (was `>=0.8.1 <2.0`).
  Charlotte now reads fancy-cms 0.9.0's island catalogue, `input` nodes and
  `DataRequests::queries()`, which 0.8.1 does not have. **Consumer action:**
  `composer update particle-academy/fancy-cms particle-academy/charlotte`. Read
  fancy-cms 0.9.0's own BREAKING note first: it matters only if your documents
  already use a node type named `chart`, `kpi`, `table`, `leaderboard` or
  `input` for something of your own.
- **`cms_finish` refuses with `not_ready`** while a finish gate fails, and
  `unsupported_claim` is an error for every kind but `quote` (see the BREAKING
  note above).
- `cms_finish` records its `checks.result` before deciding, so a refusal is on
  the event log too. A site page's own finish skips what the site judges across
  pages (required facts and images).

### Fixed

- A missing `meta_description` now says how to set it, and says so when the
  description was written to `meta.description` (which nothing reads) instead
  of `meta.seo.description`. Three lab pages (runs 427, 434, 453) did that.
- A CSS length an agent put in props (`{"value": 260, "unit": "px"}`) is no
  longer reported as an unsupported figure.

## [0.3.0] - 2026-10-30

Fixes from Charlotte's first paid lab run (flabs runs 146-149, 2026-10-30,
`claude-sonnet-5`, 4096 output tokens per call): all four builds ended without
a finished page. Regression tests replay the recorded evidence
(`tests/Feature/LabRunRegressionTest.php`, `tests/fixtures/lab-run-147-cms-apply.json`).

**BREAKING (behaviour, small):** a turn that keeps stopping at the output limit
before the page is ready now ends `run.failed` with code `output_limit` and
session status `failed`, where 0.2.0 recorded `run.finished` and went `idle`.
**What to do:** if your host treats only `idle` as "the agent stopped
normally", nothing; an `output_limit` failure was already an unfinished page.
If you branch on `run.failed` codes, add `output_limit`. Set
`charlotte.max_continuations` to `0` to fail on the first cut-off instead.

### Fixed
- **A response cut off at the output limit no longer ends the turn with
  nothing built.** When a run finishes with `length` before the page is ready
  (no `cms_finish` at the current revision) and nobody asked to stop, Charlotte
  continues the turn with a short host message
  (`TurnRunner::CONTINUATION`), up to `charlotte.max_continuations` times
  (default 3), on the SAME host run context, so a host's step and cost caps
  bound the whole turn. Each continuation is its own run: `run.finished`
  (`finishReason: "length"`), then `run.started` carrying the new field
  `continues` (the previous run's id). In run 146 the model spent 3,962 of its
  4,096 output tokens on adaptive thinking, emitted no tool call, and the
  session went idle unfinished; runs 148 and 149 ended the same way.
- **`cms_apply` (and every tool) argument errors now name the field.** An
  `invalid_request` error reads
  `{index: null, path: "$.arguments.<field>", expected, got, hint}` instead of
  a bare `$.arguments`. When `ops` arrives as a string that is not valid JSON,
  `got` says so and counts the unclosed or extra braces and brackets. In run
  147, 8 of 20 `cms_apply` calls sent `ops` as a string; every one was invalid
  JSON with one unclosed `{`, and with nothing naming it the model resent the
  same broken heading six more times.

### Changed
- `cms_apply` accepts `ops` sent as a string holding a VALID JSON array of ops.
  The decoded ops go through the same fancy-cms validation as an array. Strings
  that are not valid JSON are never repaired, because where a missing brace
  belongs is a guess. The `ops` parameter description now says "a JSON array
  of op objects (not a string)".

## [0.2.0] - 2026-10-30

**Nothing to migrate.** Both changes come from the first real consumer (the
fancy-labs website-builder lab). A host that binds nothing new runs exactly as
on 0.1.0; no public signature lost or changed a parameter.

### Added
- An optional per-turn run context for hosts that meter spend:
  `Charlotte::contextUsing(fn (Turn $turn): ?RunContext => ...)`, or bind a
  `Charlotte\Runtime\TurnContextFactory`. Each turn's prism-harness
  `RunContext` (budget, pricing, cost observer) can now come from the host
  without rebuilding `TurnDispatcher`, `TurnRunner` or `AgentRuntime`. The
  default, `Runtime\HarnessDefaults`, returns `null`, which keeps the harness's
  own configuration; billing stays optional. A factory that throws refuses the
  turn before any provider call, as `run.failed` with the exception's
  `HasErrorCode::code()` (else `run_failed`). See "Host budgets" in the README.
- `TurnRunner` takes the factory as an optional sixth constructor argument,
  defaulting to `HarnessDefaults`, so a host constructing it with five
  arguments keeps working.

### Fixed
- Optional usage telemetry no longer resolves host dependencies for
  generations that are not Charlotte's. Prism fires `GenerationCompleted` for
  every generation in the app, and the listener built a `Workspace` (and so the
  host's `PageRepository`, `AssetCatalogue` and `LinkPolicy` bindings) before
  checking `charlotte.telemetry.enabled` or the session key. A host binding
  scoped to a builder turn therefore threw into unrelated agent roles, even
  with Charlotte's telemetry off. The flag and the session are now checked
  first and the workspace is resolved lazily, only for Charlotte's own
  sessions; a failure resolving it is swallowed like any other telemetry
  failure. `UsageTelemetry`'s constructor now also accepts a
  `Closure(): Workspace` (a `Workspace` still works).

## [0.1.0] - 2026-10-27

**First release. Nothing to migrate:** install, publish the config, run the
migrations, and register an authorization hook and a tenant resolver. Without
a hook every route is refused, by design.

### Added
- Sessions on prism-harness in a `charlotte_builder` mode, with the provider
  and model taken from configuration (any Prism provider) and overridable per
  session. Turns run on the queue (`Charlotte\Laravel\RunTurn`), never inside
  an HTTP request, one at a time per session.
- Nine page tools over a fancy-cms PageSession, extracted from the flabs Sites
  lab with the same names and argument bounds: `cms_describe`, `cms_icons`,
  `cms_assets`, `cms_get`, `cms_apply`, `cms_changes_since`, `cms_finish`, plus
  the new `charlotte_brief` and `cms_check`.
- Drafts: Charlotte edits a draft only. `CharlotteSession::publish()` is the one
  way to publish, and no tool reaches it.
- Autonomy, a dev setting (`charlotte.autonomy`) overridable per session:
  `full` applies edits to the draft at once; `approve_on_save` collects them in
  a change set that a person approves (saved to the draft) or rejects. A change
  set that no longer applies resolves `conflicted` and writes nothing.
- A structured brief (`Brief::fromArray`) and code-derived checks:
  `missing_required_fact`, `unsupported_claim`, and the lab's PageChecks
  (heading outline, alt text, contrast, link targets, placeholders, meta),
  plus page facts for UIs and a host link allowlist.
- A versioned, overridable guide: fancy-cms's authoring guide plus
  `agent-guide/charlotte.md`. Overrides are hashed; each session records the
  guide hash, and a run whose builder mode does not embed the current guide is
  refused with `guide_mode_stale`.
- The event log (envelope v1, `resources/schema/events.v1.json`), written in
  the same transaction as each mutation, with gap-free per-session cursors and
  a long-poll reader. `docs/events.md` documents the contract.
- A framework-agnostic `Charlotte\Http\Api` and a thin optional Laravel
  controller kit (`Charlotte::routes()`), behind a dev-supplied authorization
  hook (default: deny all) and a host tenant resolver.
- Optional usage telemetry through Prism's own telemetry events: feature
  detected, off by default, `costUsd: null` when unpriced, never a refusal.
- Security: no `richtext` for any actor, host-catalogue image handles only,
  agent links allowlisted, error codes instead of provider messages.
- `docs/fetch-chat.html`, a complete chat on plain `fetch`.
