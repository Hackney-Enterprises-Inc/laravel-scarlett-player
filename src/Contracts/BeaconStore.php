<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Contracts;

use Hei\ScarlettPlayer\Data\BeaconPayload;

/**
 * Where processed analytics beacons are written. The package ships
 * EloquentBeaconStore (the default), NullBeaconStore and, for tests,
 * Testing\FakeBeaconStore; a host binds its own for another backend.
 *
 * A driver must honour the same contract as EloquentBeaconStore:
 *
 * - Idempotent: the same payload delivered twice has the effect of once. Queue
 *   redelivery, a bfcache restore and the plugin's pagehide plus beforeunload pair
 *   all deliver duplicates, and none of them is an error.
 * - Order-independent: keepalive fetch and sendBeacon guarantee no order, so the
 *   result of a set of beacons does not depend on the order they are recorded in.
 * - Merged per field, never per event: set-once, monotonic, true-wins (isLive: any
 *   true sets it, false only fills, nothing clears it), latest-by-timestamp, and
 *   fill-if-absent over all of them (a key absent from the payload, or null, never
 *   touches what is stored). The two viewEnd variants share one event name.
 * - Transitions, not receipts: ViewStarted when the view is first stored, ViewEnded
 *   when it first gains an end, PlaybackErrorReported when an error is first stored.
 *   A duplicate delivery fires none of them again.
 */
interface BeaconStore
{
    public function record(BeaconPayload $payload): void;
}
