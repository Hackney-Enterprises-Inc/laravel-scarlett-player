<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Every route the package registers, one switch and one middleware list per
    | module. The embed page lives at embed.route (no prefix); everything else
    | lives under the prefix. Route names are scarlett.<module>.<action>.
    |
    */

    'routes' => [

        /**
         * URI prefix for the beacon, clip and oEmbed routes.
         */
        'prefix' => env('SCARLETT_ROUTE_PREFIX', 'api/scarlett'),

        /**
         * Middleware per route group, a map rather than one list, so a host
         * overrides one group without touching the others.
         *
         * beacons: stateless by necessity. The unload beacon cannot carry a CSRF
         * token or a header, so the API key middleware is added by the route
         * itself and accepts X-API-Key or ?api_key=.
         * clips: session and CSRF through the web group, then auth. See the README
         * for the Sanctum SPA and token recipes.
         */
        'middleware' => [
            'beacons' => ['api', 'throttle:scarlett-beacons'],
            'clips' => ['web', 'auth', 'throttle:scarlett-clips'],
            'embed' => ['web'],
            'oembed' => ['api'],
        ],

        /**
         * Register the beacon ingest route.
         */
        'beacons' => true,

        /**
         * Register the clip routes (create, status, play, preview).
         */
        'clips' => true,

        /**
         * Register the embed page and the oEmbed endpoint.
         */
        'embed' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    |
    | How the package turns a player mediaId into a MediaSource. Either the model
    | implements ScarlettMedia, or the attribute map below is complete. There is
    | no "assume unprotected" fallback: a missing is_protected mapping throws.
    |
    */

    'media' => [

        /**
         * A class implementing Hei\ScarlettPlayer\Contracts\ResolvesMedia. Null
         * uses the built-in ConfigModelResolver, which reads the keys below.
         */
        'resolver' => null,

        /**
         * The host model class, e.g. App\Models\Video::class. It implements
         * ScarlettMedia, or the attribute map below is filled in.
         */
        'model' => null,

        /**
         * The model column a mediaId is looked up by.
         */
        'key' => 'uuid',

        /**
         * Model attribute per MediaSource field, used when the model does not
         * implement ScarlettMedia. playback_url, is_live and is_protected are all
         * required. source_disk takes a column name or a literal 'disk:<name>'.
         */
        'attributes' => [
            'playback_url' => null,
            'is_live' => null,
            'is_protected' => null,
            'duration' => null,
            'source_disk' => null,
            'source_path' => null,
            'title' => null,
            'poster' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Beacons (analytics ingest)
    |--------------------------------------------------------------------------
    */

    'beacons' => [

        /**
         * Accept and process analytics beacons.
         */
        'enabled' => true,

        /**
         * The API key the analytics plugin sends, as the X-API-Key header on fetch
         * or ?api_key= on the unload sendBeacon.
         */
        'key' => env('SCARLETT_BEACON_KEY'),

        /**
         * The BeaconStore driver: eloquent, null, or the class name of a host store
         * implementing Hei\ScarlettPlayer\Contracts\BeaconStore.
         */
        'store' => 'eloquent',

        /**
         * Queue connection for beacon jobs. Null uses the app's default connection.
         */
        'connection' => env('SCARLETT_BEACON_QUEUE_CONNECTION'),

        /**
         * Queue name for beacon jobs. Keep it apart from the clip queue.
         */
        'queue' => env('SCARLETT_BEACON_QUEUE', 'scarlett-beacons'),

        /**
         * The scarlett-beacons rate limit, as "attempts,minutes".
         */
        'throttle' => '600,1',

        /**
         * Keep every raw beacon in scarlett_beacon_events as well as the aggregate.
         */
        'store_raw_events' => true,

        /**
         * Event names never written to scarlett_beacon_events, even while
         * store_raw_events is on. The view row still merges every beacon, so
         * ['heartbeat'] keeps the aggregate exact and cuts the table to about a tenth,
         * losing only the per-heartbeat trail. Errors listed here still reach
         * scarlett_view_errors.
         */
        'raw_events_except' => [],

        /**
         * Store the client IP address at all.
         */
        'store_ip' => false,

        /**
         * Truncate a stored IP address before it is written.
         */
        'anonymize_ip' => true,

        /**
         * Days to keep raw events and aggregated views (with their errors) before
         * scarlett:views:prune removes them. Null keeps that table forever. Raw
         * events are aged by when the server received them, views by their last
         * update.
         */
        'retention' => ['events' => 90, 'views' => 365],

        /**
         * Largest beacon body accepted, in bytes; a larger one is answered 413. A
         * 1.16.x beacon is well under 2 KB, so the default leaves room for custom
         * dimensions without accepting arbitrary payloads.
         */
        'max_body_bytes' => 65536,

        /**
         * Run scarlett:views:prune daily from the host's scheduler.
         */
        'schedule_prune' => env('SCARLETT_BEACON_SCHEDULE_PRUNE', true),

        /**
         * Steps between the queue and the store, in order: classes implementing
         * Hei\ScarlettPlayer\Contracts\ProcessesBeacon, resolved from the container.
         * Each may redact the beacon or return null to drop it before storage.
         */
        'pipeline' => [],

        /**
         * A class implementing Hei\ScarlettPlayer\Contracts\ResolvesBeaconContext, resolved
         * from the container for every beacon before it is queued. It adds fields the
         * browser cannot be trusted with (the user id, the tenant); its keys always beat
         * a custom dimension of the same name. Null attaches nothing. Writes to the
         * scarlett_views.server and server_stamps columns.
         */
        'context' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Clips
    |--------------------------------------------------------------------------
    */

    'clips' => [

        /**
         * Accept and render clip requests. Off: the create endpoint answers 404,
         * render jobs exit leaving their clips pending, and the reconciler neither
         * dispatches nor retries stuck renders; existing clips keep their status, play
         * and preview routes. Turning it back on recovers the waiting clips through
         * scarlett:clips:reconcile, so only when schedule_reconcile is on (or you run
         * the command yourself).
         */
        'enabled' => true,

        /**
         * The generator (a key of generators below) that renders clips.
         */
        'generator' => env('SCARLETT_CLIP_GENERATOR', 'local-ffmpeg'),

        /**
         * Generator definitions. timeout is the render budget in seconds; the
         * RenderClip job timeout is this plus 30.
         */
        'generators' => [
            'local-ffmpeg' => [
                'driver' => 'local-ffmpeg',
                'binary' => env('FFMPEG_BINARY', 'ffmpeg'),
                'ffprobe' => env('FFPROBE_BINARY', 'ffprobe'),
                'timeout' => 300,
            ],
        ],

        /**
         * The disk rendered clips are written to. Assets are always private on it.
         */
        'disk' => env('SCARLETT_CLIP_DISK', 's3'),

        /**
         * Directory on the disk for rendered clips.
         */
        'path' => 'clips',

        /**
         * Queue connection for render jobs. Null uses the app's default connection.
         */
        'connection' => env('SCARLETT_CLIP_QUEUE_CONNECTION'),

        /**
         * Queue name for render jobs. Keep it apart from the beacon queue.
         */
        'queue' => env('SCARLETT_CLIP_QUEUE', 'scarlett-clips'),

        /**
         * Render attempts before a clip is marked failed.
         */
        'max_attempts' => 3,

        /**
         * Seconds a pending clip may sit undispatched before a retry or
         * scarlett:clips:reconcile dispatches it again.
         */
        'redispatch_after' => 120,

        /**
         * Shortest clip, in seconds.
         */
        'min_duration' => 5,

        /**
         * Longest clip, in seconds. Enforced on the request and on the rendered file.
         */
        'max_duration' => 60,

        /**
         * Seconds of slack the verifier allows over the requested span.
         */
        'duration_tolerance' => 1.0,

        /**
         * keyframe or exact. Applies to unprotected sources only; protected sources
         * always render exact.
         */
        'accuracy' => 'keyframe',

        /**
         * Visibility a new clip starts with.
         */
        'visibility' => 'pending_review',

        /**
         * How a public clip is delivered: signed-redirect (the object stays private
         * and /play redirects to a short-lived URL) or disk-public.
         */
        'public_delivery' => 'signed-redirect',

        /**
         * Seconds a preview URL stays valid.
         */
        'preview_ttl' => 600,

        /**
         * Seconds the short-lived object URL behind /play stays valid under
         * signed-redirect delivery.
         */
        'play_ttl' => env('SCARLETT_CLIP_PLAY_TTL', 300),

        /**
         * Schedule scarlett:clips:reconcile every minute. It dispatches pending
         * clips whose dispatch failed, retries stuck renders and deletes rejected
         * assets past delete_rejected_after. Turn off only if you run it yourself.
         */
        'schedule_reconcile' => env('SCARLETT_CLIP_RECONCILE', true),

        /**
         * Seconds approve(), reject() and a finishing render wait for a clip's
         * moderation lock, which keeps the row and the object's visibility in step.
         * Needs a cache store with locks (redis, database, file, array).
         */
        'lock_wait' => 5,

        /**
         * Days before a rejected clip's asset is deleted. Null keeps it.
         */
        'delete_rejected_after' => 7,

        /**
         * Let guests read clip status.
         */
        'allow_guests' => false,

        /**
         * The scarlett-clips rate limit, as "attempts,minutes".
         */
        'throttle' => '10,1',
    ],

    /*
    |--------------------------------------------------------------------------
    | Player
    |--------------------------------------------------------------------------
    */

    'player' => [

        /**
         * module (the host bundles @scarlett-player/*) or embed (the CDN bundle).
         */
        'mode' => 'module',

        /**
         * The player CDN origin plus its package prefix, with no version, e.g.
         * https://assets.thestreamplatform.com/scarlett-player. For embed mode.
         */
        'cdn_url' => env('SCARLETT_CDN_URL'),

        /**
         * The @scarlett-player/* version whose wire contracts this app targets.
         */
        'player_version' => env('SCARLETT_PLAYER_VERSION', '1.19.1'),

        /**
         * Where the embed bundle lives, as a template: {cdn_url} and {player_version}
         * are replaced with the keys above. The player CDN serves versioned directories
         * (v1.19.1/embed.js, an ES module); for a floating version use
         * '{cdn_url}/latest/embed.js' once the CDN serves /latest/. A path ending in
         * .cjs (the UMD build) is loaded as a classic script, anything else as a module.
         */
        'embed_bundle' => env('SCARLETT_EMBED_BUNDLE', '{cdn_url}/v{player_version}/embed.js'),

        /**
         * Seconds between the analytics plugin's heartbeats, passed to the player as
         * heartbeatInterval. Null leaves the player's default (10 s). Each player sends
         * 60 / interval heartbeats a minute against the scarlett-beacons limit
         * (beacons.throttle, 600 a minute per IP by default), so a short interval on a
         * page with several players needs a higher limit. Module mode only on player
         * 1.19.1: the embed bundle has no attribute for it.
         */
        'heartbeat_interval' => env('SCARLETT_HEARTBEAT_INTERVAL'),

        /** Per-view ephemeral IDs without browser storage. Does not enable analytics. */
        'analytics_anonymous' => env('SCARLETT_ANALYTICS_ANONYMOUS', false),

        /** Suppress beacons when the browser sends DNT or Global Privacy Control. */
        'analytics_respect_do_not_track' => env('SCARLETT_ANALYTICS_RESPECT_DO_NOT_TRACK', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Embed
    |--------------------------------------------------------------------------
    */

    'embed' => [

        /**
         * URI of the embed page.
         */
        'route' => '/v/{uuid}',

        /**
         * Bare host names (e.g. 'example.com') allowed to frame the embed page and to
         * appear as a shareUrl; each also allows its subdomains. A scheme, port, path or
         * leading '*.' is stripped; anything that is still not a host name throws.
         * Empty allows every domain.
         */
        'allowed_domains' => [],

        /**
         * Sign every embed URL, not only those for protected media.
         */
        'always_sign' => false,

        /**
         * Seconds a minted embed signature lasts when no expiry is given (embedUrl()
         * without $expires, the share button's embed base). Null signs without expiry,
         * which makes every printed embed URL for protected media permanent.
         */
        'signed_ttl' => 86400,

        /**
         * Query parameters the embed signature ignores, because the share plugin
         * appends them to a signed base URL. Validated separately. 'expires' and
         * 'signature' are always signed and are ignored here.
         */
        'unsigned_params' => ['startTime', 'shareUrl', 'autoplay', 'muted'],
    ],

];
