<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Data;

use Hei\ScarlettPlayer\Exceptions\InvalidBeaconContextException;

/**
 * One analytics beacon, as the player's analytics plugin sends it.
 *
 * The wire contract is `BeaconPayload` in @scarlett-player/analytics (types.ts) and
 * the payloads built in its index.ts: a flat JSON object with the context keys on
 * every beacon, the event-specific keys beside them, and the host's customDimensions
 * spread at the top level. Keys the package knows, carrying the type the player
 * sends them in, are split into $context and $fields; every other key, and a known
 * name carrying another type, lands in $custom and is stored, never rejected,
 * because a 422 on an unknown key breaks every host that adds a custom dimension.
 *
 * Null is absent except for qoeScore/qoeVersion: a null score clears an older score
 * so an access-denied view is excluded from averages. The two gauges additionally
 * remember arriving as an explicit null ($gaugeNulls): a live viewEnd sends
 * completionRate: null, and the canonical gauge columns need that measurement
 * distinguished from an omitted gauge. So does their `gaugeScale` marker: a
 * null marker is a present, invalid one, never an absent marker that would
 * fall back to the producer registry. The provenance is narrow: get(), has(),
 * the raw classification and the hash basis are unchanged, and the stored
 * arrays add only that null marker, so the gauges backfill reads the same
 * marker from the raw log as ingest did.
 *
 * $server is what the host asserted about the beacon (beacons.context, or a pipeline
 * step through withServer()), never what the browser sent. A key in $server is never
 * also in $custom.
 */
