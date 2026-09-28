# Laravel Scarlett Player

The server side of [Scarlett Player](https://www.npmjs.com/org/scarlett-player) for Laravel.
One package, modules toggled in config:

- **Beacons:** receives the analytics plugin's beacons, on both transports, and aggregates them into views.
- **Clips:** accepts clip requests from the clips plugin and renders them on a queue.
- **Player and embed:** builds the player configuration for a host model, ships a Blade component, and serves the embed page and oEmbed endpoint.

All three share one media contract: the host tells the package, once, what a player `mediaId` means.

> Status: in development. The scaffold, config, facade, fake and `scarlett:doctor` are in place; the module sections below fill in as each module lands.

## Requirements

- PHP 8.3, 8.4 or 8.5
- Laravel 12 or 13

## Install

```bash
composer require hei/laravel-scarlett-player
php artisan vendor:publish --tag=scarlett-config
```

The service provider and the `ScarlettPlayer` facade alias are auto-discovered.

Set the beacon key the analytics plugin sends:

```dotenv
SCARLETT_BEACON_KEY=
```

Then check the installation:

```bash
php artisan scarlett:doctor
```

### Upgrading from 0.1

- **Server-side context columns.** A `scarlett_views` table migrated by 0.1.0 needs two
  nullable json columns, `server` and `server_stamps`, before you set `beacons.context`
  (see [Server-side context](#server-side-context); the `beacon context` doctor check fails
  until they exist). Without a resolver nothing reads or writes them, so a host that does
  not use the hook needs no migration.
- **`scarlett:clips:reconcile` can exit non-zero.** A clip visibility write that fails (a
  `false` from a disk configured `throw => false`, or a disk that throws) is now reported and
  fails the run instead of counting as re-synced. A scheduler or monitor that alerts on
  failed commands will see it; the clip stays in the pass's window and is retried on the next
  run.
- **One new event key for redacted beacons.** The raw log's `event_key` now hashes the
  beacon as the browser sent it. A beacon that a `beacons.pipeline` step redacts therefore
  gets a new key once across the upgrade: a v0.1.0 delivery and its v0.2.0 retry are stored
  as two raw rows. Beacons no step changes keep their v0.1.0 key.

### Publish tags

| Tag | What |
|---|---|
| `scarlett-config` | `config/scarlett-player.php` |
| `scarlett-migrations` | The package migrations |
| `scarlett-views` | The embed page and Blade component views, to `resources/views/vendor/scarlett` |
| `scarlett-js` | The JS initialiser, to `resources/js/vendor/scarlett-player/init.js` |

### Routes

Each module's routes load only when its switch is on, and each has its own middleware list,
so a host overrides one without touching the others:

```php
'routes' => [
    'prefix' => env('SCARLETT_ROUTE_PREFIX', 'api/scarlett'),
    'middleware' => [
        'beacons' => ['api', 'throttle:scarlett-beacons'],
        'clips' => ['web', 'auth', 'throttle:scarlett-clips'],
        'embed' => ['web'],
        'oembed' => ['api'],
    ],
    'beacons' => true,
    'clips' => true,
    'embed' => true,   // the embed page and the oEmbed endpoint
],
```

Route names are `scarlett.<module>.<action>`. The embed page lives at `embed.route`
(`/v/{uuid}` by default), outside the prefix. The `scarlett-beacons` and `scarlett-clips`
rate limiters read `beacons.throttle` and `clips.throttle` (`"attempts,minutes"`).

## Media contract

Every module asks the same question: what is this `mediaId`? The answer is a
`Hei\ScarlettPlayer\Data\MediaSource`, and the host supplies it one of three ways.

1. **The model implements `ScarlettMedia`** (preferred). Add the `HasScarlettClips` trait
   for the clip relation and a `scarlettMediaId()` default, and write one method:

   ```php
   use Hei\ScarlettPlayer\Concerns\HasScarlettClips;
   use Hei\ScarlettPlayer\Contracts\ScarlettMedia;
   use Hei\ScarlettPlayer\Data\MediaSource;

   class Video extends Model implements ScarlettMedia
   {
       use HasScarlettClips;

       public function toScarlettMediaSource(): MediaSource
       {
           return new MediaSource(
               id: $this->uuid,
               playbackUrl: $this->hls_url,
               isLive: $this->is_live,
               isProtected: $this->is_ppv,
               duration: $this->duration_seconds,
               title: $this->title,
               poster: $this->poster_url,
               model: $this,
           );
       }
   }
   ```

   Point `media.model` at the class and `media.key` at the column the player's `mediaId`
   holds.

2. **A complete attribute map in config**, for hosts that cannot touch the model:

   ```php
   'media' => [
       'model' => App\Models\Video::class,
       'key' => 'uuid',
       'attributes' => [
           'playback_url' => 'hls_url',       // required
           'is_live' => 'is_live',            // required
           'is_protected' => 'is_ppv',        // required
           'duration' => 'duration_seconds',
           'source_disk' => 'disk:mezzanine', // a column, or a literal disk name
           'source_path' => 'mezzanine_path',
           'title' => 'title',
           'poster' => 'poster_url',
       ],
   ],
   ```

3. **Your own resolver** for anything non-trivial (signed URLs, entitlement, multi-tenant):
   a class implementing `Hei\ScarlettPlayer\Contracts\ResolvesMedia`, named in
   `media.resolver`. Return `null` for an unknown id.

`isLive` and `isProtected` have no defaults, and **protection fails closed**: a mapping
without `is_protected` throws `IncompleteMediaMappingException` rather than assuming the
media is free. `scarlett:doctor` reports an incomplete mapping at install time.

How the default resolver (`Hei\ScarlettPlayer\Media\ConfigModelResolver`) behaves:

- It loads `media.model` where `media.key` equals the `mediaId`. A null `media.key` uses
  the model's route key. No matching record returns `null`, which the package answers
  with a 404.
- A model implementing `ScarlettMedia` always resolves through `toScarlettMediaSource()`;
  any attribute map is then ignored.
- The mapping is checked before the lookup, so an incomplete map throws even for an
  unknown id. The exception's `missing` property lists the config keys to fill in.
- Values fail closed as well as mappings: if a record's `is_protected` or `is_live` column
  is null or not a readable boolean (`1`, `0`, `true`, `false`, `yes`, `no`, `on`, `off`),
  or its `playback_url` column is empty, it throws rather than guessing.
- `source_disk` prefixed with `disk:` is a literal disk name; anything else is a column.
- `HasScarlettClips::scarlettMediaId()` returns the model's route key. Override it (or
  `getRouteKeyName()`) when the player is given a different id.

Resolve a media id yourself through the facade:

```php
use Hei\ScarlettPlayer\Facades\ScarlettPlayer;

$source = ScarlettPlayer::resolve($mediaId);   // throws MediaNotFoundException for an unknown id
$builder = ScarlettPlayer::for($video);        // a model or a mediaId
```

## Beacons

The analytics ingest for `@scarlett-player/analytics`: one route, `POST {prefix}/beacons`
(`scarlett.beacons.store`), that answers `204` before any aggregation and queues a
`ProcessBeacon` job on `beacons.connection` / `beacons.queue`. The player ignores the
response, so a slow or failing store costs the host, never the viewer;
`scarlett:beacon:test` and `scarlett:doctor` are how you find out.

### Wiring the player

```js
createAnalyticsPlugin({
  beaconUrl: 'https://app.example.com/api/scarlett/beacons', // must be https
  apiKey: '...',                                              // SCARLETT_BEACON_KEY
  videoId: 'the mediaId',
  heartbeatInterval: 10000,                                   // optional, ms; player.heartbeat_interval (seconds) sets it
  customDimensions: { plan: 'ppv' },                          // optional, stored in custom
});
```

The plugin sends its key only when `beaconUrl` is `https:`; there is no localhost
exception. On fetch (every in-session beacon) it sends `X-API-Key`. On page unload it
uses `navigator.sendBeacon`, which cannot carry a header, so it appends `?api_key=`
instead. The route accepts either, compared with `hash_equals`, and refuses everything
while `beacons.key` is unset.

**The key lands in access logs.** `?api_key=` is part of the URL, so nginx, a load
balancer or any proxy in front of the app records it. It is a low-privilege ingest key
that can only post beacons; never reuse a real API token for it. To keep it out of nginx
logs, log `$uri` rather than `$request` for this location:

```nginx
log_format scarlett '$remote_addr [$time_local] "$request_method $uri" $status';
location /api/scarlett/beacons { access_log /var/log/nginx/access.log scarlett; }
```

Do the same wherever else a full URL is recorded: turn off query-string logging for the
beacon path at a load balancer or CDN, and add `api_key` to the scrubbed parameters of any
error tracker or request inspector (Sentry, Telescope, Flare) that captures request URLs.
If the key does leak, rotate it: set a new `SCARLETT_BEACON_KEY` and ship the same value in
the player's `apiKey`.

The route is stateless by necessity: the unload beacon cannot carry a CSRF token either,
so it runs in the `api` group with the `scarlett-beacons` rate limiter
(`beacons.throttle`, default `600,1` per IP; heartbeats are frequent).

### CORS: the complete recipe

An embed iframe on `embed.example.com` beaconing to `app.example.com` is cross-origin. A
`Blob` of type `application/json` is not a CORS-safelisted content type, so both transports
preflight. The two transports then diverge:

| Transport | Credentials mode | Response the browser requires |
|---|---|---|
| `fetch` (in-session, and the unload fallback) | `same-origin` (default) | `Access-Control-Allow-Origin: *` is enough |
| `navigator.sendBeacon` (unload) | `include` | `Access-Control-Allow-Origin: <exact origin>` **and** `Access-Control-Allow-Credentials: true`; a `*` origin fails the request with no error visible anywhere |

So an ingest that "works" in the network tab can still be losing every unload beacon, the
one carrying the session totals. The package does not write `config/cors.php` for you; it
is host-owned config. Use this block:

```php
'paths' => ['api/scarlett/beacons'],          // the configured prefix + /beacons
'allowed_methods' => ['POST', 'OPTIONS'],
'allowed_origins' => ['https://embed.example.com'],   // explicit; never '*' when supports_credentials is true
'allowed_origins_patterns' => [],
'allowed_headers' => ['Content-Type', 'X-API-Key'],
'exposed_headers' => [],
'max_age' => 600,
'supports_credentials' => true,
```

- **Preflight is unauthenticated.** Laravel's `HandleCors` answers `OPTIONS` as global
  middleware, before the route. `ScarlettApiKey` also lets `OPTIONS` through, so a host
  that reorders middleware still cannot 401 a preflight.
- `scarlett:doctor` warns when beacons are enabled and the beacon path is absent from
  `cors.paths`, `supports_credentials` is false, `allowed_origins` contains `*` alongside
  `supports_credentials`, or `X-API-Key` is missing from `allowed_headers`.
- `scarlett:beacon:test` cannot prove any of this; only a browser enforces CORS. The
  package's browser test does: the real plugin, cross-origin over HTTPS, receives the
  unload `viewEnd` with the recipe and loses it with `supports_credentials` false.
- **Even with the recipe, Chromium can lose an unload beacon.** Because the unload
  beacon preflights, a same-site navigation that tears the page down while the
  preflight is in flight can make Chromium drop the POST after a successful preflight
  (about 1 in 3 in the package's headless Chromium 153 runs; much rarer cross-site).
  The ingest treats a missing unload `viewEnd` as normal (the heartbeats carry the
  running totals), but session totals from same-site navigations are best effort.

### What is accepted

One JSON object per request (a batched array is refused by name). The only things that
can refuse a beacon are the `event`, the four ids (`viewId`, `sessionId`, `viewerId`,
`videoId`) and the `timestamp` being missing or malformed (`422`), or the body exceeding
`beacons.max_body_bytes` (`413`, default 64 KiB). Everything else is accepted:

- **Unknown keys are stored, never rejected**, in the view's `custom` column. A newer
  player's keys and your `customDimensions` both land there.
- A known key carrying another type is treated as a custom dimension of that name (the
  player spreads `customDimensions` among its own keys, so a dimension called
  `duration` arrives under a known name).
- A key sent as `null` is treated as absent. Long strings are truncated to their column.

### What is stored

`EloquentBeaconStore` (the default, `beacons.store = eloquent`) keeps three tables.
Publish and run the migrations:

```bash
php artisan vendor:publish --tag=scarlett-migrations
php artisan migrate
```

| Table | Rows | Pruned by |
|---|---|---|
| `scarlett_views` | one per `viewId`, merged from every beacon of that view | `retention.views` days since its last update |
| `scarlett_beacon_events` | every beacon as received, while `store_raw_events` is on (about 90% heartbeats), except the event names in `raw_events_except` | `retention.events` days since received |
| `scarlett_view_errors` | one per `error` beacon, with its `video_id` | with its view |

Beacons arrive duplicated and out of order by design (keepalive fetch and `sendBeacon`
guarantee no order, a bfcache restore re-sends, a queue redelivers), so the view row is
merged per field, never per event:

| Class | Columns | Rule |
|---|---|---|
| Set-once | identity and environment except `is_live`, `started_at`, `first_frame_at`, `ended_at`, `exit_type` | the first beacon to arrive with a value keeps it; a second `viewEnd` cannot move `ended_at` |
| True wins | `is_live` | any beacon with `isLive: true` sets it and nothing clears it; `false` only fills an empty column. A live `viewStart` fires before the player has read the playlist and says `false` (from player 1.18 it sends `null`, treated as absent), and a live stream that becomes a replay in the same session stays live |
| Monotonic | `watch_ms`, `play_ms`, `rebuffer_ms`, `rebuffer_count`, `seek_count`, `pause_count`, `quality_changes`, `error_count`, `max_bitrate`, `startup_ms` | the larger value wins, whatever the order |
| Latest by timestamp | `qoe_score`, `avg_bitrate`, `rebuffer_ratio`, `completion_rate`, `current_position`, the live latency summary | written when the beacon is at least as new as the one that wrote that column (a `*_at` stamp per column); `metrics_at` is the newest |
| Fill if absent | every column | a key absent from the beacon never touches its column: the unload `viewEnd` has no `qoeScore`, so the last heartbeat's stays |

Two `viewEnd` beacons for one view (the player can send both) merge: the ended variant
fills what the unload variant lacked. Counters are never incremented from the event
stream; they are the player's own running totals. `custom` merges key by key, with a
stamp per key (`custom_stamps`): each key keeps the value from the newest beacon that
sent that key, so an older beacon delivered late still wins a key no newer beacon
wrote. A nested value is replaced whole, the same on every engine (the player itself
sends only flat scalars); `custom_at` is the newest stamp.

The same beacon delivered twice has the effect of once: raw events and errors are unique
on an `event_key`, the view row's rules are idempotent, and the transition events fire on
observed transitions only. The merge is three statements in one transaction (an
insert-or-ignore that decides `ViewStarted`, a guarded `ended_at` update that decides
`ViewEnded`, and one conditional update for the merge classes), each atomic on its row and
identical on SQLite, MySQL and Postgres. The view is linked to your model
(`viewable_type`, `viewable_id`) through `ResolvesMedia` after the transaction commits,
best effort; a beacons-only install with no `media.model` is never asked.
`viewable_id` is a string column so integer and uuid keys both fit; the package defines no
relation on it. A `morphMany` from your model to `scarlett_views` must use a string local
key on Postgres, which refuses to compare `varchar` with `integer`.

### Server-side context

Everything in a beacon comes from the browser, and anything the package does not know
lands in `custom`, so a browser can send a custom dimension called `user_id` with any
value. `custom` is what the browser sent; never trust it for who the viewer is. For the
fields only your server knows (the signed-in user, the tenant), name a resolver in
`beacons.context`:

```php
use Hei\ScarlettPlayer\Contracts\ResolvesBeaconContext;
use Hei\ScarlettPlayer\Data\BeaconPayload;
use Illuminate\Http\Request;

final class BeaconContext implements ResolvesBeaconContext
{
    public function resolve(Request $request, BeaconPayload $payload): array
    {
        return ['user_id' => $request->user('web')?->getAuthIdentifier()];
    }
}
```

```php
// config/scarlett-player.php
'beacons' => [
    'context' => App\Analytics\BeaconContext::class,
],
```

Three rules:

- **It runs at request time**, in the beacon route, after validation and before the
  beacon is queued, because the queued job has no request. It runs for every beacon,
  heartbeats included, so keep it cheap. The route has no `auth` middleware (guests
  beacon too), so it must not throw for a guest. It is not wrapped: an exception is
  reported by your handler, the beacon is answered `500` and lost, and so is every
  beacon while it keeps throwing. `scarlett:beacon:test` posts through the route and
  runs it.
- **Every key it returns is server-owned on that beacon.** The key is removed from the
  browser's custom dimensions, whatever the browser sent, and the value is stored under
  `scarlett_views.server`. A name the player also uses (`duration`) is allowed, but it
  lands only in `server` and the raw log, never in that name's own column. The six
  identity keys (`event`, `timestamp`, `viewId`, `sessionId`, `viewerId`, `videoId`) are
  refused with `InvalidBeaconContextException`.
- **Null strips.** A key returned as `null` stores nothing but still removes the
  browser's copy, so `['user_id' => $request->user()?->id]` means a guest's view has no
  `user_id` from anywhere. A value stored by an earlier beacon of the view is never
  deleted by a later null.

`scarlett_views.server` is a JSON map beside `custom`, merged the same way: key by key,
each key keeping the value from the newest beacon that sent it (`server_stamps`). Query it
with Laravel's JSON paths; bind the value with the type your resolver returned:

```php
DB::table('scarlett_views')->where('server->tenant_id', 7)->get();
```

The raw log, `BeaconReceived`, `ViewStarted` and the fake's ledger see the server value
under its name, after the browser's keys. The raw log's `event_key` hashes only what the
browser sent, so the server context never makes two deliveries of one beacon look like
two beacons. For an indexed column (a `tenant_id` you filter on), add it in your own
migration and fill it from `ViewStarted`.

A `ProcessesBeacon` step can add server context too, with `$payload->withServer()`, for
anything that does not need the request.

**Migrated with 0.1?** Add two nullable json columns, `server` and `server_stamps`, to
`scarlett_views` before setting `beacons.context` (the `beacon context` doctor check fails
until you do; without a resolver nothing touches them).

#### Recipe: tenant from the request

No middleware needed: the host, or a header your edge sets, is on every request.

```php
public function resolve(Request $request, BeaconPayload $payload): array
{
    return ['tenant_id' => Tenant::idForHost($request->getHost())];
}
```

#### Recipe: the signed-in user, from the session

The beacon route runs in the `api` group, which has no session. Append the cookie and
session middleware to the beacon group, then read the `web` guard:

```php
'routes' => [
    'middleware' => [
        'beacons' => [
            'api',
            'throttle:scarlett-beacons',
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Session\Middleware\StartSession::class,
        ],
    ],
],
```

```php
return ['user_id' => $request->user('web')?->getAuthIdentifier()];
```

Never add `auth` (guests beacon too, and a `401` loses the view) or the CSRF middleware
(the unload `sendBeacon` cannot send a token).

**Same origin only.** The plugin sends every beacon except the unload `viewEnd` with
`fetch`, and sets no `credentials` option, so the browser attaches cookies only when the
ingest is on the page's own origin. With the page and the ingest on one origin, every
beacon carries the session. With the ingest on another origin (another subdomain
included, whatever the cookie's domain), in-session beacons arrive as guests; only the
unload `sendBeacon`, which always sends credentials, carries the session cookie, so a
view gets its `user_id` from that one beacon, or never when it is lost. For an ingest on
another origin, use the token recipe below.

#### Recipe: Sanctum SPA

```php
'beacons' => [
    'api',
    \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
    'throttle:scarlett-beacons',
],
```

```php
return ['user_id' => $request->user('sanctum')?->getAuthIdentifier()];
```

The session rides the cookie, so this too is same origin only, for the reason above.
`EnsureFrontendRequestsAreStateful` adds Laravel's CSRF check to every request from a
stateful domain, and neither beacon transport sends a token, so beacons from your SPA are
answered `419` (Laravel 13 lets a same-origin request through on `Sec-Fetch-Site`; a
cross-origin ingest, and Laravel 12, do not). Exempt the beacon path, in
`bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->validateCsrfTokens(except: ['api/scarlett/beacons']);
})
```

#### Recipe: a token, for an ingest on another origin

Keep the default beacon middleware and read the `sanctum` guard, which accepts a Bearer
token with no session or stateful middleware:

```php
return ['user_id' => $request->user('sanctum')?->getAuthIdentifier()];
```

Give the page a token and pass it through the plugin's `headers` option (merged over
`Content-Type` and `X-API-Key` on every fetch beacon), and add `Authorization` to
`allowed_headers` in `config/cors.php`:

```js
window.scarlettPlayerOptions = {
  analytics: { headers: { Authorization: `Bearer ${token}` } },
};
```

The token is readable by any script on the page, so issue one that is short-lived and
limited to this purpose (`$user->createToken('beacons', ['beacons'], now()->addHours(2))`).
The unload `viewEnd` goes out through `sendBeacon`, which carries no headers, so it is
always a guest; a guest beacon's `null` never deletes the `user_id` the view already has.

### Your own store

`beacons.store` takes `eloquent`, `null` (accept and discard), or the class name of your
own `Hei\ScarlettPlayer\Contracts\BeaconStore`:

```php
interface BeaconStore
{
    public function record(BeaconPayload $payload): void;
}
```

A driver inherits the route, the key, the validation and `BeaconPayload`, and must honour
the same contract: idempotent, order-independent, merged per field with these classes,
and transitions (not receipts) for the events. `Contracts\BeaconStore` spells it out.

### Privacy and retention

The player's `viewerId` is a persistent anonymous id when you do not supply one:
persistent, therefore not automatically anonymous.

- `beacons.store_ip` (default `false`): no address is stored, and the migration creates no
  `ip_address` column. Turn it on **before** migrating; turned on later, addresses are
  skipped and `scarlett:doctor` fails the `beacon ip column` check.
- `beacons.anonymize_ip` (default `true`): the address is truncated (IPv4 last octet, IPv6
  last 64 bits) before the job is queued, so a full address never reaches the queue.
- `beacons.pipeline`: classes implementing `Contracts\ProcessesBeacon`, run in order
  before storage. A step can redact (`$payload->withCustom()`, `->withIp()`), replace the
  server context (`->withServer()`, see Server-side context) or return `null` to drop the
  beacon. What a step returns is what the raw log, the store and
  `BeaconReceived` see.

```php
final class DropEmail implements ProcessesBeacon
{
    public function handle(BeaconPayload $payload, Closure $next): ?BeaconPayload
    {
        return $next($payload->withCustom(Arr::except($payload->custom, ['email'])));
    }
}
```

- `beacons.raw_events_except` (default `[]`): event names never written to
  `scarlett_beacon_events`. `['heartbeat']` cuts the raw log to about a tenth and loses
  only the per-heartbeat trail: every beacon still merges into its `scarlett_views` row,
  and an excluded `error` still reaches `scarlett_view_errors`.
- `beacons.retention`: days per table, `['events' => 90, 'views' => 365]`; `null` keeps
  a table forever. `scarlett:views:prune` applies it and runs daily from your scheduler
  while `beacons.schedule_prune` is on. `scarlett_view_errors` follows `retention.views`.
  The daily run needs Laravel's scheduler running (`php artisan schedule:run` from cron
  every minute, or `schedule:work`); with `schedule_prune` off, run the command yourself.

### Events

| Event | Fires |
|---|---|
| `BeaconReceived` | once per delivery, after the pipeline, with the payload the steps let through; a dropped beacon fires nothing. Not deduplicated: a redelivered job fires it again |
| `ViewStarted` | once, when a view is first stored (by whichever beacon arrives first) |
| `ViewEnded` | once, when a view first gains an end |
| `PlaybackErrorReported` | once per error beacon actually stored |

The last three come from `EloquentBeaconStore`; a host store fires its own.

### Commands

```bash
php artisan scarlett:beacon:test [--url=https://app.example.com/api/scarlett/beacons]
php artisan scarlett:views:prune [--days=30]      # --days overrides retention.events
```

`scarlett:beacon:test` posts a synthetic `viewStart`, `heartbeat` and `viewEnd` through the
header path and again through the query-string path, and fails unless every one is
answered `204`. The beacons are real (queued and stored under a view id starting
`scarlett-beacon-test-`). It proves the route, the key and the body; it does not prove
browser CORS.

`scarlett:doctor` adds, for beacons: the key, the store binding, the route, the CORS
recipe, the beacon queue (warns when it shares a queue with clips) and the IP column.

### Testing against beacons

`ScarlettPlayer::fake()` swaps in a `Testing\FakeBeaconStore` that also fills the fake's
beacon ledger, so beacons posted through the route (with a `sync` queue) can be asserted:

```php
$fake = ScarlettPlayer::fake();

$this->postJson('/api/scarlett/beacons?api_key='.config('scarlett-player.beacons.key'), $body)
    ->assertNoContent();

$fake->assertBeaconRecorded(fn (array $beacon) => $beacon['event'] === 'viewEnd');
```

Bind `FakeBeaconStore` yourself for `assertRecorded()`, `assertRecordedCount()` and
`assertNothingRecorded()` on `BeaconPayload` objects. The player's wire fixtures for
1.17.0 are under `tests/Fixtures/wire/1.17.0/`, captured from the real transports by the
player repo's harness: every beacon event per transport, the three `viewEnd` variants, a
live session and a clip create and retry.

## Clips

A clip is a free, publicly watchable video, even when its source is paid. So a clip is
always a separately rendered, separately stored asset with its own URL, and
`clips.max_duration` is a rights control: it decides how much of a paid event a viewer
may republish, and it is checked on the rendered file as well as the request.

Add the relation to your media model with the `HasScarlettClips` trait (see Media
contract). Migrations are publish-only:

```bash
php artisan vendor:publish --tag=scarlett-migrations
php artisan migrate
```

The `scarlett_clips` migration stores `clippable_id` as a string, so integer, uuid and
ulid media keys all fit. `user_id` is an integer: if your users use uuid or ulid keys,
edit that column after publishing.

### Endpoints

| Method | Route name | Path | What it does |
|---|---|---|---|
| `POST` | `scarlett.clips.store` | `{prefix}/clips` | Validates, stores the clip as `pending`, queues the render, answers `202` |
| `GET` | `scarlett.clips.show` | `{prefix}/clips/{uuid}` | Status, with `playbackUrl` or `previewUrl` when the viewer may have one |
| `GET` | `scarlett.clips.play` | `{prefix}/clips/{uuid}/play` | Redirects to the asset once the clip is `ready` and `public`, else 404 |
| `GET` | `scarlett.clips.preview` | `{prefix}/clips/{uuid}/preview` | Temporary signed; re-checks the `preview` ability, then redirects |

Point the clips plugin's `endpoint.url` at `route('scarlett.clips.store')`. The plugin
posts its `ClipRange` verbatim (camelCase) and gives up after 15 seconds, so the create
endpoint never renders inline. A retry with the same `clientRequestId` returns the first
clip, and queues its render again only if that dispatch never happened. Both the first
request and a retry answer:

```json
{
  "uuid": "9b2c...", "status": "pending", "visibility": "pending_review",
  "mediaId": "abc123", "title": "The knockout",
  "startTime": 120.5, "endTime": 150.5, "duration": 30,
  "failureReason": null,
  "statusUrl": "https://app.test/api/scarlett/clips/9b2c...",
  "playbackUrl": null, "previewUrl": null
}
```

Validation messages are written for viewers, because the plugin shows the `message` of a
422 in its overlay: "Clips can be at most 60 seconds.", "Clips from live streams aren't
available yet.". The bounds come from `clips.min_duration` and `clips.max_duration`, and
the duration is re-derived from `endTime - startTime`; the client's `duration` is
ignored. Live clips are refused in v1: `isLive` must be `false`, every live field
(`seekableStart`, `seekableEnd`, `startDate`, `endDate`) must be null, and the resolved
media must not be live. An unknown `mediaId` is a 404.

Every clip route answers JSON, including auth and validation failures, even though the
plugin sends no `Accept` header.

### Authentication recipes

`routes.middleware.clips` guards **creating** a clip. The status, play and preview routes
run the same list **without its `auth` / `auth:<guard>` entries and the
`throttle:scarlett-clips` entry**, and authorize themselves: status through
`ClipPolicy::view` (guests only when `clips.allow_guests` is on, and then only for clips
that are ready and public; the viewer is resolved through the guards your `auth:` entry
names), play publicly once the clip is
ready and public, and preview through its signature plus `ClipPolicy::preview`. Keep
this in mind when you read the configured list: it is not what the read routes enforce.

**Default: same-origin Blade or Inertia app.** Nothing to change:

```php
'clips' => ['web', 'auth', 'throttle:scarlett-clips'],
```

The `web` group starts the session and checks CSRF. The plugin's default
`credentials: 'same-origin'` sends the session cookie, and the initialiser's `headers()`
reads `X-CSRF-TOKEN` from the page's `csrf-token` meta tag on every request. No session
is a JSON 401; a missing or wrong token is a 419.

**Sanctum SPA (another origin, cookie auth):**

```php
'clips' => [
    'api',
    \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
    'auth:sanctum',
    'throttle:scarlett-clips',
],
```

Add the SPA's host to `sanctum.stateful` and set `supports_credentials` in
`config/cors.php`. `EnsureFrontendRequestsAreStateful` turns on CSRF protection, and a page
on another origin has no `csrf-token` meta tag, so the SPA does the Sanctum handshake
instead: request `GET /sanctum/csrf-cookie` once (with credentials), then send the
`XSRF-TOKEN` cookie's value back as the `X-XSRF-TOKEN` header on every clip request.
Without that header the create request is a 419. Sending cookies and a CSRF header from
the browser does nothing unless the server side is configured this way.

Pass the plugin options through the initialiser by setting `window.scarlettPlayerOptions`
in your entry point; the Blade component's players pick them up. Set it **synchronously,
before any `await`**: a top-level `await` pauses your entry module, and the players can
start during the pause without the options. Do the handshake inside the headers function
instead, which the plugin resolves on every request:

```js
function xsrfToken() {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
  return match ? decodeURIComponent(match[1]) : '';
}

// Started now, awaited per request: never awaited at the top level.
const csrfReady = fetch('https://app.example.com/sanctum/csrf-cookie', { credentials: 'include' });

window.scarlettPlayerOptions = {
  clips: {
    endpoint: {
      credentials: 'include',
      headers: async () => {
        await csrfReady;
        return { 'X-XSRF-TOKEN': xsrfToken() };
      },
    },
  },
};
```

If the SPA page also renders a `csrf-token` meta tag, Laravel reads that stale
`X-CSRF-TOKEN` before your `X-XSRF-TOKEN` and answers 419; return
`'X-CSRF-TOKEN': null` from the headers function to drop it (see
[JS initialiser (module mode)](#js-initialiser-module-mode)).

**Sanctum token:**

```php
'clips' => ['api', 'auth:sanctum'],
```

and send the token through the same options; no CSRF is involved:

```js
window.scarlettPlayerOptions = {
  clips: { endpoint: { headers: () => ({ Authorization: `Bearer ${token}` }) } },
};
```

The status route authenticates the same token through the `sanctum` guard.

All three are tested in `tests/Feature/Clips/MiddlewareRecipesTest.php`.

### Policy: protected media fails closed

The package registers `Hei\ScarlettPlayer\Policies\ClipPolicy` for the `Clip` model
unless you register your own. Its `create` lets signed-in viewers clip unprotected media
only, and denies every protected source (`MediaSource::$isProtected`). Nobody moderates
by default.

Turning paid media into public files must be your explicit decision, and writing a
`create()` is that decision. Extend the default and override what you need:

```php
use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Models\Clip;
use Hei\ScarlettPlayer\Policies\ClipPolicy;
use Illuminate\Contracts\Auth\Authenticatable;

class AppClipPolicy extends ClipPolicy
{
    public function create(?Authenticatable $user, MediaSource $media): bool
    {
        return $user !== null && (! $media->isProtected || $user->can('clip-paid-events'));
    }

    public function moderate(?Authenticatable $user, ?Clip $clip = null): bool
    {
        return $user?->is_moderator === true;
    }
}

// In a service provider's boot():
Gate::policy(Clip::class, AppClipPolicy::class);
```

A registered policy that inherits the default `create()` still denies protected media.
`ClipPolicy::hostPolicyPublished($gate)` answers whether you have written one, and the
player config builder's `withClips()` asks the same question.

The abilities are `create` (with the `MediaSource`), `view`, `preview` and `moderate`.

### Visibility, moderation and delivery

New clips start at `clips.visibility` (`pending_review`). Moderate on the model:

```php
$clip->approve($moderator);   // visibility public, fires ClipApproved
$clip->reject($moderator);    // visibility hidden, fires ClipRejected
```

There is no admin UI. Both are conditional on the clip's current state in the database,
so a moderator acting on a stale model never undoes a render or a rejection: approving a
clip that was rejected or failed meanwhile throws `ClipStateException`. Rejecting a clip
that has not rendered yet stops its render; one rejected mid-render has its file deleted
by the job. `scarlett:clips:reconcile` deletes a rejected clip's asset after
`clips.delete_rejected_after` days (null keeps it), after which the clip can no longer be
approved.

Assets are written **private**, always, at `{clips.path}/{uuid}.mp4` on `clips.disk`.
`Clips\ClipUrlIssuer` is the only class that produces a clip URL:

- `ready` + `public`: a playback URL. With `clips.public_delivery = signed-redirect`
  (default) it is the `/play` route, which answers a 302 to a temporary object URL valid
  for `clips.play_ttl` seconds, so the object stays private and you can front `/play` with
  a CDN. With `disk-public`, `approve()` makes the object public (or, for a clip approved
  before it rendered, the render does) and the URL is the object URL; `reject()` makes it
  private again. A CDN in front of a `disk-public` bucket may keep serving its cached copy
  after a reject until that copy expires; use `signed-redirect` if a reject must take
  effect at once. Moderation and the object's visibility change together under a per-clip
  cache lock (`clips.lock_wait` seconds of waiting), so use a cache store with locks
  (redis, database, file); a moderation that cannot get the lock throws
  `LockTimeoutException` and can be retried. A visibility write the disk refuses throws
  `ClipStorageException`, including a `false` from a disk configured `throw => false`:
  `reject()` then leaves the row untouched, and `scarlett:clips:reconcile` reports the
  clip and exits with a failure code until a retry lands (a disk that throws counts the same;
  a clip locked by a moderation in flight does not). A clip approved before it rendered still
  announces `ClipReady` when its public write is refused (the object stays private), but not
  when a reject landed mid-render and the write hiding the object is refused.
- `ready` and not public: no playback URL. The status response carries a `previewUrl`
  (a temporary signed route, valid for `clips.preview_ttl` seconds) for viewers who pass
  `preview`: the submitter while the clip waits for review, or a moderator.
- anything else: nothing.

Signed-redirect delivery and previews need a disk that issues temporary URLs (S3 and
compatible disks do); `scarlett:doctor` checks it.

### Rendering

Renders run on the queue in `Jobs\RenderClip`, which carries only the clip id. The
`local-ffmpeg` generator ships, so one box with `ffmpeg` and `ffprobe` works. Add your
own with `app(ClipGeneratorManager::class)->extend('fleet', fn ($app, array $config) => ...)`
and a `clips.generators.<name>` entry with `'driver' => 'fleet'`.

The `local-ffmpeg` generator reads three keys under `clips.generators.local-ffmpeg`:

| Key | Default | What it is |
|---|---|---|
| `binary` | `FFMPEG_BINARY`, `ffmpeg` | The ffmpeg executable, a name on `PATH` or an absolute path |
| `ffprobe` | `FFPROBE_BINARY`, `ffprobe` | The ffprobe executable; `ClipVerifier` uses it for every driver |
| `timeout` | `300` | Render budget in seconds; `RenderClip::$timeout` is this plus 30 |

`clips.accuracy` chooses how an unprotected source is cut:

- `keyframe` (default): seek on the input and stream copy. Fast, but the copy can start
  up to one GOP before the in point.
- `exact`: decode, seek on the output, re-encode. Frame accurate, slower.

**Protected sources always render `exact`**, whatever the config says.

Every render, from any driver, passes `Clips\ClipVerifier` before it can become ready.
It reads the video packet timestamps with ffprobe and refuses a file whose packets start
more than `clips.duration_tolerance` before the container start, or whose packet span
(not the container's nominal duration, which hides pre-roll) exceeds the requested
interval plus the tolerance, or, for a protected source, `clips.max_duration` plus the
tolerance. A refused file is deleted, the clip is marked `failed` with
`render_exceeds_bounds`, and `ClipFailed` fires; nothing reaches the clip disk.

**A keyframe miss falls back to exact.** When a keyframe render of an unprotected source
fails verification (common on sources with a GOP longer than the tolerance), the job
re-renders it `exact` once, in the same attempt, and logs the miss at info level
("Scarlett clip keyframe render exceeded bounds; re-rendering exact."). That costs a
second render; if your sources have long GOPs, set `accuracy` to `exact`.

### Workers and recovery

Run clip workers on their own queue, with a timeout that covers the render:

```bash
php artisan queue:work --queue=scarlett-clips --timeout=330
```

`RenderClip::$timeout` is `clips.generators.<driver>.timeout + 30` (330 by default).
The worker's `--timeout` must be at least that, and the connection's `retry_after` (or
Horizon's `timeout`) must be larger, or Laravel re-delivers a job that is still
rendering. The job is `ShouldBeUniqueUntilProcessing` per clip, so however long the
queue backlog, a retry or the reconciler never queues a second render; once a worker
starts it, a second delivery exits unless the first render is older than the job timeout
plus 60 seconds (its worker died).

A render is idempotent: a job that finds its clip `ready`, `failed` or `rejected` exits,
every transition is a conditional update, and a retry that finds the asset already
written re-verifies it and finishes without rendering again. Attempts are counted on the
clip; after `clips.max_attempts` it is `failed`.

`scarlett:clips:reconcile` runs every minute through the scheduler
(`clips.schedule_reconcile`, on by default). It queues pending clips whose dispatch
failed or went stale (`clips.redispatch_after`), retries renders stuck in `processing`
past the job timeout, and deletes rejected assets past their retention. Under
`disk-public` it also sets each clip's object visibility to what its row wants, which
heals a storage write that failed between a moderation and its object. That pass looks
only at clips changed in the last day, because every object write is a billable storage
call; if you suspect older drift, run `php artisan scarlett:clips:reconcile --resync-all`
once. Keep the scheduler running.

`scarlett:doctor` checks that `ffmpeg` and `ffprobe` run and that the clip disk keeps a
private write private and issues temporary URLs.

### Events

`ClipRequested` (a new clip, not a retry), `ClipProcessing`, `ClipReady`,
`ClipFailed` (with a `reason`: `render_exceeds_bounds`, `render_error`,
`attempts_exhausted`, `media_not_found`, `live_source`), `ClipApproved` and
`ClipRejected` (with the moderator as `by`). Each carries the clip, never a URL; ask
`ClipUrlIssuer` for one.

### Testing

`ScarlettPlayer::fake()` records every clip the endpoint accepts:

```php
$fake = ScarlettPlayer::fake()->withMedia($source);

$this->actingAs($user)->postJson(route('scarlett.clips.store'), $range)->assertStatus(202);

$fake->assertClipRequested(fn (array $clip) => $clip['mediaId'] === 'abc123');
```

Each record has `uuid`, `mediaId`, `clientRequestId`, `startTime`, `endTime`, `duration`,
`title` and `userId`.

## Player and embed

### Two modes, one config

`createPlayer()` takes plugin objects with `init()` and `destroy()`, which PHP cannot produce,
so the package emits a **host config** (a versioned JSON schema, `scarlettConfigVersion: 1`)
and pairs it with one of two front ends:

- **`module`** (default): the host bundles `@scarlett-player/*` and the package's JS
  initialiser, which turns the host config into plugins.
- **`embed`**: `data-*` attributes read by the pinned `@scarlett-player/embed` bundle from
  `player.cdn_url`. Nothing to bundle, fewer features.

Pick one globally with `player.mode`, or per call with `->mode()` or the component's `mode`
attribute. What each mode can carry:

<!-- feature-matrix:start -->
| Feature | `module` (host bundles `@scarlett-player/*` + the initialiser) | `embed` (`data-*` attributes + the `@scarlett-player/embed` bundle) |
|---|---|---|
| src, poster, autoplay, muted, loop, start time | yes | yes |
| brand colour / brand text colour | yes | yes; `data-brand-color`, `data-brand-text-color` |
| analytics (beaconUrl, videoId, apiKey) | yes; plus `headers()` in the initialiser | yes; `data-analytics-*`, no extra headers |
| analytics live flag (isLive from the MediaSource) | yes; the initialiser passes it to the analytics plugin, so viewStart is right before the playlist loads | **no**; the embed has no attribute for it, so viewStart reports the player state (false until the playlist loads) |
| analytics heartbeat interval (`player.heartbeat_interval`) | yes | **no**; no `data-analytics-heartbeat-interval` attribute; the player default applies |
| share URL + embed base URL | yes | yes; `data-share-url`, `data-embed-base-url` |
| clips (endpoint, CSRF header) | yes | yes, from player 1.17.0; `data-clips-endpoint`, `data-clips-csrf="meta"`, `data-clips-media-id`, `data-clips-min-duration`, `data-clips-max-duration`, with the `embed.addon.clips` addon; Full and Video builds only; the host page needs its `csrf-token` meta tag |
| chapters | yes | yes, from player 1.17.0; `data-chapters` (JSON or a WebVTT URL), with the `embed.addon.chapters` addon; Full and Video builds only |
| captions | yes | yes, from player 1.17.0; `data-captions`, no addon; Full and Video builds only |
<!-- feature-matrix:end -->

This table is generated from `Hei\ScarlettPlayer\Player\FeatureMatrix`, the same table the
builder enforces (`FeatureMatrix::toMarkdown()`; a test fails if the two differ). A cell
reading "from player X" is checked against `player.player_version`: below it, asking for that
feature in embed mode throws `UnsupportedInEmbedMode`, which names the module-mode
alternative. On the pinned 1.17.0, embed mode carries clips, chapters and captions; chapters
and clips need the embed's addon files, which the component loads for you.

### Config builder

```php
use Hei\ScarlettPlayer\Facades\ScarlettPlayer;

$config = ScarlettPlayer::for($video)          // ScarlettMedia model, media id, or MediaSource
    ->mode('module')
    ->autoplay()->muted()
    ->brandColor($tenant->brand_color)
    ->withAnalytics()                           // beaconUrl, videoId and apiKey from the package route and config
    ->heartbeatInterval(5)                      // seconds; overrides player.heartbeat_interval (module mode only)
    ->withClips()                               // endpoint from the package route; csrf: true
    ->withChapters($video->chapters)            // [['time' => 0, 'label' => 'Intro', 'endTime' => 95], ...] or a WebVTT URL
    ->withCaptions($video->captionTracks)       // [['language' => 'en', 'label' => 'English', 'src' => '...'], ...]
    ->withShare(route('videos.show', $video))   // share button; the embed snippet uses embedUrl()
    ->toArray();                                // the host config, version 1
```

Chapters use the player's own field names: `time` and `label` (required), `endTime`,
`subtitle` and `thumbnail` (optional; `end` is accepted as an alias for `endTime`). A chapter
with an `endTime` stops there, so sparse chapters leave gaps unpainted; a chapter without one
runs until the next chapter starts. Caption tracks take `language`, `label` and `src`, plus
optional `kind` (`subtitles` or `captions`) and `default`.

`beaconUrl`, the clips endpoint and `mediaId` always come from the package's own routes and
your `MediaSource`. `withAnalytics()` also passes `MediaSource::$isLive` to the analytics plugin,
because the player's own `viewStart` reports `isLive: false` until the playlist has loaded;
the embed bundle has no attribute for it (see the matrix). `withAnalytics()` and `withClips()` throw if their routes are switched off.

The heartbeat interval is `player.heartbeat_interval` (`SCARLETT_HEARTBEAT_INTERVAL`), in
seconds; `heartbeatInterval()` overrides it for one player, and null goes back to the config
value. The analytics block then carries `heartbeatInterval` in milliseconds, which is what the
plugin takes (`2.5` becomes `2500`). Unset, nothing is emitted and the player's own 10 s
applies. The value must be from 0.001 to 2147483.647 seconds (the longest delay browsers honour
in `setInterval()`; a longer one fires almost at once); anything else throws
`InvalidPlayerConfigException`. The
embed bundle has no attribute for it: in embed mode a configured interval is left out and the
page still renders, while calling `heartbeatInterval()` throws `UnsupportedInEmbedMode`.
`withClips()` also refuses protected media until you register your own policy for
`Hei\ScarlettPlayer\Models\Clip`, and that policy must declare its own `create()`. The
package's default policy does not count, and neither does a subclass that inherits its
`create()`.

### JS initialiser (module mode)

```bash
php artisan vendor:publish --tag=scarlett-js
npm install @scarlett-player/core @scarlett-player/hls @scarlett-player/native @scarlett-player/ui \
    @scarlett-player/analytics @scarlett-player/clips @scarlett-player/chapters @scarlett-player/captions \
    @scarlett-player/share
```

Import it once from your Vite entry point:

```js
import './vendor/scarlett-player/init.js';
```

It exposes `initScarlettPlayer(container, config, options)`, `initAll(root, options)` and
`buildPlugins(config, options)`, and sets `window.ScarlettPlayerHost`. Every import in it is
static, so every package listed above must be installed.

**Importing it starts nothing.** The Blade component's inline script starts its player,
through `initAll(document, window.scarlettPlayerOptions)` if the initialiser has loaded, or
by leaving a flag the initialiser picks up once your bundle has finished running. So set
page-wide options in your entry point, after the import, and every component player gets
them:

```js
import './vendor/scarlett-player/init.js';

window.scarlettPlayerOptions = {
    // Sanctum SPA: send the session cookie cross-origin.
    clips: { endpoint: { credentials: 'include' } },
    // Token recipe instead: clips: { endpoint: { headers: () => ({ Authorization: `Bearer ${token()}` }) } },
    // Extra beacon headers, resolved per beacon (in-session beacons only; see Beacons).
    analytics: { headers: () => ({ 'X-Tenant': tenantId }) },
};
```

Set it synchronously, before any `await` in your entry module: a top-level `await` yields,
and the players can then start while the options are still undefined.

`options.clips.endpoint` and `options.analytics` are merged into the plugin configs. With
`csrf: true` (what `withClips()` emits), `X-CSRF-TOKEN` from `<meta name="csrf-token">` is
sent on every clip request, and your endpoint `headers` are added to it, never swapped for
it. A header you set to `null` is removed, `X-CSRF-TOKEN` included. On a Sanctum SPA page
that matters: Laravel checks `X-CSRF-TOKEN` before `X-XSRF-TOKEN`, so a stale
`csrf-token` meta tag answers 419 however fresh the XSRF cookie is. Drop the meta tag, or
send `{ 'X-CSRF-TOKEN': null }`. The clips endpoint is emitted as a path, so the session cookie goes with it from any host
that serves the page. The full server-side recipes are under Clips.

For one player that needs its own options, render it with `manual`
(`<x-scarlett-player :media="$video" manual player-id="hero" />`): the container carries
`data-scarlett-manual`, no start call is emitted, and you call
`initScarlettPlayer('#hero', config, options)` yourself, with the config from
`document.getElementById('hero-config')`.

### Blade component

```blade
<x-scarlett-player :media="$video" autoplay muted class="aspect-video" />
<x-scarlett-player :media="$video" mode="embed" brand-color="#e50914" />
<x-scarlett-player :media="$video" analytics clips :chapters="$video->chapters" share-url="{{ url()->current() }}" />
```

Attributes: `media` (required), `mode`, `autoplay`, `muted`, `loop`, `controls`, `start-time`,
`poster`, `title`, `brand-color`, `brand-text-color`, `analytics`, `heartbeat-interval`
(seconds, module mode), `clips`, `chapters`, `captions`, `share-url`, `share-embed`, `player-id`, `manual` and `nonce` (for a CSP). Any other
attribute, such as `class`, goes on the container.

- **Module mode** renders the container, the host config in a `<script type="application/json">`
  and a one-line call into the initialiser.
- **Embed mode** renders the container with the embed bundle's documented `data-*` attributes
  (no invented names) and a `<script>` for the bundle. Its URL is `player.embed_bundle`, a
  template defaulting to `{cdn_url}/v{player_version}/embed.js`, the player CDN's layout: set
  `SCARLETT_CDN_URL` to the CDN origin plus its package prefix, with no version
  (`https://assets.thestreamplatform.com/scarlett-player`), and the pinned version fills in
  the rest. `embed.js` is an ES module, so it is loaded with `type="module"`; a template
  ending in `.cjs` (the UMD build) is loaded as a classic script. For a floating version set
  `SCARLETT_EMBED_BUNDLE='{cdn_url}/latest/embed.js'`, once the CDN serves `/latest/` (it
  serves versioned directories only today).
- **Embed builds.** Clips, chapters and captions need the Full (`embed.js`) or Video
  (`embed.video.*`) build. With `player.embed_bundle` on the Audio build (`embed.audio.*`),
  asking for them throws `UnsupportedInEmbedMode`, naming the build.
- **Embed addons.** With `:chapters` or `clips`, the component also loads
  `embed.addon.chapters.js` and `embed.addon.clips.js` after the bundle. They come from the
  bundle's own directory, in its flavour (`.umd.cjs` beside a `.cjs` bundle), and each is
  loaded once per page. `embedAddonUrls()` on the builder lists them if you write the tags
  yourself. Keep the addons in the same version directory as the bundle: an addon refuses
  an embed of another version. Captions need no addon.
- **Embed clips** post to the clips route with the page's cookies and `X-CSRF-TOKEN` from
  the page's `<meta name="csrf-token">` (the component emits `data-clips-csrf="meta"`, the
  opt-in the embed requires). So they work on your own pages, under the same recipes as
  module mode (see Clips), with the meta tag present. They cannot work on the package's
  embed page, which runs cross-origin in someone else's iframe with no session, so that
  page never enables clips.

`<x-scarlett::player>` is the same component.

### Embed page

`GET /v/{uuid}` (`embed.route`, route `scarlett.embed.show`) serves the media full-window in
the embed bundle, with `noindex` (meta and `X-Robots-Tag`), Open Graph tags, the brand colour
and a `Content-Security-Policy: frame-ancestors` built from `embed.allowed_domains` (`*` when
empty; each domain also allows its subdomains). The brand colour comes from
`MediaSource::$meta['brand_color']` and `['brand_text_color']`. When beacons are on, their
route is registered and `beacons.key` is set, the page also beacons through the analytics
plugin (`data-analytics-*`): an embed iframe beaconing cross-origin is exactly the case the
CORS recipe under Beacons exists for. It never enables clips: in someone else's iframe there
is no session and no CSRF meta tag of yours to send. It carries no chapters or captions either,
since the `MediaSource` has no field for them. Publish the view with `--tag=scarlett-views`.

`embed.allowed_domains` takes bare host names (`example.com`, which also allows its
subdomains). A scheme, port, path or leading `*.` is stripped, and anything that is still not
a host name throws `InvalidEmbedConfigException`.

The page honours `autoplay`, `muted`, `startTime` and `shareUrl` from the query string.
`embed.route` must contain exactly one parameter, the media id.

```php
ScarlettPlayer::embedUrl($video);                 // a URL: give it to the share plugin as embedBaseUrl
ScarlettPlayer::embedUrl($video, now()->addDay()); // signed, expiring
ScarlettPlayer::embedCode($video);                // the <iframe> snippet: give it to a CMS paste field
```

**Signing.** `embedUrl()` signs when the media is protected, when `embed.always_sign` is on,
or when an expiry is given. A signature minted without an explicit expiry lasts
`embed.signed_ttl` seconds (one day by default), because the URL is printed into every page
that shows the media (the share button's embed base) and would otherwise be a permanent
link to paid content; set it to null to sign without expiry. The page then requires a valid signature, and any signature that
is present is always checked. The share plugin appends `startTime` and `shareUrl` to
whatever base URL it is given, so `ValidateEmbedSignature` leaves `embed.unsigned_params`
(`startTime`, `shareUrl`, `autoplay`, `muted` by default) out of the check. The signature
covers the media id and the expiry: `expires` and `signature` are always signed, even if
listed in `unsigned_params`. The presentation parameters are validated separately:
`startTime` must be plain seconds (`42` or `42.5`), and `shareUrl` must be an absolute
http(s) URL with no userinfo, backslash, whitespace or control character, whose host is
plain ASCII (no `%` escapes, no non-ASCII characters), on an allowed domain when
`embed.allowed_domains` is set. A parameter that fails is dropped and
never echoed. A tampered id answers 403.

### oEmbed

`GET {prefix}/oembed?url=<embed page URL>` (route `scarlett.oembed.show`) answers with an
oEmbed `video` response whose `html` is `embedCode()`. It honours `maxwidth` and `maxheight`,
answers 404 for a URL that is not this app's embed page, and 501 for a format other than
`json`. For media that must be signed, the URL has to carry a valid signature (401
otherwise), and the snippet keeps that URL's expiry, so a lookup never mints a longer-lived
link.

## Queues and workers

Beacons and clips use separate queues and, optionally, separate connections, so a
five-minute render never sits in front of ten thousand heartbeats:

| Key | Default |
|---|---|
| `beacons.connection` | `SCARLETT_BEACON_QUEUE_CONNECTION`, null for the app default |
| `beacons.queue` | `SCARLETT_BEACON_QUEUE`, `scarlett-beacons` |
| `clips.connection` | `SCARLETT_CLIP_QUEUE_CONNECTION`, null for the app default |
| `clips.queue` | `SCARLETT_CLIP_QUEUE`, `scarlett-clips` |

A queue name selects nothing about asynchrony. `scarlett:doctor` warns when either
resolved connection is `sync` outside the `local` environment, and notes that `database`
writes each job row synchronously (one insert per heartbeat: acceptable, but a database
write all the same).

### The two jobs

| Job | Queue | Carries | Timeout | Tries |
|---|---|---|---|---|
| `ProcessBeacon` | `beacons.queue` | the beacon payload | 30 s | 5, backoff 5 s, 30 s, 120 s |
| `RenderClip` | `clips.queue` | the clip id only | generator timeout + 30 s | `clips.max_attempts` (3) |

Beacon jobs are small and plentiful: the analytics plugin sends a heartbeat every
`player.heartbeat_interval` seconds per viewer, 10 by default. Each player sends 60 / interval
heartbeats a minute against the `scarlett-beacons` limit (`beacons.throttle`, `600,1` per IP):
a 2 s interval is 30 a minute per player, so a page with several players, or many viewers
behind one NAT, needs a higher limit. Clip jobs are few, CPU-heavy and slow.

### Timeouts: the 330-second arithmetic

`RenderClip::$timeout` is `clips.generators.<driver>.timeout + 30`. With the default
`local-ffmpeg` timeout of 300 s, that is **330 s**. The unique lock
(`ShouldBeUniqueUntilProcessing`, an hour) covers the wait in the queue; once a worker starts,
a second delivery cannot claim the clip until the render is older than the timeout plus 60
seconds.

Two settings outside the package must agree with it, or Laravel hands a still-rendering
job to a second worker:

1. The clips worker runs with `--timeout` of at least 330.
2. The clips connection's `retry_after` (or Horizon's supervisor `timeout`, plus the
   connection's `retry_after`) is **greater** than 330. 360 leaves room.

Raise the generator timeout and all three move together: timeout T gives a job timeout of
T + 30, a worker `--timeout` of at least T + 30, and a `retry_after` above T + 30.

A dedicated clips connection is the clean way to give clips a long `retry_after` without
slowing redelivery for everything else:

```php
// config/queue.php
'connections' => [
    'redis' => [/* your app default, retry_after 90 */],
    'redis-clips' => [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => 'scarlett-clips',
        'retry_after' => 360,
        'block_for' => null,
    ],
],
```

```dotenv
SCARLETT_CLIP_QUEUE_CONNECTION=redis-clips
```

### Worker allocation

```bash
# Beacons: several cheap workers.
php artisan queue:work --queue=scarlett-beacons --timeout=60

# Clips: few workers, one per CPU-heavy slot, on the clips connection.
php artisan queue:work redis-clips --queue=scarlett-clips --timeout=330
```

Do not put both queues on one worker: a render then blocks every heartbeat behind it.
`scarlett:doctor` warns when beacons and clips share one queue on one connection.

Clip workers need the `pcntl` extension: without it a queue worker cannot enforce
`RenderClip`'s timeout, a hung render is never killed, and the clip is only recovered when
`scarlett:clips:reconcile` finds it stuck. `scarlett:doctor` warns when clips are on and
`pcntl` is missing.

### Horizon

```php
// config/horizon.php
'environments' => [
    'production' => [
        'scarlett-beacons' => [
            'connection' => 'redis',
            'queue' => ['scarlett-beacons'],
            'balance' => 'auto',
            'minProcesses' => 2,
            'maxProcesses' => 10,
            'timeout' => 60,
            'tries' => 5,
        ],
        'scarlett-clips' => [
            'connection' => 'redis-clips',
            'queue' => ['scarlett-clips'],
            'balance' => 'simple',
            'processes' => 2,         // one per CPU-heavy slot
            'timeout' => 330,         // at least RenderClip::$timeout
            'tries' => 3,             // clips.max_attempts
        ],
    ],
],
```

Horizon's supervisor `timeout` must stay below the connection's `retry_after` (here 330
under 360), as Horizon itself requires.

## Diagnostics

```bash
php artisan scarlett:doctor
```

Prints one row per check and exits non-zero when any check fails (warnings alone pass):

| Check | Fails or warns when |
|---|---|
| media mapping | `media.model` is unset, not a model, or neither implements `ScarlettMedia` nor maps `playback_url`, `is_live` and `is_protected`. A custom resolver passes with a note |
| queue connections | a module's connection is not defined in `queue.connections` (fail), or is `sync` outside `local` (warn) |
| beacon key | beacons are on and `beacons.key` is empty or has surrounding whitespace |
| beacon store | the `BeaconStore` binding does not resolve (fail), or the null store is bound while beacons are on (warn) |
| embed bundle | `player.mode` is `embed` and the bundle URL cannot be built (fail), or only the embed page would need it (warn) |
| embed domains | an `embed.allowed_domains` entry is not a host name (fail; otherwise every embed page request fails) |
| beacon ip column | `beacons.store_ip` is on but `scarlett_views.ip_address` does not exist, because it is created only when `store_ip` was on at migrate time (fail); the table is missing or cannot be inspected (warn) |
| beacon context | `beacons.context` is set but is not a class implementing `ResolvesBeaconContext`, or `scarlett_views.server` does not exist, on a table migrated by 0.1 (fail); the table is missing or cannot be inspected (warn) |
| beacon route | `routes.beacons` is on but `scarlett.beacons.store` is not registered, e.g. a stale route cache (fail); the route is registered while `beacons.enabled` is off (warn) |
| beacon cors | beacons are on and the CORS config would lose the unload beacon: the path is missing, `supports_credentials` is off, `*` origins with credentials, or `X-API-Key` not allowed (warn) |
| beacon queue | beacons and clips share one queue on one connection (warn) |
| Clip toolchain (ffmpeg, ffprobe) | the configured binaries are not runnable (fail) |
| Clip disk | `clips.disk` is not a configured disk, cannot be written, does not keep a private write private, or cannot issue the temporary URLs previews need (fail) |
| Clip worker timeout (pcntl) | clips are on and the `pcntl` extension is missing, so a worker cannot enforce `RenderClip`'s timeout (warn) |

## Testing with the fake

`ScarlettPlayer::fake()` swaps the facade root for `Hei\ScarlettPlayer\Testing\FakeScarlett`,
which records what your app resolved and the beacons and clip requests it saw:

```php
use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Facades\ScarlettPlayer;

$fake = ScarlettPlayer::fake()->withMedia(new MediaSource(
    id: 'video-1',
    playbackUrl: 'https://cdn.example.test/video-1.m3u8',
    isLive: false,
    isProtected: false,
    duration: 120.0,
));

// ... exercise your app ...

$fake->assertResolved('video-1');     // or assertNothingResolved()
$fake->assertBeaconRecorded(fn (array $beacon): bool => $beacon['event'] === 'viewStart');
$fake->assertClipRequested();
```

Media registered with `withMedia()` answers without touching the database; any other id
falls through to the bound resolver.

## Compatibility

| Package | Player wire contracts (`@scarlett-player/*`) | Wire fixture set | Notes |
|---|---|---|---|
| `v0.1.0` | 1.17.x, pinned at 1.17.0 (`player.player_version`) | `tests/Fixtures/wire/1.17.0/`, captured | Captured by the player repo's harness against the `v1.17.0` checkout (21 fixtures, a 34-beacon session sequence, 12 harness assertions passing); the browser test runs the npm 1.17.0 embed bundle |
| `v0.2.0` | 1.17.x, pinned at 1.17.0 (`player.player_version`) | `tests/Fixtures/wire/1.17.0/`, captured | The same captured fixture set as `v0.1.0`, unchanged. Embed mode now carries chapters, captions and clips on 1.17.0, with the `embed.addon.chapters` and `embed.addon.clips` addon files beside the bundle |

Two contract rules keep the package and the player from drifting:

- **Additive tolerance.** Unknown top-level beacon keys are stored in `custom`, never
  rejected, so upgrading the player ahead of the package never breaks ingest.
- **Batching is a major.** Player 1.17.x sends one beacon per request. If a later player
  batches beacons (an array body), that is a breaking ingest change: the package answers a
  batched body 422 by name today, and accepting both shapes needs a package major.

The wire fixture set is fully captured. The share-built embed iframe fixture is the one
derived fixture: it is generated by the share plugin's own snippet builder, taken byte for
byte from the published 1.17.0 dist, because the capture harness has no share scenario.

The embed bundle location follows `player.embed_bundle`, a template defaulting to
`{cdn_url}/v{player_version}/embed.js`: the player CDN serves one directory per release
(`v1.17.0/`) and no `/latest/` alias yet, so the embed bundle is always the pinned version.

## Development

```bash
composer install
vendor/bin/pest                                  # all groups; browser and integration skip without their tools
SCARLETT_BROWSER=1 vendor/bin/pest --group=browser
vendor/bin/pest --group=integration              # needs ffmpeg and ffprobe on PATH
composer lint                                    # pint --test
composer analyse                                 # phpstan level 8
```

### Browser tests

The `browser` group needs Node 20 or later, `openssl` and Playwright's Chromium (`npm install`, then
`npm run browser:install`). It serves the page on `127.0.0.1:8001`, the ingest on `127.0.0.1:8002`
and a TLS proxy for the beacon origin on `127.0.0.1:8443`. See [`tests/Browser/README.md`](tests/Browser/README.md)
for what each origin is for and how to run the proxy by hand.

## License

MIT. See [LICENSE](LICENSE).
