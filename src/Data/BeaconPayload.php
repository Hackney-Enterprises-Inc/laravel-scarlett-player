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
 * A key present with a null value is treated as absent, known or custom, so it
 * never touches what is stored (the fill-if-absent rule).
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

    /** Keys every beacon carries. The four ids are required. */
    public const IDENTITY = ['event', 'timestamp', 'viewId', 'sessionId', 'viewerId', 'videoId'];

    /**
     * Frozen v0.3.0 promotions: these names remain custom in the v0.2.1 hash basis,
     * regardless of type. Do not remove them when changing DTO classification.
     */
    private const LEGACY_HASH_CUSTOM = ['beaconSeq', 'seekSource'];

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
     * Beacon keys shipped through player 1.19.3, by the type validation accepts.
     * `scalar` is a string or a number (errorCode is either).
     *
     * @var array<string, 'numeric'|'string'|'boolean'|'scalar'>
     */
    public const FIELDS = [
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
     * @param  array<string, int|float|string|bool>  $fields  known event keys present and non-null
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
        $this->bodyHash = $bodyHash ?? sha1((string) json_encode($this->browserArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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

        foreach ($data as $key => $value) {
            $key = (string) $key;

            if (in_array($key, self::IDENTITY, true)) {
                continue;
            }

            // Present-but-null is absent, for known keys and custom dimensions alike.
            if ($value === null) {
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
        ], $context, array_diff_key($fields, array_flip(self::LEGACY_HASH_CUSTOM)), $legacyCustom);

        return new self(
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
     * The same beacon with its custom dimensions replaced, for a ProcessesBeacon
     * that redacts before storage. A key the server context owns is left out, one it
     * owns as null included.
     *
     * @param  array<string, mixed>  $custom
     */
    public function withCustom(array $custom): self
    {
        return new self(
            $this->event, $this->timestamp, $this->viewId, $this->sessionId, $this->viewerId,
            $this->videoId, $this->context, $this->fields, array_diff_key($custom, array_flip($this->owned)),
            $this->ip, $this->server, $this->owned, $this->bodyHash,
        );
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

        return new self(
            $this->event, $this->timestamp, $this->viewId, $this->sessionId, $this->viewerId,
            $this->videoId, $this->context, $this->fields, $custom, $this->ip, $kept, $owned,
            $this->bodyHash,
        );
    }

    /**
     * The same beacon with its client address replaced or removed.
     */
    public function withIp(?string $ip): self
    {
        return new self(
            $this->event, $this->timestamp, $this->viewId, $this->sessionId, $this->viewerId,
            $this->videoId, $this->context, $this->fields, $this->custom, $ip, $this->server,
            $this->owned, $this->bodyHash,
        );
    }

    /**
     * The beacon as stored: the browser's keys, then the server context last, so a
     * server-owned value wins a name the browser also used. What the raw event log
     * and the fake's ledger record, after any pipeline step, so a redaction reaches
     * the raw log too. Known keys sent as null are left out.
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
        // array_replace, not `...`: spreading renumbers integer keys, so a custom
        // dimension named "5" (PHP makes numeric-string keys ints) would become 0.
        return array_replace([
            'event' => $this->event,
            'timestamp' => $this->timestamp,
            'viewId' => $this->viewId,
            'sessionId' => $this->sessionId,
            'viewerId' => $this->viewerId,
            'videoId' => $this->videoId,
        ], $this->context, $this->fields, $this->custom);
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return get_object_vars($this);
    }

    /**
     * Restores a queued beacon. A job queued by an older version of the package lacks
     * the newer properties; they get the values a fresh beacon would have, so jobs in
     * the queue across an upgrade still process.
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
