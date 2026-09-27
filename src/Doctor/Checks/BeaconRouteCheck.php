<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Doctor\Checks;

use Hei\ScarlettPlayer\Doctor\Check;
use Hei\ScarlettPlayer\Doctor\CheckResult;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Routing\Router;

/**
 * The beacon route is registered, and says the URL to give the analytics plugin.
 */
class BeaconRouteCheck implements Check
{
    public function __construct(
        private readonly Repository $config,
        private readonly Router $router,
    ) {}

    public function name(): string
    {
        return 'beacon route';
    }

    public function run(): CheckResult
    {
        if (! $this->config->get('scarlett-player.routes.beacons')) {
            return CheckResult::pass('routes.beacons is off; no beacon route');
        }

        $this->router->getRoutes()->refreshNameLookups();
        $route = $this->router->getRoutes()->getByName('scarlett.beacons.store');

        if ($route === null) {
            return CheckResult::fail('routes.beacons is on but [scarlett.beacons.store] is not registered (a cached route file from before the install?)');
        }

        if (! $this->config->get('scarlett-player.beacons.enabled')) {
            return CheckResult::warn("POST /{$route->uri()} is registered but beacons.enabled is off: beacons are answered and discarded");
        }

        return CheckResult::pass("POST /{$route->uri()}: give the analytics plugin this path on an https origin as beaconUrl");
    }
}
