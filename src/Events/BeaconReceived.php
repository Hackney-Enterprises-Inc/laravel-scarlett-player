<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Events;

use Hei\ScarlettPlayer\Data\BeaconPayload;

/**
 * Fired once per delivery of a beacon to ProcessBeacon, after the beacons.pipeline
 * steps and before the store, with the payload as the steps left it. A beacon a
 * step dropped fires nothing. Unlike the transition events it is NOT deduplicated:
 * a job the queue redelivers fires it again, and so does a duplicate beacon from
 * the player.
 */
final class BeaconReceived
{
    public function __construct(
        public readonly BeaconPayload $payload,
    ) {}
}
