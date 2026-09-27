<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Stores;

use Hei\ScarlettPlayer\Contracts\BeaconStore;
use Hei\ScarlettPlayer\Data\BeaconPayload;

/**
 * Discards every beacon. The store for hosts that want the ingest route answered
 * without keeping anything (beacons.store = null).
 */
class NullBeaconStore implements BeaconStore
{
    public function record(BeaconPayload $payload): void
    {
        //
    }
}
