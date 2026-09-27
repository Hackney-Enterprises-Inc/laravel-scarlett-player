<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Jobs;

use Hei\ScarlettPlayer\Contracts\BeaconStore;
use Hei\ScarlettPlayer\Contracts\ProcessesBeacon;
use Hei\ScarlettPlayer\Data\BeaconPayload;
use Hei\ScarlettPlayer\Events\BeaconReceived;
use Hei\ScarlettPlayer\Exceptions\InvalidBeaconPipelineException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Stores one beacon: runs the beacons.pipeline steps, then, unless a step dropped
 * it, fires BeaconReceived with what the steps let through and hands that to the
 * bound BeaconStore.
 *
 * The payload rides in the job. Redelivery is safe because the store is idempotent,
 * which is also why a failed attempt can simply be retried.
 */
class ProcessBeacon implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 30;

    public int $tries = 5;

    public function __construct(
        public readonly BeaconPayload $payload,
    ) {}

    /**
     * Seconds to wait before each retry.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(BeaconStore $store, Dispatcher $events, Container $container, Repository $config): void
    {
        $payload = $this->throughPipeline($this->payload, $container, (array) $config->get('scarlett-player.beacons.pipeline', []));

        // A dropped beacon is announced to nobody, and listeners (queued ones
        // serialise the event) only ever see what a redacting step let through.
        if ($payload === null) {
            return;
        }

        $events->dispatch(new BeaconReceived($payload));

        $store->record($payload);
    }

    /**
     * @param  array<array-key, mixed>  $classes  class names resolved from the container
     */
    private function throughPipeline(BeaconPayload $payload, Container $container, array $classes): ?BeaconPayload
    {
        $steps = [];

        foreach ($classes as $class) {
            $step = $container->make((string) $class);

            if (! $step instanceof ProcessesBeacon) {
                throw InvalidBeaconPipelineException::notAStep((string) $class);
            }

            $steps[] = $step;
        }

        return $this->step($steps, 0, $payload);
    }

    /**
     * @param  list<ProcessesBeacon>  $steps
     */
    private function step(array $steps, int $index, BeaconPayload $payload): ?BeaconPayload
    {
        if (! isset($steps[$index])) {
            return $payload;
        }

        return $steps[$index]->handle($payload, fn (BeaconPayload $next): ?BeaconPayload => $this->step($steps, $index + 1, $next));
    }
}