final readonly class BeaconPayload
{
    /**
     * sha1 of the legacy-normalized beacon as received, before any with*() change.
     * Field promotions preserve the old custom-key order for deduplication, so this
     * need not equal the hash of the current browserArray(), even before redaction.
     */
    public string $bodyHash;

    /**
     * Known-typed values of names promoted since the job was queued, captured from
     * $custom once, when a payload serialized by an older package (no SERIAL_VERSION
     * marker) is restored. Uninitialized on every other payload. Carried unchanged
     * through the with*() methods, so neither a pipeline's withCustom() nor
     * withServer() ownership adds or removes one. Read only by knownValue().
     *
     * Deliberately not set by the constructor, whose public signature stays as it
     * was: PHP lets the class initialize a readonly property once from its own scope
     * (__unserialize(), carryLegacy()), and every read goes through isset().
     *
     * @var array<string, int|float|string|bool>
     */
    private array $legacyKnown; // @phpstan-ignore property.uninitializedReadonly

    /**
     * The gauge keys received as an explicit null, a subset of GAUGE_NULL_KEYS.
     * Uninitialized when the beacon carried none and on every payload restored
     * from a queue written before this provenance existed: both mean "no
     * explicit-null claim", so an older job never gains one. Carried unchanged
     * through the with*() methods. Never part of the hash basis; the stored
     * arrays show only a null marker (nullGaugeScale()). Read through
     * explicitlyNullGauge().
     *
     * @var list<string>
     */
    private array $gaugeNulls; // @phpstan-ignore property.uninitializedReadonly

    /**
     * Written by __serialize(). A serialized payload without it was queued by an
     * older package, whose classification may have left promoted names in custom.
     */
    private const SERIAL_VERSION = 1;

    /** Keys every beacon carries. The four ids are required. */
    public const IDENTITY = ['event', 'timestamp', 'viewId', 'sessionId', 'viewerId', 'videoId'];

    /** The gauge keys and marker whose explicit null is remembered ($gaugeNulls). */
    private const GAUGE_NULL_KEYS = ['completionRate', 'rebufferRatio', 'gaugeScale'];

    /**
     * Frozen field promotions: these names remain custom in the v0.2.1 hash basis,
     * regardless of type. Do not remove them when changing DTO classification.
     */
    private const LEGACY_HASH_CUSTOM = [
        'beaconSeq', 'seekSource',
        'anonymous',
        'pageUrl',
        'referrerOrigin',
        'pageLoadToInitMs',
        'playerInitMs',
        'qoeVersion',
        'errorCategory',
        'errorSeverity',
        'httpStatus',
        'mediaErrorCode',
        'attempts',
        'retriesExhausted',
        'reconnectExhausted',
        'timedOut',
        'warningCount',
        'fatalErrorCategory',
        'segmentCount',
        'segmentBytes',
        'segmentLoadAvgMs',
        'segmentLoadMaxMs',
        'segmentErrors',
        'segmentThroughputBps',
        'decodedFrames',
        'droppedFrames',
        // player 1.22.0
        'elementSeekCount',
        'reconnectCount',
        'reconnectDuration',
        'dvrTime',
        'attempt',
        'delayMs',
        'elapsedMs',
        'longOutage',
        'networkState',
        'readyState',
        'online',
        'sourceHost',
        'reconnecting',
    ];

    /**
     * Context keys on every beacon, by the type validation accepts.
     *
     * @var array<string, 'string'|'boolean'>
     */
    public const CONTEXT = [
        'videoTitle' => 'string',
        'isLive' => 'boolean',
        'playerVersion' => 'string',
        'playerName' => 'string',
        'browser' => 'string',
        'os' => 'string',
        'deviceType' => 'string',
        'screenSize' => 'string',
        'playerSize' => 'string',
        'connectionType' => 'string',
    ];

    /**
     * Beacon keys including the signals contract, by the type validation accepts.
     * `scalar` is a string or a number (errorCode is either).
     *
     * @var array<string, 'numeric'|'string'|'boolean'|'scalar'>
     */
    public const FIELDS = [
        // Signals: intervals, page context, structured errors and privacy.
        'anonymous' => 'boolean',
        'pageUrl' => 'string',
        'referrerOrigin' => 'string',
        'pageLoadToInitMs' => 'numeric',
        'playerInitMs' => 'numeric',
        'qoeVersion' => 'numeric',
        'errorCategory' => 'string',
        'errorSeverity' => 'string',
        'httpStatus' => 'numeric',
        'mediaErrorCode' => 'numeric',
        'attempts' => 'numeric',
        'retriesExhausted' => 'boolean',
        'reconnectExhausted' => 'boolean',
        'timedOut' => 'boolean',
        'warningCount' => 'numeric',
        'fatalErrorCategory' => 'string',
        'segmentCount' => 'numeric',
        'segmentBytes' => 'numeric',
        'segmentLoadAvgMs' => 'numeric',
        'segmentLoadMaxMs' => 'numeric',
        'segmentErrors' => 'numeric',
        'segmentThroughputBps' => 'numeric',
        'decodedFrames' => 'numeric',
        'droppedFrames' => 'numeric',

        // Player 1.22.0: cumulative counters on heartbeat and both viewEnds
        // (dvrTime on live views only), reconnecting/recovered event data, and
        // error context. `duration` on recovered is the outage in ms.
        'elementSeekCount' => 'numeric',
        'reconnectCount' => 'numeric',
        'reconnectDuration' => 'numeric',
        'dvrTime' => 'numeric',
        'attempt' => 'numeric',
        'delayMs' => 'numeric',
        'elapsedMs' => 'numeric',
        'longOutage' => 'boolean',
        'networkState' => 'numeric',
        'readyState' => 'numeric',
        'online' => 'boolean',
        'sourceHost' => 'string',
        'reconnecting' => 'boolean',

        // every beacon (player 1.19.3)
        'beaconSeq' => 'numeric',
        // seeking
        'seekSource' => 'string',
        // videoStart, heartbeat, viewEnd
        'startupTime' => 'numeric',
        'watchTime' => 'numeric',
        'playTime' => 'numeric',
        'currentTime' => 'numeric',
        'duration' => 'numeric',
        'rebufferCount' => 'numeric',
        'rebufferDuration' => 'numeric',
        'avgBitrate' => 'numeric',
        'qoeScore' => 'numeric',
        // seeking, rebufferEnd, qualityChange
        'seekCount' => 'numeric',
        'seekTo' => 'numeric',
        'totalRebufferTime' => 'numeric',
        'bitrate' => 'numeric',
        'width' => 'numeric',
        'height' => 'numeric',
        'auto' => 'boolean',
        // error
        'errorType' => 'string',
        'errorMessage' => 'string',
        'errorCode' => 'scalar',
        'fatal' => 'boolean',
        // viewEnd
        'rebufferRatio' => 'numeric',
        'maxBitrate' => 'numeric',
        'qualityChanges' => 'numeric',
        'pauseCount' => 'numeric',
        'pauseDuration' => 'numeric',
        'errorCount' => 'numeric',
        'exitType' => 'string',
        'completionRate' => 'numeric',
        // live latency summary (heartbeat and viewEnd, live only)
        'liveLatencySamples' => 'numeric',
        'liveLatencyMean' => 'numeric',
        'liveLatencyP95' => 'numeric',
        'liveLatencyMax' => 'numeric',
        'lowLatency' => 'boolean',
    ];

    /**
     * @param  int  $timestamp  client clock, epoch milliseconds
     * @param  array<string, string|bool>  $context  known context keys present and non-null
     * @param  array<string, int|float|string|bool|null>  $fields  known event keys, including explicit null qoeScore/qoeVersion
     * @param  array<string, mixed>  $custom  every key the package does not know
     * @param  string|null  $ip  the client address, only when beacons.store_ip is on
     * @param  array<string, mixed>  $server  server-owned keys, non-null values only
     * @param  list<string>  $owned  every key the server context owns, null-valued ones included
     * @param  string|null  $bodyHash  carried by the with*() methods; null hashes this beacon
     */
    public function __construct(
        public string $event,
        public int $timestamp,
        public string $viewId,
        public string $sessionId,
        public string $viewerId,
        public string $videoId,
        public array $context = [],
        public array $fields = [],
        public array $custom = [],
        public ?string $ip = null,
        public array $server = [],
        public array $owned = [],
        ?string $bodyHash = null,
    ) {
        // No null marker yet: the provenance is attached after construction.
        $this->bodyHash = $bodyHash ?? sha1((string) json_encode($this->browserKeys([]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Build from a decoded beacon body that has passed validation.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, ?string $ip = null): self
    {
        $context = [];
        $fields = [];
        $custom = [];
        $legacyCustom = [];
        $gaugeNulls = [];

        foreach ($data as $key => $value) {
            $key = (string) $key;

            if (in_array($key, self::IDENTITY, true)) {
                continue;
            }

            // Keep null score/version presence, but omit nulls from the legacy hash basis.
            if ($value === null) {
                if (in_array($key, ['qoeScore', 'qoeVersion'], true)) {
                    $fields[$key] = null;
                }

                if (in_array($key, self::GAUGE_NULL_KEYS, true)) {
                    $gaugeNulls[] = $key;
                }

                continue;
            }

            // Collect during receipt, not after splitting: custom dimensions can
            // precede, follow or interleave the promoted names in the old hash.
            if (in_array($key, self::LEGACY_HASH_CUSTOM, true)) {
                $legacyCustom[$key] = $value;
            }

            $type = self::CONTEXT[$key] ?? self::FIELDS[$key] ?? null;

            if ($type !== null && self::isType($value, $type)) {
                if (array_key_exists($key, self::CONTEXT)) {
                    $context[$key] = is_bool($value) ? $value : (string) $value;
                } else {
                    $fields[$key] = self::field($value);
                }

                continue;
            }

            // Unknown, or a known name with another type: the player spreads the
            // host's customDimensions after the context keys and before the event
            // data (index.ts ~210), so a dimension called `duration` or `fatal` on
            // an event that does not set it arrives under a known name. It is kept
            // as a custom dimension rather than refused.
            $custom[$key] = $value;
            $legacyCustom[$key] = $value;
        }

        $legacyBody = array_replace([
            'event' => (string) $data['event'],
            'timestamp' => (int) $data['timestamp'],
            'viewId' => (string) $data['viewId'],
            'sessionId' => (string) $data['sessionId'],
            'viewerId' => (string) $data['viewerId'],
            'videoId' => (string) $data['videoId'],
        ], $context, array_filter(array_diff_key($fields, array_flip(self::LEGACY_HASH_CUSTOM)), fn (mixed $value): bool => $value !== null), $legacyCustom);

        $payload = new self(
            event: (string) $data['event'],
            timestamp: (int) $data['timestamp'],
            viewId: (string) $data['viewId'],
            sessionId: (string) $data['sessionId'],
            viewerId: (string) $data['viewerId'],
            videoId: (string) $data['videoId'],
            context: $context,
            fields: $fields,
            custom: $custom,
            ip: $ip,
            bodyHash: sha1((string) json_encode($legacyBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        );

        if ($gaugeNulls !== []) {
            $payload->gaugeNulls = $gaugeNulls; // @phpstan-ignore property.readOnlyAssignNotInConstructor
        }

        return $payload;
    }

    /**
     * The value of a known context or event key, or null when it was absent.
     */
    public function get(string $key): int|float|string|bool|null
    {
        return $this->fields[$key] ?? $this->context[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->fields) || array_key_exists($key, $this->context);
    }

    /**
     * Whether the received beacon carried one of the two gauges, or their
     * `gaugeScale` marker, as an explicit null (a live viewEnd's
     * completionRate), as opposed to omitting it. False
     * for every payload parsed by an older package version or restored from a
     * queue written before this provenance existed: a null was dropped there,
     * and no presence claim is invented afterwards. The marker is a custom
     * dimension, so a server context that owns `gaugeScale` strips its null
     * as it strips a browser value.
     */
    public function explicitlyNullGauge(string $key): bool
    {
        return isset($this->gaugeNulls) && in_array($key, $this->gaugeNulls, true)
            && ($key !== 'gaugeScale' || ! in_array($key, $this->owned, true));
    }

    /**
     * A known key's value as a fresh parse of the received beacon would classify it:
     * get(), or, only for a payload restored from an older package's queue, the
     * value of a name promoted since that its classification left in $custom
     * (`reconnecting`, `errorSeverity`), captured when the job was restored. Decided
     * by provenance, never by name and type: a key a pipeline puts in $custom with
     * withCustom() stays a custom dimension, and server ownership does not remove a
     * captured browser value, just as it leaves a known field. Reads only; the
     * queued classification, $bodyHash and the event key are unchanged.
     */
    public function knownValue(string $key): int|float|string|bool|null
    {
        if ($this->has($key)) {
            return $this->get($key);
        }

        return isset($this->legacyKnown) ? ($this->legacyKnown[$key] ?? null) : null;
    }

    /**
     * Whether this is an error that ended its view. From player 1.22.0 a fatal error
     * the provider will reconnect from is sent with `fatal: true`, `errorSeverity:
     * 'warning'` and `reconnecting: true`, and the view stays open, so `fatal` alone
     * no longer means the view failed. `errorSeverity` decides when present; a player
     * without it (before 1.20.0) falls back to `fatal`. A reconnecting error is never
     * fatal. Read through knownValue(), so a job queued by an older package decides
     * the same as a fresh parse of the same body.
     */
    public function isFatalError(): bool
    {
        if ($this->event !== 'error' || $this->isReconnectingError()) {
            return false;
        }

        $severity = $this->knownValue('errorSeverity');

        return is_string($severity) ? $severity === 'fatal' : $this->knownValue('fatal') === true;
    }

    /**
     * Whether this is an error the provider is auto-reconnecting from (player 1.22.0).
     */
    public function isReconnectingError(): bool
    {
        return $this->event === 'error' && $this->knownValue('reconnecting') === true;
    }

    /**
     * The same beacon with its custom dimensions replaced, for a ProcessesBeacon
     * that redacts before storage. A key the server context owns is left out, one it
     * owns as null included.
     *
     * @param  array<string, mixed>  $custom
     */
    public function withCustom(array $custom): self
    {
        return (new self(
            $this->event, $this->timestamp, $this->viewId, $this->sessionId, $this->viewerId,
            $this->videoId, $this->context, $this->fields, array_diff_key($custom, array_flip($this->owned)),
            $this->ip, $this->server, $this->owned, $this->bodyHash,
        ))->carryLegacy($this);
    }

    /**
     * The same beacon with its server context replaced: the fields the host asserts
     * (beacons.context, or a ProcessesBeacon step). Every key given is server-owned
     * on this beacon and removed from the custom dimensions, so a browser cannot
     * spoof it; a non-null value is kept in $server, a null one only strips.
     *
     * A key that is also a known context or event name (`duration`, `isLive`) is
     * allowed, but it goes only to the server map and the raw log, never to that
     * key's own column, which keeps the browser's value.
     *
     * @param  array<array-key, mixed>  $server
     *
     * @throws InvalidBeaconContextException for one of the six identity keys or an empty key
     */
    public function withServer(array $server): self
    {
        $custom = $this->custom;
        $kept = [];
        $owned = [];

        foreach ($server as $key => $value) {
            // PHP makes a numeric-string key an int; it is still the name "5".
            $key = (string) $key;

            if ($key === '') {
                throw InvalidBeaconContextException::invalidKey($key);
            }

            if (in_array($key, self::IDENTITY, true)) {
                throw InvalidBeaconContextException::reservedKey($key);
            }

            unset($custom[$key]);
            $owned[] = $key;

            if ($value !== null) {
                $kept[$key] = $value;
            }
        }

        return (new self(
            $this->event, $this->timestamp, $this->viewId, $this->sessionId, $this->viewerId,
            $this->videoId, $this->context, $this->fields, $custom, $this->ip, $kept, $owned,
            $this->bodyHash,
        ))->carryLegacy($this);
    }

    /**
     * The same beacon with its client address replaced or removed.
     */
    public function withIp(?string $ip): self
    {
        return (new self(
            $this->event, $this->timestamp, $this->viewId, $this->sessionId, $this->viewerId,
            $this->videoId, $this->context, $this->fields, $this->custom, $ip, $this->server,
            $this->owned, $this->bodyHash,
        ))->carryLegacy($this);
    }

    /**
     * The beacon as stored: the browser's keys, then the server context last, so a
     * server-owned value wins a name the browser also used. What the raw event log
     * and the fake's ledger record, after any pipeline step, so a redaction reaches
     * the raw log too. Explicit null qoeScore/qoeVersion and an explicit null
     * `gaugeScale` marker are retained; other null keys are left out.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_replace($this->browserArray(), $this->server);
    }

    /**
     * The beacon as the browser sent it, after any redaction: identity, the known keys
     * present, then custom dimensions, without the server context. $bodyHash keeps
     * the legacy classification order as received, not necessarily this array's hash.
     *
     * @return array<string, mixed>
     */
    public function browserArray(): array
    {
        return $this->browserKeys($this->nullGaugeScale());
    }

    /**
     * browserArray() with the given null marker between the known keys and the
     * custom dimensions, so a custom marker replaces it.
     *
     * @param  array<string, null>  $nullMarker
     * @return array<string, mixed>
     */
    private function browserKeys(array $nullMarker): array
    {
        // array_replace, not `...`: spreading renumbers integer keys, so a custom
        // dimension named "5" (PHP makes numeric-string keys ints) would become 0.
        return array_replace([
            'event' => $this->event,
            'timestamp' => $this->timestamp,
            'viewId' => $this->viewId,
            'sessionId' => $this->sessionId,
            'viewerId' => $this->viewerId,
            'videoId' => $this->videoId,
        ], $this->context, $this->fields, $nullMarker, $this->custom);
    }

    /**
     * An explicit null marker, kept so the raw log tells it from an omitted
     * one: the gauges backfill reads the marker from there. A marker in the
     * custom dimensions (a pipeline may set one) replaces it.
     *
     * @return array<string, null>
     */
    private function nullGaugeScale(): array
    {
        return $this->explicitlyNullGauge('gaugeScale') ? ['gaugeScale' => null] : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        // Older packages restore by named keys and ignore the marker and the map.
        return [...get_object_vars($this), 'serialVersion' => self::SERIAL_VERSION];
    }

    /**
     * Restores a queued beacon. A job queued by an older version of the package lacks
     * the newer properties; they get the values a fresh beacon would have, so jobs in
     * the queue across an upgrade still process.
     *
     * Its classification is kept as queued. Only when the data lacks this version's
     * marker are the known-typed values of names promoted since captured for
     * knownValue(); custom, get() and the hash are untouched.
     *
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        $server = (array) ($data['server'] ?? []);

        $this->__construct(
            event: (string) $data['event'],
            timestamp: (int) $data['timestamp'],
            viewId: (string) $data['viewId'],
            sessionId: (string) $data['sessionId'],
            viewerId: (string) $data['viewerId'],
            videoId: (string) $data['videoId'],
            context: (array) ($data['context'] ?? []),
            fields: (array) ($data['fields'] ?? []),
            custom: (array) ($data['custom'] ?? []),
            ip: isset($data['ip']) ? (string) $data['ip'] : null,
            server: $server,
            owned: array_values(array_map('strval', (array) ($data['owned'] ?? array_keys($server)))),
            bodyHash: isset($data['bodyHash']) ? (string) $data['bodyHash'] : null,
        );

        $legacy = [];

        if (isset($data['serialVersion'])) {
            // Written by this version: only a map it captured itself, carried as is.
            foreach ((array) ($data['legacyKnown'] ?? []) as $key => $value) {
                if (is_string($key) && is_scalar($value)) {
                    $legacy[$key] = $value;
                }
            }
        } else {
            // Queued by an older package: promoted names it classified as custom.
            foreach (self::LEGACY_HASH_CUSTOM as $key) {
                $value = $this->custom[$key] ?? null;

                if (! $this->has($key) && self::isType($value, self::FIELDS[$key])) {
                    $legacy[$key] = self::field($value);
                }
            }
        }

        if ($legacy !== []) {
            $this->legacyKnown = $legacy;
        }

        // A queue written before this provenance existed has no gaugeNulls key:
        // its gauge nulls were dropped at parse time and stay absent.
        $gaugeNulls = [];

        foreach ((array) ($data['gaugeNulls'] ?? []) as $key) {
            if (is_string($key) && in_array($key, self::GAUGE_NULL_KEYS, true)) {
                $gaugeNulls[] = $key;
            }
        }

        if ($gaugeNulls !== []) {
            $this->gaugeNulls = array_values(array_unique($gaugeNulls));
        }
    }

    /** Give a with*() copy this payload's captured legacy values and gauge provenance, if it has any. */
    private function carryLegacy(self $from): self
    {
        if (isset($from->legacyKnown)) {
            $this->legacyKnown = $from->legacyKnown; // @phpstan-ignore property.readOnlyAssignNotInConstructor
        }

        if (isset($from->gaugeNulls)) {
            $this->gaugeNulls = $from->gaugeNulls; // @phpstan-ignore property.readOnlyAssignNotInConstructor
        }

        return $this;
    }

    /**
     * Whether a value has the type a known key is listed with.
     */
    public static function isType(mixed $value, string $type): bool
    {
        return match ($type) {
            'numeric' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'string' => is_string($value),
            'scalar' => is_string($value) || is_int($value) || is_float($value),
            default => false,
        };
    }

    private static function field(mixed $value): int|float|string|bool
    {
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
