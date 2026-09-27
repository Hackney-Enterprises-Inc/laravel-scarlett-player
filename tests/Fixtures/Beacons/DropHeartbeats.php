<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Tests\Fixtures\Beacons;

use Closure;
use Hei\ScarlettPlayer\Contracts\ProcessesBeacon;
use Hei\ScarlettPlayer\Data\BeaconPayload;

/** A pipeline step that drops every heartbeat before storage. */
final class DropHeartbeats implements ProcessesBeacon
{
    public function handle(BeaconPayload $payload, Closure $next): ?BeaconPayload
    {
        return $payload->event === 'heartbeat' ? null : $next($payload);
    }
}
