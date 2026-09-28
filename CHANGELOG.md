# Changelog

All notable changes to `hei/laravel-scarlett-player` are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2026-09-28

### Added

- `beacons.raw_events_except`: event names never written to `scarlett_beacon_events` while `store_raw_events` is on. `['heartbeat']` cuts the raw log to about a tenth and keeps `scarlett_views` exact, since every beacon still merges into its view; an excluded `error` still reaches `scarlett_view_errors`. Entries that are not strings are ignored.
- Server-side beacon context: `beacons.context` names a `Contracts\ResolvesBeaconContext`, called by the beacon route with the request after validation and before the beacon is queued. Every key it returns is server-owned on that beacon: it is removed from the browser's custom dimensions, a non-null value is stored in the new `scarlett_views.server` map (merged per key, newest beacon winning, stamps in `server_stamps`), and `null` strips the browser's copy without storing anything. A `ProcessesBeacon` step can add server context too, with `BeaconPayload::withServer()`. Identity keys are refused with `InvalidBeaconContextException`.
- `BeaconPayload::withServer()`, `browserArray()`, `$server` and `$owned`; `toArray()` puts the server context after the browser's keys.
- The `beacon context` doctor check.
- README recipes for server-side context: a tenant from the request, the user from a session and the Sanctum SPA (same origin only: the plugin's fetch beacons carry no cookie cross-origin), and a Bearer token through the plugin's `headers` option for an ingest on another origin.
- `player.heartbeat_interval` (`SCARLETT_HEARTBEAT_INTERVAL`, seconds): the analytics plugin's heartbeat interval, passed to the player as `heartbeatInterval` in milliseconds. Null, the default, emits nothing and the player's own 10 s applies. Numeric strings are accepted; anything that is not a number above zero throws `InvalidPlayerConfigException`.
- `PlayerConfigBuilder::heartbeatInterval()` and the component's `heartbeat-interval` attribute (seconds) override the setting for one player. The initialiser passes it to the analytics plugin, and a page-wide `window.scarlettPlayerOptions.analytics.heartbeatInterval` still wins. Module mode only: the 1.17.0 embed bundle has no attribute for it, so embed mode leaves a configured interval out and an explicit `heartbeatInterval()` throws `UnsupportedInEmbedMode` (feature matrix row `analytics_heartbeat`).
- Embed mode carries clips, chapters and captions from player 1.17.0: `withClips()`, `withChapters()` and `withCaptions()` build there, and the embed renders `data-captions`, `data-chapters` (JSON, or the WebVTT URL given), `data-clips-endpoint`, `data-clips-csrf="meta"`, `data-clips-media-id`, `data-clips-min-duration` and `data-clips-max-duration`, in the same shapes as the module config. On an older `player.player_version` they still throw `UnsupportedInEmbedMode`. The protected-media clip policy applies in both modes.
- `PlayerConfigBuilder::embedAddonUrls()`: the embed addon files a config needs (`embed.addon.chapters`, `embed.addon.clips`), in the bundle's directory and flavour. The Blade component loads them after the bundle, once per page. Embed clips need the host page's `csrf-token` meta tag; the package's embed page never enables clips.
- `ClipStorageException`, thrown when the clip disk refuses a visibility write.

### Fixed

- A clip visibility write the disk refused counted as a success. On a disk configured `throw => false`, `setVisibility()` returns `false`: `reject()` marked the clip hidden while its object stayed public, and `scarlett:clips:reconcile` counted the object as re-synced. Every visibility write now treats `false` as a failure and throws `ClipStorageException`, naming the clip, disk and path. `reject()` throws before the row changes and fires no `ClipRejected`; `approve()` throws with the row public and the object private; a render still goes ready with a private object and reports the exception; `scarlett:clips:reconcile` reports each refused write and exits with a failure code. Making an object that no longer exists private is not a failure.

### Changed

- The raw log's `event_key` hashes the beacon as the browser sent it, so neither server context nor a pipeline redaction moves it. A beacon no pipeline step changes keeps its 0.1.0 key; a beacon a step redacts gets a new key once across the upgrade. Beacon jobs queued by 0.1.0 still process.
- `scarlett_views` gains two nullable json columns, `server` and `server_stamps`, in its create migration. A table migrated by 0.1.0 needs them added before `beacons.context` is set; without a resolver nothing reads or writes them.
- `scarlett:clips:reconcile` exits with a failure code when the disk refuses a visibility write.
- CI runs on the pinned `ubuntu-24.04` runner image instead of `ubuntu-latest`.

## [0.1.0] - 2026-09-27

### Added

- Package scaffold: Composer package `hei/laravel-scarlett-player` on PHP `^8.3|^8.4|^8.5` and Laravel `^12.0|^13.0`, Pint, PHPStan level 8 with larastan, Pest 4 with the `unit`, `feature`, `browser` and `integration` groups.
- `config/scarlett-player.php` with the routes, media, beacons, clips, player and embed sections, publishable under `scarlett-config`.
- `ScarlettPlayerServiceProvider` with per-module route loading behind `routes.<module>`, per-module middleware from `routes.middleware`, route names `scarlett.<module>.<action>`, the `scarlett-beacons` and `scarlett-clips` rate limiters, and the `scarlett-config`, `scarlett-migrations`, `scarlett-views` and `scarlett-js` publish tags.
- `ScarlettPlayer` facade with `resolve()` (throws `MediaNotFoundException` for an unknown id), `for()` and `fake()`.
- `Testing\FakeScarlett` recording resolved media, with `assertResolved()`, `assertNothingResolved()`, `assertBeaconRecorded()` and `assertClipRequested()`.
- `scarlett:doctor` with checks for the media mapping, the beacon key, the queue connections and the beacon store binding.
- `package.json` with Playwright for the browser group (`browser:install`, `proxy`, `cert` scripts).
- Browser test support: `tests/Browser/support/tls-proxy.mjs`, a transparent TLS proxy for the HTTPS beacon origin, and `make-cert.sh`, with a smoke test proving OPTIONS, headers, query string and body pass through unchanged.
- CI: pint, phpstan, em-dash gate, PHP 8.3/8.4 x Laravel 12/13 matrix, browser and integration jobs; release workflow that tags and publishes from the first CHANGELOG heading.
- Beacons: `POST {prefix}/beacons` (`scarlett.beacons.store`) with `Http\Middleware\ScarlettApiKey`, taking the key from `X-API-Key` or `?api_key=` (the unload `sendBeacon` cannot send a header), compared with `hash_equals`, failing closed while `beacons.key` is unset, and never refusing `OPTIONS`. Answers `204` and queues `Jobs\ProcessBeacon` on `beacons.connection` / `beacons.queue` before any aggregation.
- `Data\BeaconPayload` and `Http\Requests\BeaconRequest`: one JSON object per request (batched arrays refused by name, bodies over `beacons.max_body_bytes` answered `413`); only the event, the four ids and the timestamp can refuse a beacon. Unknown keys, and known names carrying another type, are stored as custom dimensions, never rejected; `null` is absent.
- `Contracts\BeaconStore` with its idempotency and merge contract, and `Stores\EloquentBeaconStore`: one view row per `viewId` merged per field (set-once, monotonic, latest-by-timestamp with a stamp per column, fill-if-absent), raw events and errors unique on `event_key`, as three atomic statements verified on SQLite, MySQL and Postgres. `Stores\NullBeaconStore`, `Testing\FakeBeaconStore`, and `beacons.store` taking `eloquent`, `null` or a host class.
- Publish-only migrations for `scarlett_views`, `scarlett_beacon_events` and `scarlett_view_errors`; no IP column unless `beacons.store_ip` is on at migration time.
- `scarlett_views.is_live` merges true-wins: any beacon with `isLive: true` sets it and nothing clears it, `false` only fills an empty column, and `isLive: null` (the player 1.18 `viewStart`) is absent. A live `viewStart` that fired before the playlist was read, or a live stream that became a replay, does not mislabel the view.
- Custom dimensions merge per key with a stamp per key (`scarlett_views.custom_stamps`), so an older beacon delivered late still wins the keys no newer beacon wrote; a nested value is replaced whole on every engine. Two beacons in the same millisecond tie-break on their event key, and numeric custom keys (`"0"`, `"5"`) stay object keys in the view and the raw log.
- The beacon store takes the view's row lock first on MySQL and Postgres, avoiding the InnoDB shared-to-exclusive deadlock between two workers merging one view.
- Events `BeaconReceived` (per delivery, after the pipeline), `ViewStarted`, `ViewEnded` and `PlaybackErrorReported` (on observed transitions only). `Contracts\ProcessesBeacon` steps listed in `beacons.pipeline` redact or drop a beacon before anything stores or announces it. `beacons.anonymize_ip` truncates the address before it is queued.
- `scarlett:beacon:test {--url=}` (both key paths, expects `204`), `scarlett:views:prune {--days=}` with a daily schedule behind `beacons.schedule_prune`, and doctor checks for the beacon route, the CORS recipe, the beacon queue and the IP column.
- `ScarlettPlayer::fake()` records beacons posted through the route in the fake's beacon ledger.
- `player.player_version` defaults to `1.17.0`.
- Browser test of the CORS recipe with the real analytics plugin from `@scarlett-player/embed` 1.17.0, cross-origin over HTTPS; wire fixtures for player 1.17.0 under `tests/Fixtures/wire/1.17.0/`, captured from the real transports by the player repo's harness and replayed by the beacon and clip tests; CI job running the beacons suite on MySQL 8 and Postgres 17.
- Media contract: `Contracts\ResolvesMedia`, `Contracts\ScarlettMedia` and the readonly `Data\MediaSource`, whose `isLive` and `isProtected` have no defaults.
- `Media\ConfigModelResolver`, the default resolver: resolves through `ScarlettMedia` or a complete `media.attributes` map (`disk:` literals for `source_disk`), returns `null` for an unknown id, and exposes `assertMappingComplete()` for the doctor. Protection fails closed on a missing mapping and on a null or unreadable flag value.
- `Concerns\HasScarlettClips` with the `clips()` morph relation (`clippable_type`/`clippable_id`) and a `scarlettMediaId()` default of the route key.
- `Exceptions\ScarlettPlayerException` base with `IncompleteMediaMappingException` (lists the missing keys) and `MediaNotFoundException`.
- Player config: `ScarlettPlayer::for()` returns `Player\PlayerConfigBuilder`, which emits the versioned host config (`scarlettConfigVersion: 1`) through `toArray()`/`toJson()`, with `mode()`, playback, poster, title and brand setters, `withAnalytics()` and `withClips()` wired to the package routes, `withChapters()`, `withCaptions()` and `withShare()`.
- `Player\FeatureMatrix`, the one table of what `module` and `embed` modes carry (cells may name a minimum player version). The builder enforces it and the README matrix is generated from it. Embed mode says no for clips, chapters and captions on the pinned player and throws `UnsupportedInEmbedMode`.
- `withClips()` refuses protected media unless the host published its own Clip policy with a `create()` (`ClipPolicyMissingException`).
- `resources/js/init.js`, the module-mode initialiser (`initScarlettPlayer()`, `initAll()`, `buildPlugins()`), publishable under `scarlett-js`.
- Blade component `<x-scarlett-player>` (also `<x-scarlett::player>`): module mode writes the host config and calls the initialiser; embed mode writes only the embed README's `data-*` attributes and loads the pinned bundle from `player.cdn_url`.
- Embed page at `embed.route` (`scarlett.embed.show`) with noindex, Open Graph tags, brand colour from the media meta, and a `frame-ancestors` policy from `embed.allowed_domains`.
- `Http\Middleware\ValidateEmbedSignature`: checks signatures while ignoring `embed.unsigned_params`, requires one for protected media or `embed.always_sign`, and drops an invalid `startTime` or an off-domain or non-http(s) `shareUrl` without echoing it.
- `ScarlettPlayer::embedUrl()` (signed when protected, `always_sign` or given an expiry) and `ScarlettPlayer::embedCode()` (the iframe snippet).
- oEmbed endpoint `scarlett.oembed.show`, whose `html` is `embedCode()`; signed media needs a signed URL and keeps its expiry.
- `embed.signed_ttl` (one day by default): an embed signature minted without an explicit expiry is temporary, so a printed embed URL for protected media is never permanent.
- `embed.allowed_domains` entries are normalised to bare hosts, and a non-host entry throws `InvalidEmbedConfigException`; `expires` and `signature` are always signed, whatever `embed.unsigned_params` lists.
- The embed page beacons through the analytics plugin when beacons, their route and `beacons.key` are configured.
- The initialiser starts nothing on import: the component's inline call passes `window.scarlettPlayerOptions` (the Sanctum SPA, token and analytics-headers recipes), and `manual` components are left to the host. Host clip endpoint headers are added to the CSRF header rather than replacing it.
- The clips endpoint in the host config is a path, so the session cookie goes with it from any host serving the page.
- `shareUrl` on the embed page is refused when it carries userinfo, a backslash, whitespace or a control character (a `parse_url()` and browser disagreement), or a host that is not plain ASCII; `startTime` accepts plain seconds only.
- `scarlett:doctor` check for `embed.allowed_domains`. In the initialiser, a host header set to `null` removes that header, `X-CSRF-TOKEN` included.
- `ConfigModelResolver` treats an id that a native uuid column cannot hold as unknown (404) instead of failing on Postgres (SQLSTATE 22P02), and runs the lookup in a savepoint inside an open transaction.
- `withChapters()` emits the player's `Chapter` shape (`time`, `label`, `endTime`, `subtitle`, `thumbnail`), so an explicit end is honoured and sparse chapters leave their gaps unpainted; `end` is accepted on input as an alias for `endTime`.
- The analytics block carries `isLive` from the `MediaSource` and the initialiser passes it to the analytics plugin, so `viewStart` reports live media correctly before the playlist loads; the feature matrix records that embed mode cannot (no attribute).
- Composer requires `illuminate/auth` and `illuminate/validation`, which ship the authentication, authorization and validation exceptions the clips module throws.
- `player.embed_bundle`, a template for the embed bundle URL (`{cdn_url}/v{player_version}/embed.js` by default, the player CDN's versioned layout), loaded as an ES module (`type="module"`) unless it ends in `.cjs`, and a `scarlett:doctor` check for it: fail in embed mode, warn when only the embed page needs it.
- Clips: `Models\Clip` with `ClipStatus`, `ClipVisibility` and `ClipAccuracy` enums, and the publish-only `scarlett_clips` migration (a string `clippable_id`, so uuid and ulid host keys fit).
- Clip endpoints `scarlett.clips.store` (202 inside the plugin's 15 s budget), `scarlett.clips.show`, `scarlett.clips.play` and `scarlett.clips.preview`, all answering JSON. The read routes drop the configured auth and throttle entries and authorize themselves.
- `Http\Requests\StoreClipRequest`: the camelCase `ClipRange` validated as sent, viewer-facing one-sentence messages, bounds from config with the duration re-derived from `endTime - startTime`, and live ranges or live media refused ("Clips from live streams aren't available yet.").
- `clientRequestId` idempotency with an outbox dispatch: after commit, `dispatched_at`, `Clip::ensureDispatched()` on retry, and `scarlett:clips:reconcile`, scheduled every minute unless `clips.schedule_reconcile` is off. A failed push releases the job's unique lock so the recovery dispatch is not skipped.
- `Jobs\RenderClip`: `ShouldBeUniqueUntilProcessing` per clip (the lock covers queue backlog), a claim that takes only a pending clip or an abandoned render, timeout = generator timeout + 30, conditional transitions, attempts up to `clips.max_attempts`, and an existing asset re-verified without a second render.
- `Contracts\ClipGenerator`, `Generators\ClipGeneratorManager` with the `local-ffmpeg` driver, and `Data\ClipOptions`. Protected sources always render `exact`; an unprotected keyframe render that fails verification is re-rendered `exact` once and logged.
- `Clips\ClipVerifier`: every render is checked on the ffprobe packet span of all its streams (a file without video is refused), not the container duration, before it can become ready (`render_exceeds_bounds`), with a real-media integration test.
- `Clips\ClipUrlIssuer`, the only source of clip URLs: private assets, `signed-redirect` and `disk-public` delivery, previews through temporary signed routes (`clips.preview_ttl`), and `clips.play_ttl` for signed-redirect playback.
- `Policies\ClipPolicy` (`create`, `view`, `preview`, `moderate`), registered unless the host has its own; protected media is denied until the host writes a `create()`, and `ClipPolicy::hostPolicyPublished()` answers that question for the whole package. Guests (with `clips.allow_guests`) see only ready, public clips, and a denied request answers "You can't make clips from this video."
- `Clip::approve()` and `Clip::reject()` as conditional updates on the row (a stale model never undoes a render or a rejection), `clips.delete_rejected_after`, and the events `ClipRequested`, `ClipProcessing`, `ClipReady`, `ClipFailed`, `ClipApproved` and `ClipRejected`.
- `scarlett:doctor` checks for `ffmpeg`/`ffprobe`, for a clip disk that keeps writes private and issues temporary URLs, and a warning when clip workers lack `pcntl` to enforce the render timeout.
- `FakeScarlett` records every clip the endpoint accepts, for `assertClipRequested()`.
- The clip row and its stored object stay in step: `approve()`, `reject()` and a finishing render take a per-clip lock (`scarlett:clip:{uuid}`, waiting `clips.lock_wait` seconds) around the row update and the object visibility write, and every visibility write re-reads the row and makes the object private again if the clip is no longer ready and public.
- Any exception after a render job claims its clip hands the clip back as pending for the queue retry (or fails it on the last attempt), never leaving it processing.
- `HasScarlettClips::clips()` returns `Relations\StringKeyedMorphMany`, so `withCount('clips')`, `has()` and `whereHas()` work on every engine, casting the key to text on Postgres, where `varchar = integer` is refused.
- `clips.enabled = false` stops new clips: the create endpoint answers 404, render jobs leave their clips pending, the reconciler dispatches nothing; existing clips stay readable.
- `reject()` makes the object private before it hides the row, so a failed storage write never leaves a hidden clip public; `scarlett:clips:reconcile` re-syncs the objects of clips changed in the last day under disk-public, healing any row and object left apart.
- A render delivery that finds its clip mid-render releases itself until the render would count as abandoned, instead of being consumed; a render error stamps `dispatched_at` past its backoff so the reconciler does not queue a second job meanwhile.
- `scarlett:doctor` warns when the cache store's locks are not shared (array, null, or file on several servers), and with clips disabled the create endpoint answers 404 before any validation.
- A clip only becomes ready once its object is confirmed on the disk at the expected size; a write that fails, throws or comes up short is a render error for that attempt.
- The three clip authentication recipes (web + auth, Sanctum SPA, Sanctum token) tested end to end.
