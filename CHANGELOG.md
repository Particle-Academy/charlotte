# Changelog

Notable changes to `particle-academy/charlotte`.

**BREAKING** marks anything that can stop working on upgrade. This package is
pre-1.0, so breaking changes land in MINOR releases; read those entries before
upgrading.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [Unreleased]

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
