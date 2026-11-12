# Changelog

Notable changes to `particle-academy/charlotte`.

**BREAKING** marks anything that can stop working on upgrade. This package is
pre-1.0, so breaking changes land in MINOR releases; read those entries before
upgrading.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [Unreleased]

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
`unsupported_claim` (kind `figure`) unless the brief carries it. It is a
warning, as every claim is.

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
- **One run tree per site turn.** The orchestrator's stream and every page
  sub-agent share one `RunContext`: the host's, or (with no host context) the
  same root the harness would have built, held by Charlotte. A child's spend
  lands in that tree and a child past the tree's cap is refused `exhausted`.
  Until prism-harness G-261, Charlotte offers `charlotte_page` itself through
  the harness's `SubagentRunner` with that context; the child resolves only
  the `charlotte` mode's nine tools.
- Tool handlers pass every argument to the closed dispatch, so an undeclared
  one (`tenant`, `page`) is reported as `$.arguments.<field>: no such field`
  instead of failing inside the PHP call with a generic error.
- `draft.ready` from one page of a site carries `page`.

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
