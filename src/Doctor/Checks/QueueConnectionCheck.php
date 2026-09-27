<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Doctor\Checks;

use Hei\ScarlettPlayer\Doctor\Check;
use Hei\ScarlettPlayer\Doctor\CheckResult;
use Hei\ScarlettPlayer\Doctor\CheckStatus;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;

/**
 * The beacon and clip queue connections resolve. A queue name selects nothing about
 * asynchrony: `sync` outside the local environment renders clips inside the request,
 * so it warns. `database` works but writes every job row synchronously.
 */
class QueueConnectionCheck implements Check
{
    public function __construct(
        private readonly Repository $config,
        private readonly Application $app,
    ) {}

    public function name(): string
    {
        return 'queue connections';
    }

    public function run(): CheckResult
    {
        $status = CheckStatus::Pass;
        $notes = [];

        foreach (['beacons', 'clips'] as $module) {
            if (! $this->config->get("scarlett-player.{$module}.enabled")) {
                $notes[] = "{$module}: off";

                continue;
            }

            $name = $this->config->get("scarlett-player.{$module}.connection") ?? $this->config->get('queue.default');
            $driver = is_string($name) ? $this->config->get("queue.connections.{$name}.driver") : null;

            if (! is_string($name) || ! is_string($driver)) {
                $status = CheckStatus::Fail;
                $notes[] = "{$module}: connection [".(is_string($name) ? $name : 'none').'] is not defined in queue.connections';

                continue;
            }

            if ($driver === 'sync' && ! $this->app->environment('local')) {
                $status = $status === CheckStatus::Fail ? $status : CheckStatus::Warn;
                $notes[] = "{$module}: [{$name}] is sync outside local, jobs run inside the request";

                continue;
            }

            $notes[] = $driver === 'database'
                ? "{$module}: [{$name}] (database writes each job row synchronously)"
                : "{$module}: [{$name}] ({$driver})";
        }

        return new CheckResult($status, implode('; ', $notes));
    }
}
