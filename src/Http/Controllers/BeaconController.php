<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Http\Controllers;

use Hei\ScarlettPlayer\Contracts\ResolvesBeaconContext;
use Hei\ScarlettPlayer\Data\BeaconPayload;
use Hei\ScarlettPlayer\Exceptions\InvalidBeaconContextException;
use Hei\ScarlettPlayer\Http\Requests\BeaconRequest;
use Hei\ScarlettPlayer\Jobs\ProcessBeacon;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST {prefix}/beacons. Checks the body, queues ProcessBeacon on the beacons
 * connection and queue, and answers 204 before any aggregation. The player ignores
 * the response entirely, so a slow or failing store costs the host, never the viewer.
 *
 * With beacons.enabled off the beacon is answered 204 and discarded.
 *
 * beacons.context runs here, after validation and before the dispatch, because the
 * request exists only here. Its exceptions are not caught: the host's handler
 * reports them, the beacon is answered 500 and nothing is queued.
 */
class BeaconController
{
    public function __invoke(Request $request, Repository $config, Dispatcher $bus, Container $container): Response|JsonResponse
    {
        $beacon = new BeaconRequest($request, $config);

        if (! $beacon->passes()) {
            return new JsonResponse($beacon->failure(), $beacon->status());
        }

        if ($config->get('scarlett-player.beacons.enabled')) {
            $connection = $config->get('scarlett-player.beacons.connection');
            $queue = $config->get('scarlett-player.beacons.queue');

            $payload = $this->withContext($beacon->payload(), $request, $config, $container);

            $bus->dispatch(
                (new ProcessBeacon($payload))
                    ->onConnection(is_string($connection) && $connection !== '' ? $connection : null)
                    ->onQueue(is_string($queue) && $queue !== '' ? $queue : null),
            );
        }

        return new Response(status: 204);
    }

    /**
     * The payload with the server context from beacons.context, or unchanged when none
     * is configured.
     */
    private function withContext(BeaconPayload $payload, Request $request, Repository $config, Container $container): BeaconPayload
    {
        $class = $config->get('scarlett-player.beacons.context');

        if (! is_string($class) || $class === '') {
            return $payload;
        }

        $resolver = $container->make($class);

        if (! $resolver instanceof ResolvesBeaconContext) {
            throw InvalidBeaconContextException::notAResolver($class);
        }

        return $payload->withServer($resolver->resolve($request, $payload));
    }
}
