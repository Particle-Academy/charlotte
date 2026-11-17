# Using particle-academy/charlotte — guide for agents

How an agent installs and uses this package in a consumer app. What the package
is, is in [README.md](./README.md). Notes for working ON this repo's code are in
[CONTRIBUTING.md](./CONTRIBUTING.md).

## Install

```bash
composer require particle-academy/charlotte
php artisan vendor:publish --tag=charlotte-config
php artisan migrate        # Charlotte's tables and prism-harness's
```

```dotenv
CHARLOTTE_PROVIDER=anthropic
CHARLOTTE_MODEL=claude-sonnet-5-5
CHARLOTTE_AUTONOMY=approve_on_save   # or full
```

- **Requires:** PHP 8.4, Laravel 13, a queue worker, and a Prism provider
  configured as usual (`config/prism.php`, its key in `.env`).
  `particle-academy/prism-harness` (0.23.2 or later) and
  `particle-academy/fancy-cms` install with Charlotte.
- Full setup: [docs/laravel-quickstart.md](docs/laravel-quickstart.md).

## Find it on the Fancy MCP server

The catalog server is `https://ui.particle.academy/mcp` (streamable HTTP, no API
key; MCP id `fancy-ui`). Its registry slug is `charlotte`: use
`install-instructions` / `get-component` with that slug, or find it by
capability with `search-backend-packages` (stack `php`).

## API essentials

- **Wire it once**, in a service provider: `Charlotte::resolveTenantUsing(...)`,
  `Charlotte::authorizeUsing(fn ($user, string $ability, array $subject): bool => ...)`
  (abilities `open | view | prompt | edit | approve | publish`), then
  `Charlotte::routes()`.
- **Open a session:** `Charlotte::for($user)->tenant($id)->page('home')`
  (or `->site('marketing')`) `->brief(Brief::fromArray([...]))->autonomy('approve_on_save')->open()`.
  `$session->send($message, $actorId)` returns a cursor; a queue worker runs the
  turn.
- **Read progress** from the event log: `GET /charlotte/sessions/{id}/events?after=<cursor>&wait=20`
  ([docs/events.md](docs/events.md)). A plain `fetch` loop is enough
  ([docs/fetch-chat.html](docs/fetch-chat.html)).
- **Publish is host code:** `$session->publish($revision, $by)`.
- **As a sub-agent of your own prism-harness agent:** declare
  `'subagents' => ['charlotte' => \Charlotte\Guide\BuilderMode::subagent()]` in
  your mode, then bind the page each parent turn with `Charlotte::delegate(...)`.
- **Optional hooks:** `Charlotte::contextUsing()` (your own `RunContext` with a
  budget per turn), `Charlotte::renderedUsing()` (contrast measured on the
  rendered page), a host `IslandCatalogue` for your own `namespace:Name`
  component nodes.

## Traps

- **Every route answers 403 until you register an authorization hook.**
- **Charlotte edits drafts only.** Nothing it does publishes, and it never says
  a page is live.
- **Tenant, page, actor and brief come from host context**, never from a tool
  argument or the parent agent's task text. An undeclared argument is refused.
- **No `richtext` nodes** (the renderer injects richtext HTML as is). Images
  are `AssetCatalogue` handles only; authored `src`, sizes and CSS `url()` are
  refused.
- **Links** only to your own paths, anchors, `mailto:`, `tel:` or origins you
  allowlist. For a site session, list the routes your app serves itself in
  `charlotte.site.reserved_routes`; the agent is refused those routes.
- **`cms_finish` refuses `not_ready`** with every blocking defect; after
  `charlotte.finish.max_not_ready` refusals in a row (3) the agent stops and
  hands the defects back.
- **Re-bind before each later parent turn** when used as a sub-agent
  (`$page->bindAsSubagent($parent->scope())`): the binding lives in harness
  session state.
- **Events and API errors carry codes**, never provider messages.
