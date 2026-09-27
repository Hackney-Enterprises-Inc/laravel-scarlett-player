<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Doctor\Checks;

use Hei\ScarlettPlayer\Doctor\Check;
use Hei\ScarlettPlayer\Doctor\CheckResult;
use Illuminate\Contracts\Config\Repository;

/**
 * Beacons and clips do not share a queue. QueueConnectionCheck proves each
 * connection resolves; this one catches the configuration where a five-minute
 * ffmpeg render sits in front of ten thousand heartbeats, and names the worker
 * command the beacon queue needs.
 */
class BeaconQueueCheck implements Check
{
    public function __construct(
        private readonly Repository $config,
    ) {}

    public function name(): string
    {
        return 'beacon queue';
    }

    public function run(): CheckResult
    {
        if (! $this->config->get('scarlett-player.beacons.enabled')) {
            return CheckResult::pass('beacons are off');
        }

        [$connection, $queue] = $this->resolved('beacons');

        if ($this->config->get('scarlett-player.clips.enabled') && [$connection, $queue] === $this->resolved('clips')) {
            return CheckResult::warn("beacons and clips share [{$connection}] queue [{$queue}]: set beacons.queue and clips.queue apart so renders never delay heartbeats");
        }

        return CheckResult::pass("run: php artisan queue:work {$connection} --queue={$queue}");
    }

    /**
     * @return array{string, string}
     */
    private function resolved(string $module): array
    {
        $connection = $this->config->get("scarlett-player.{$module}.connection") ?? $this->config->get('queue.default');
        $connection = is_string($connection) ? $connection : 'default';
        $queue = $this->config->get("scarlett-player.{$module}.queue");

        if (! is_string($queue) || $queue === '') {
            $queue = $this->config->get("queue.connections.{$connection}.queue");
        }

        return [$connection, is_string($queue) && $queue !== '' ? $queue : 'default'];
    }
}
