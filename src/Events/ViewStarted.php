<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Events;

use Hei\ScarlettPlayer\Data\BeaconPayload;

/**
 * Fired once, when a view is first stored. The first beacon stored for the view is
 * attached; it is not necessarily the viewStart, because beacons arrive in any order.
 */
final class ViewStarted
{
    public function __construct(
        public readonly string $viewId,
        public readonly BeaconPayload $payload,
    ) {}
}
