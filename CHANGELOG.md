# Changelog

Notable changes to `particle-academy/charlotte`.

**BREAKING** marks anything that can stop working on upgrade. This package is
pre-1.0, so breaking changes land in MINOR releases; read those entries before
upgrading.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [Unreleased]

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
