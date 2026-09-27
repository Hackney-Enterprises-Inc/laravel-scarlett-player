<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Http\Controllers;

use Hei\ScarlettPlayer\Http\Requests\BeaconRequest;
use Hei\ScarlettPlayer\Jobs\ProcessBeacon;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST {prefix}/beacons. Checks the body, queues ProcessBeacon on the beacons
 * connection and queue, and answers 204 before any aggregation. The player ignores
 * the response entirely, so a slow or failing store costs the host, never the viewer.
 *
 * With beacons.enabled off the beacon is answered 204 and discarded.
 */
class BeaconController
{
    public function __invoke(Request $request, Repository $config, Dispatcher $bus): Response|JsonResponse
    {
        $beacon = new BeaconRequest($request, $config);

        if (! $beacon->passes()) {
            return new JsonResponse($beacon->failure(), $beacon->status());
        }

        if ($config->get('scarlett-player.beacons.enabled')) {
            $connection = $config->get('scarlett-player.beacons.connection');
            $queue = $config->get('scarlett-player.beacons.queue');

            $bus->dispatch(
                (new ProcessBeacon($beacon->payload()))
                    ->onConnection(is_string($connection) && $connection !== '' ? $connection : null)
                    ->onQueue(is_string($queue) && $queue !== '' ? $queue : null),
            );
        }

        return new Response(status: 204);
    }
}
