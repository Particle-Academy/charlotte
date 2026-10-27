# Laravel quick start

Charlotte runs inside your Laravel 13 app on prism-harness. You supply three
things: who may do what (an authorization hook), which tenant a request
belongs to, and a queue worker. Everything else has a working default.

## 1. Install

```bash
composer require particle-academy/charlotte
php artisan vendor:publish --tag=charlotte-config
php artisan migrate        # Charlotte's tables and prism-harness's
```

Configure a Prism provider as you normally would (`config/prism.php`, its API
key in `.env`). Any provider Prism supports works.

```dotenv
CHARLOTTE_PROVIDER=anthropic
CHARLOTTE_MODEL=claude-sonnet-5-5
CHARLOTTE_AUTONOMY=approve_on_save   # or full
```

## 2. Authorization and tenancy

Every route asks your hook. Until you register one, every route answers 403.

```php
// app/Providers/AppServiceProvider.php
use Charlotte\Facades\Charlotte;
use Illuminate\Http\Request;

public function boot(): void
{
    // Which tenant (team, site, account) this request acts for.
    Charlotte::resolveTenantUsing(fn (Request $request): ?string =>
        $request->user()?->current_team_id === null ? null : (string) $request->user()->current_team_id);

    // $ability: open | view | prompt | edit | approve | publish
    // $subject: ['tenant' => ..., 'page' => ..., 'session' => id|null]
    Charlotte::authorizeUsing(fn ($user, string $ability, array $subject): bool =>
        $user->can('charlotte.'.$ability, [$subject['tenant'], $subject['page']]));

    Charlotte::routes(); // prefix and middleware from config/charlotte.php
}
```

A single-tenant app can return a constant: `fn () => 'default'`.

## 3. A queue worker

The model loop never runs inside an HTTP request. `send()` records the
message and dispatches `Charlotte\Laravel\RunTurn`:

```bash
php artisan queue:work --tries=1
```

## 4. Images and links (optional)

Agents may only use images from your catalogue, by handle. They never see a
URL; your fancy-cms image resolver maps handles at render time.

```php
use Charlotte\Policy\ArrayAssetCatalogue;
use Charlotte\Policy\AssetCatalogue;

$this->app->singleton(AssetCatalogue::class, fn () => new ArrayAssetCatalogue([
    'hero-truck' => ['alt' => 'Our truck at the Saturday market', 'width' => 1600, 'height' => 1067],
]));
```

Agents may link to your own paths, anchors, `mailto:` and `tel:`. To allow an
external origin, list it in `charlotte.links.allow_origins`.

## 5. Open a session and talk to it

From your own controller:

```php
use Charlotte\Brief\Brief;
use Charlotte\Facades\Charlotte;

$session = Charlotte::for($request->user())
    ->tenant((string) $team->getKey())
    ->page('home')
    ->brief(Brief::fromArray([
        'summary' => 'Landing page for Acme Ledger, bookkeeping for food trucks.',
        'facts' => [
            ['id' => 'price-starter', 'kind' => 'price', 'label' => 'Starter plan', 'value' => '$19/month'],
            ['id' => 'contact', 'kind' => 'contact', 'value' => 'hello@example.com'],
        ],
        'cta' => ['label' => 'Start free', 'href' => '/signup'],
    ]))
    ->open();

$cursor = $session->send('Build the landing page from the brief.', (string) $request->user()->getKey());
```

Or over HTTP with the routes above: `POST /charlotte/sessions`, then
`POST /charlotte/sessions/{id}/messages`, then poll
`GET /charlotte/sessions/{id}/events?after=<cursor>&wait=20`. The complete
contract is in [events.md](events.md), and [fetch-chat.html](fetch-chat.html) is
a working chat on nothing but `fetch`.

## 6. Review, approve, publish

- Under `approve_on_save`, the agent's edits wait in a change set:
  `$session->approve($proposalId, $userId)` saves it to the draft,
  `$session->reject(...)` discards it.
- `cms_finish` marks the draft ready (`draft.ready`) and runs the checks
  (`checks.result`). It does not publish.
- Publishing is yours alone: `$session->publish($revision, $userId)` copies the
  draft at the revision the person reviewed. Render it with fancy-cms's
  `PageRenderer`, read from `PageRepository::published()`.

## 7. Optional usage telemetry

Off by default and never required. To see token usage (and cost, if you give
prices) as `usage.observed` events:

```dotenv
PRISM_TELEMETRY_ENABLED=true
CHARLOTTE_TELEMETRY=true
```

A model without a price reports `costUsd: null`. Nothing is ever refused for
lack of a price.

## Testing your integration

Register a custom Prism provider with `PrismManager::extend()` that plays a
script, as this package's own suite does (`tests/Support/ScriptedProvider.php`),
and run with the `sync` queue. No network and no key are needed.
