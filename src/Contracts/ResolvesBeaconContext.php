<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Contracts;

use Hei\ScarlettPlayer\Data\BeaconPayload;
use Illuminate\Http\Request;

/**
 * Named by beacons.context and called by the beacon route after the body passes
 * validation and before the beacon is queued: the queued job has no request, so the
 * signed-in user, the tenant resolved from the host and anything else request-bound
 * must be read here.
 */
interface ResolvesBeaconContext
{
    /**
     * Fields the host asserts about this beacon from the request: a user id, a
     * tenant, a plan. Every key returned is server-owned on this beacon: it is
     * removed from the browser's custom dimensions, and a non-null value is stored
     * under scarlett_views.server (merged per key, newest beacon winning). Return
     * null for a key to strip the browser's copy without storing anything.
     *
     * Runs on the request path for every beacon, so keep it cheap and never let it
     * throw for a guest: the beacon route has no auth middleware, guests beacon too.
     * An exception is not caught: the beacon is answered 500 and lost.
     *
     * @return array<string, mixed>
     */
    public function resolve(Request $request, BeaconPayload $payload): array;
}
