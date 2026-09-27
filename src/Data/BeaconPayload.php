<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Data;

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
 */
final readonly class BeaconPayload
{
    /** Keys every beacon carries. The four ids are required. */
    public const IDENTITY = ['event', 'timestamp', 'viewId', 'sessionId', 'viewerId', 'videoId'];

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
     * Event-specific keys shipped in player 1.16.x, by the type validation accepts.
     * `scalar` is a string or a number (errorCode is either).
     *
     * @var array<string, 'numeric'|'string'|'boolean'|'scalar'>
     */
    public const FIELDS = [
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
    ) {}

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

        foreach ($data as $key => $value) {
            $key = (string) $key;

            if (in_array($key, self::IDENTITY, true)) {
                continue;
            }

            // Present-but-null is absent, for known keys and custom dimensions alike.
            if ($value === null) {
                continue;
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
        }

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
     * that redacts before storage.
     *
     * @param  array<string, mixed>  $custom
     */
    public function withCustom(array $custom): self
    {
        return new self(
            $this->event, $this->timestamp, $this->viewId, $this->sessionId, $this->viewerId,
            $this->videoId, $this->context, $this->fields, $custom, $this->ip,
        );
    }

    /**
     * The same beacon with its client address replaced or removed.
     */
    public function withIp(?string $ip): self
    {
        return new self(
            $this->event, $this->timestamp, $this->viewId, $this->sessionId, $this->viewerId,
            $this->videoId, $this->context, $this->fields, $this->custom, $ip,
        );
    }

    /**
     * The beacon as stored: identity, the known keys present, then custom dimensions.
     * What the raw event log and the fake's ledger record, after any pipeline step,
     * so a redaction reaches the raw log too. Known keys sent as null are left out.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
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
