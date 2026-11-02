<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Data;

/**
 * The clock-skew correction of one beacon (0.9.0): how far the viewer's clock sits
 * from the server's, read off the beacon alone.
 *
 * The player stamps `sentAt` (its own clock) when the transport dispatches the body;
 * the controller stamps the receipt time (the server's clock) when the request
 * arrives. Their difference is the skew plus network latency, and adding it to the
 * beacon's own `timestamp` puts that event on the server clock. It is a pure function
 * of the beacon, so delivery order and worker count cannot change it.
 *
 * Source is `sentAt` (corrected), `none` (no usable sentAt or receipt time, stored as
 * received) or `rejected` (an offset beyond the cap, stored as received).
 */
final readonly class ClockCorrection
{
    public const SOURCE_SENT_AT = 'sentAt';

    public const SOURCE_NONE = 'none';

    public const SOURCE_REJECTED = 'rejected';

    private function __construct(
        public ?int $offsetMs,
        public string $source,
    ) {}

    /** No correction: used when beacons.max_clock_offset is off. */
    public static function none(): self
    {
        return new self(null, self::SOURCE_NONE);
    }

    /**
     * @param  int  $capMs  the largest |offset| trusted, in milliseconds
     */
    public static function for(BeaconPayload $payload, int $capMs): self
    {
        $received = $payload->receivedAtMs();
        $sent = $payload->get('sentAt');

        if ($received === null || ! (is_int($sent) || is_float($sent)) || ! is_finite((float) $sent) || $sent <= 0) {
            return new self(null, self::SOURCE_NONE);
        }

        // Float arithmetic first: a huge sentAt must not wrap when cast to int.
        $offset = (float) $received - (float) $sent;

        if (abs($offset) > $capMs) {
            return new self(abs($offset) < 1.0e15 ? (int) round($offset) : null, self::SOURCE_REJECTED);
        }

        return new self((int) round($offset), self::SOURCE_SENT_AT);
    }

    /** The beacon's client timestamp on the server clock, or unchanged when not corrected. */
    public function correct(int $timestampMs): int
    {
        return $this->source === self::SOURCE_SENT_AT ? $timestampMs + (int) $this->offsetMs : $timestampMs;
    }
}
