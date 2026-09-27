<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Tests\Fixtures\Beacons;

use Closure;
use Hei\ScarlettPlayer\Contracts\ProcessesBeacon;
use Hei\ScarlettPlayer\Data\BeaconPayload;

/** A pipeline step that removes an `email` custom dimension. */
final class RedactEmail implements ProcessesBeacon
{
    public function handle(BeaconPayload $payload, Closure $next): ?BeaconPayload
    {
        $custom = $payload->custom;
        unset($custom['email']);

        return $next($payload->withCustom($custom));
    }
}
