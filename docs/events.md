# Event contract (envelope v1)

Every Charlotte session has an **ordered, durable event log**. The server
writes to it; every transport only delivers it. A client renders a session by
reading the log from a cursor, so any chat UI (React, Blade, a CLI, another
agent) can be built on the same contract.

The JSON Schema is `resources/schema/events.v1.json`. One fixture covering
every type, `tests/fixtures/events.v1.json`, is the reference a client suite
can test its reducer against.

## The envelope

```json
{
  "v": 1,
  "id": "01JCFZ8Q4M3V9T2B7K5N6P0R1S",
  "seq": 412,
  "session": "01JCFZ7Y2W8D4H6J0K9M3N5P7Q",
  "at": "2026-10-27T12:00:00.000Z",
  "type": "doc.changed",
  "actor": { "kind": "agent", "id": "charlotte" },
  "data": { "revision": "r…", "previousRevision": "r…", "opCount": 2, "ops": [] }
}
```

| Field | Meaning |
|---|---|
| `v` | Envelope version. A client that gets a version it does not know must stop, not guess. |
| `id` | A ULID, unique across sessions. |
| `seq` | Per-session cursor: starts at 1, gap-free, monotonic. Resume with `after=<seq>`. |
| `session` | The session id. |
| `at` | UTC, ISO 8601 with milliseconds. |
| `type` | See the table below. |
| `actor` | `{kind: agent\|human\|system, id}`. A person's id is the host user's key. |
| `data` | Always a JSON object (`{}` when empty). |

### Compatibility rules

- **Ignore a `type` you do not know.** New types arrive in minor releases.
- New fields inside `data` arrive in minor releases; ignore what you do not use.
- Removing a type or field, or changing what one means, bumps `v`.

## Types

