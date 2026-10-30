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
  `Runtime\StreamRecorder` (Prism stream events -> log events).
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

## Invariants a change must keep

- **Nothing publishes except `Workspace::publish()`**, reached only from
  `CharlotteSession::publish()` and the `publish` route. No tool name may
  contain `publish`; `DraftsTest` checks.
- **Node types exclude `richtext` for every actor** (`Drafts\NodeTypes`).
  Every PageSession here must be built with `NodeTypes::allowed()`.
- **`AssetPolicy::assert` runs after every write** (agent, human, approval
  replay, publish). It refuses authored image URLs, dimensions and any CSS
  fetch, escape or comment.
- **`LinkPolicy::assertOps` runs on agent ops only**, before the write.
- **Events are appended inside the mutation's transaction.** A rolled-back
  write must leave no event. `DatabaseEventLog::append` locks the session row
  and the unique (session_id, seq) index is the backstop for gap-free seq.
- **No exception message reaches the log or an API body.** `run.failed`
  carries a code (`Refused::reason`, `HasErrorCode::code()`, else
  `run_failed`); API errors use `Refused::MESSAGES` only.
- **Tools bind to a session through harness capability state**, written only
  by `TurnRunner`, and the provider in `CharlotteServiceProvider::boot()`
  also requires the harness scope to be `charlotte:<id>`.
- **Telemetry is optional.** `Telemetry\UsageTelemetry` is the only reader of
  Prism telemetry, swallows its own failures, and nothing reads
  `usage.observed`. Never make a turn depend on it. Prism fires its event for
  EVERY generation in the host: check the flags and the session key before
  resolving anything from the container (`TelemetryLazyTest`).
- **Billing is optional.** Nothing may require a `TurnContextFactory`; with
  none bound a turn passes `context: null` and runs on harness config.
- **Event types**: adding one means updating `Events\EventTypes::ALL`,
  `resources/schema/events.v1.json`, `tests/fixtures/events.v1.json` and
  `docs/events.md`; `EventContractTest` fails until all agree.

## approve_on_save mechanics

One open change set per session (`charlotte_proposals`). Its `working_json` is
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
