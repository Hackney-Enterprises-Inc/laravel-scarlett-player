<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Contracts;

use Closure;
use Hei\ScarlettPlayer\Data\BeaconPayload;

/**
 * A step between the queue and the BeaconStore, listed in beacons.pipeline. It may
 * redact the payload (BeaconPayload::withCustom(), withIp()) and pass it on, or
 * return null to drop the beacon before anything is stored.
 */
interface ProcessesBeacon
{
    /**
     * @param  Closure(BeaconPayload): ?BeaconPayload  $next
     */
    public function handle(BeaconPayload $payload, Closure $next): ?BeaconPayload;
}
