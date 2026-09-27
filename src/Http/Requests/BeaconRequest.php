<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Http\Requests;

use Hei\ScarlettPlayer\Data\BeaconPayload;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Reads and checks one beacon body before it is queued. Cheap by design: the only
 * things that can refuse a beacon are the body not being one JSON object, and the
 * event name, the four ids or the timestamp being missing or malformed. A known key
 * of the wrong type moves to the custom dimensions (BeaconPayload::fromArray()), and
 * a long string is truncated by the store, never refused: a 422 there would drop
 * every beacon of a video with a long title, the unload viewEnd included.
 *
 * The body is decoded as JSON whatever the Content-Type says: sendBeacon posts a
 * Blob typed application/json, but the ingest should not depend on a header to parse
 * a body it was sent. Unknown keys are never checked or rejected. A JSON array is
 * refused by name: player 1.16.x sends one event per request, and a batched body is
 * a breaking change that needs a package major (plan, Wire-contract versioning).
 *
 * A plain class rather than a FormRequest, so the package needs neither
 * illuminate/foundation nor illuminate/validation.
 */
class BeaconRequest
{
    /** Longest id or event name accepted; the columns are sized to it. */
    public const MAX_ID = 191;

    /** @var array<string, mixed>|null */
    private ?array $body = null;

    /** @var array<string, string> */
    private array $errors = [];

    private ?string $refusal = null;

    private int $status = 422;

    public function __construct(
        private readonly Request $request,
        private readonly Repository $config,
    ) {
        $this->check();
    }

    public function passes(): bool
    {
        return $this->refusal === null && $this->errors === [];
    }

    /**
     * The status to refuse with: 413 for a body over beacons.max_body_bytes, else 422.
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * Why the body was refused, for the error response.
     *
     * @return array{message: string, errors?: array<string, string>}
     */
    public function failure(): array
    {
        if ($this->refusal !== null) {
            return ['message' => $this->refusal];
        }

        return ['message' => 'The beacon is invalid.', 'errors' => $this->errors];
    }

    /**
     * The checked beacon. The client address is attached only when beacons.store_ip
     * is on, and truncated first when beacons.anonymize_ip is, so a full address never
     * reaches the queue.
     */
    public function payload(): BeaconPayload
    {
        return BeaconPayload::fromArray($this->body ?? [], $this->clientIp());
    }

    private function check(): void
    {
        $content = $this->request->getContent();
        $limit = $this->config->get('scarlett-player.beacons.max_body_bytes');

        if (is_int($limit) && $limit > 0 && strlen($content) > $limit) {
            $this->status = 413;
            $this->refusal = "A beacon body is at most {$limit} bytes.";

            return;
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded) || $decoded === [] || array_is_list($decoded)) {
            $this->refusal = is_array($decoded) && $decoded !== [] && array_is_list($decoded)
                ? 'A beacon body is one JSON object; batched beacons are not supported.'
                : 'A beacon body is one JSON object.';

            return;
        }

        /** @var array<string, mixed> $decoded */
        $this->body = $decoded;

        foreach (['event', 'viewId', 'sessionId', 'viewerId', 'videoId'] as $key) {
            $value = $decoded[$key] ?? null;

            if (! is_string($value) || $value === '' || mb_strlen($value) > self::MAX_ID) {
                $this->errors[$key] = "{$key} is required: a string of 1 to ".self::MAX_ID.' characters.';
            }
        }

        $timestamp = $decoded['timestamp'] ?? null;

        if (! is_int($timestamp) || $timestamp < 0) {
            $this->errors['timestamp'] = 'timestamp is required: epoch milliseconds as a non-negative integer.';
        }
    }

    private function clientIp(): ?string
    {
        if (! $this->config->get('scarlett-player.beacons.store_ip')) {
            return null;
        }

        $ip = $this->request->ip();

        if (! is_string($ip) || $ip === '') {
            return null;
        }

        return $this->config->get('scarlett-player.beacons.anonymize_ip') ? IpUtils::anonymize($ip) : $ip;
    }
}
