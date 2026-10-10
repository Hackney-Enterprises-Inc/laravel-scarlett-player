<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Beacons;

/**
 * One normalized gauge measurement.
 *
 * $value is the canonical 0..1 ratio when $available is true, otherwise null.
 * $reason names the decision: how the scale was established (an explicit
 * marker, the audited registry) with a `_clamped` suffix when a finite input
 * exceeded the contract bounds, or why the measurement is unavailable
 * (explicit null, invalid marker, unknown scale, non-finite input).
 */
final readonly class GaugeReading
{
    public function __construct(
        public ?float $value,
        public bool $available,
        public string $reason,
    ) {}
}