| type | actor | data |
|---|---|---|
| `session.status` | system | `status`: `idle`, `queued`, `running`, `stopping`, `stopped`, `failed` |
| `message.created` | human | `messageId`, `role: "user"`, `text` |
| `run.started` | system | `runId`, `mode`, `provider`, `model`; `continues` (the previous run's id) when the turn continues after a run stopped at the output limit |
| `message.delta` | agent | `messageId`, `role: "assistant"`, `text` (a chunk). Coalesced to at most one per 250 ms plus a flush at the end, so append them in order |
| `message.completed` | agent | `messageId`, `role`, `text` (the whole message; replace the deltas with it) |
| `tool.call` | agent | `callId`, `tool`, `args` (JSON text, at most 1200 bytes), `truncated` |
| `tool.result` | agent | `callId`, `tool`, `ok`, `code` (null when ok), `revision`, `summary` (at most 1200 bytes), `truncated` |
| `doc.changed` | agent or human | `revision`, `previousRevision`, `opCount`, `ops` (only when at most 64 KiB; otherwise refetch state). On an approved change set, also `proposalId`, `approvedBy` |
| `proposal.created` | agent | `proposalId`, `baseRevision`, `revision`, `opCount`, `ops` (this batch) |
| `proposal.updated` | agent | `proposalId`, `revision`, `opCount` (running total), `ops` (this batch) |
| `proposal.resolved` | human | `proposalId`, `state`: `accepted`, `rejected` or `conflicted`; `revision` (the draft after) |
| `checks.result` | system | `revision`, `summary {errors, warnings, info}`, `defects[]`, `facts {outline, images, links}` |
| `draft.ready` | agent | `revision`, `staged` (true when the revision is a pending change set) |
| `draft.published` | human | `revision` |
| `usage.observed` | system | `provider`, `model`, `inputTokens`, `outputTokens`, `cacheReadTokens` and `cacheWriteTokens` (prompt-cache tokens; null when the provider does not report them), `costUsd` (null when unpriced), `priced`. Only when the dev enabled telemetry |
| `run.finished` | system | `runId`, `finishReason` |
| `run.failed` | system | `runId` (may be null), `code`. Never a provider message |
| `error` | system | `code`, `message` (a fixed, safe sentence). Reserved: 0.1 does not emit it yet, but clients should render it |
| `site.changed` | agent | Site sessions only. `site`, `version` (the structure version after), `change`: `page_created` (with `kind`, `route`), `page_routed` (with `route`) or `page_selected`; `page` (the page id, or `@header` / `@footer`) |

**Site sessions** add `page` (the document key, `<site>/<id>`) to `doc.changed`,
`proposal.created`, `proposal.updated` and `proposal.resolved`, because one
session edits several documents. On a site, `checks.result` from `cms_check`
also carries `page` and `site {revision, defects}`, and every defect a `page`;
`cms_finish` records one `checks.result` for the whole site (`site`,
`revision` = the site revision, `contrast` per document), then `draft.ready`
with `site`. A page sub-agent's `cms_finish` on one page of a site records
`draft.ready` with `page` (the document key) and leaves the site's ready
revision alone. `draft.published` from a site publish carries `site` and
`documents`. Page sessions are unchanged.

Every `checks.result` carries `contrast`: `{source: "rendered", renderedBy,
revision}` when a rendered report for the checked revision was supplied
(contrast then comes from the browser, islands included), or `{source:
"document", reason}` with `reason` one of `no_rendered_report`,
`rendered_report_stale` (with `renderedRevision`) or `rendered_report_failed`.

Failure codes include `run_failed`, `provider_error`, `guide_mode_stale`,
`output_limit` (the turn kept stopping at the output-token limit before the
page was ready, past `charlotte.max_continuations`), and any `HasErrorCode`
code prism-harness raises.

One turn may hold several runs. When a run finishes with `length` before the
page is ready, it gets its own `run.finished` and the turn continues with a
new `run.started` whose `continues` names that run. The session stays
`running` throughout. Tool error codes include
`surface_changed_under_you`, `invalid_request`, `invalid_operation`,
`image_source_refused`, `link_refused`, `island_invalid` (with `errors`),
`not_ready` and `not_ready_limit` (with `defects`), `layout_required` (with
`errors`) and `stopped`; on a site also `route_reserved`, `route_invalid`, `route_taken`,
`site_page_missing`, `site_page_exists`, `site_page_unrouted`,
`site_not_found_exists` and `site_full`.

## Defects in `checks.result`

All code-derived; no model is involved.

| code | severity | from |
|---|---|---|
| `h1_count`, `heading_skip`, `image_alt`, `link_target`, `anchor_target`, `placeholder_copy`, `contrast`, `meta_title`, `meta_description`, `document` | error | PageChecks |
| `contrast_unchecked` | info | PageChecks: contrast could not be computed, which is never read as a pass |
| `missing_required_fact` (with `fact`) | error | FactChecks |
| `unsupported_claim` (with `kind`, `claim`, `node` or `field`) | error (warning for `kind: quote`) | FactChecks: a price, percentage, count, phone, email, date, year, typed figure or quote no brief fact supports |
| `layout_missing`, `link_spacing`, `responsive_layout` (with `breakpoint` when page-wide), `required_image_missing` (with `asset`) | error | QualityChecks |
| `required_image_unavailable` (with `image`) | warning | QualityChecks: the brief names an image the host catalogue does not have |
| `link_not_allowlisted` | warning | an external link outside the host's allowlist |
| `contrast` with `source: "rendered"` (and `target`, `node`?, `island`?, `ratio`?, `needed`?) | error | a rendered report: the browser measured this element below the minimum |
| `site_link_broken`, `site_anchor_broken`, `site_orphan_page`, `site_page_unrouted`, `site_missing_home`, `site_missing_404`, `site_nav_missing`, `site_region_h1` | error | SiteChecks (site sessions) |
| `site_404_no_home_link`, `site_nav_incomplete`, `site_nav_duplicate`, `site_nav_in_page`, `site_region_anchor`, `site_title_duplicate` | warning | SiteChecks (site sessions) |

On a site, `missing_required_fact` and the required images are judged across
every page (they may live on any of them), a shared region is not held to
`h1_count`, `meta_title`, `meta_description`, `anchor_target` or
`responsive_layout`, and the 404 page is not held to `responsive_layout`.

`cms_finish` refuses with tool error `not_ready` (carrying `defects`) while any
`layout_missing`, `link_spacing`, `responsive_layout`, `required_image_missing`,
`meta_description` or `unsupported_claim` error remains (`Checks::FINISH_GATES`).
It still records its `checks.result`, and emits no `draft.ready`. Every such
defect carries `node` (a node id, or `page:<doc id>` for a page-level one) and
`rule`. The error also carries `refusals` and `limit`; at the limit
(`charlotte.finish.max_not_ready`, default 3) the code is `not_ready_limit`
(with `draft {page, revision}` for a page sub-agent), and `cms_apply` and
`site_nav_set` are refused `not_ready_limit` until new work starts.

## Delivery

**Long-poll is the baseline and is always on:**

```
GET /charlotte/sessions/{id}/events?after=<seq>&wait=<seconds>
-> 200 {"events": [ ... ], "cursor": <seq>}
```

- Answers at once when events follow the cursor; otherwise waits up to `wait`
  seconds (capped by config, never above 25) for one.
- Send the returned `cursor` as the next `after`. A dropped response loses
  nothing: the next request reads the same events again.
- Each request is short, so it survives Cloudflare, which resets only
  long-lived streams.

Socket push and SSE are planned accelerators over the same log; neither is
the source of truth.

## The rest of the HTTP contract

All routes sit under the configured prefix (default `/charlotte`) and the
configured middleware. Every route resolves the tenant through the host, finds
the session **in that tenant** (another tenant's session is a 404), then asks
the host's authorization hook.

| Route | Ability | Answer |
|---|---|---|
| `POST /sessions {page \| site, brief, autonomy?}` | `open` | `201 {session, cursor}`. With `site` the authorization subject also carries `site` |
| `GET /sessions/{s}` | `view` | `200 {session, doc, revision, readyRevision, proposal, published, cursor}`; a site session adds `site` (structure and site revision) and its `doc` is the selected document |
| `GET /sessions/{s}/events?after=&wait=` | `view` | `200 {events, cursor}` |
| `POST /sessions/{s}/messages {text}` | `prompt` | `202 {cursor}`; `409 turn_in_progress` |
| `POST /sessions/{s}/stop` | `prompt` | `202 {status}` |
| `POST /sessions/{s}/ops {ops, basedOn}` | `edit` | `200 {revision}`; `409 {error, snapshot: {doc, revision}}` on a stale revision |
| `POST /sessions/{s}/proposals/{p}/accept` | `approve` | `200 {state, revision}`; `409 proposal_resolved` |
| `POST /sessions/{s}/proposals/{p}/reject` | `approve` | `200 {state, revision}` |
| `POST /sessions/{s}/publish {basedOn}` | `publish` | `200 {revision, published}`; `409` with a snapshot when stale. On a site session `basedOn` is the site revision and every page and region publishes together |

Errors are `{"error": {"code": "...", "message"?: "..."}}`. Status codes: 403
(`forbidden`, `tenant_unresolved`), 404 (`not_found`, `proposal_missing`), 409,
413 (`payload_too_large`), 422 (`invalid_request`, `invalid_brief` with `path`,
`invalid_operation` with `errors`, `image_source_refused`, `link_refused`).
