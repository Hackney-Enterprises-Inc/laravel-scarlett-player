<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Doctor\Checks;

use Hei\ScarlettPlayer\Contracts\BeaconStore;
use Hei\ScarlettPlayer\Doctor\Check;
use Hei\ScarlettPlayer\Doctor\CheckResult;
use Hei\ScarlettPlayer\Stores\NullBeaconStore;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * The BeaconStore binding resolves, and says which store is receiving beacons.
 */
class BeaconStoreCheck implements Check
{
    public function __construct(
        private readonly Container $container,
        private readonly Repository $config,
    ) {}

    public function name(): string
    {
        return 'beacon store';
    }

    public function run(): CheckResult
    {
        try {
            $store = $this->store();
        } catch (Throwable $e) {
            return CheckResult::fail('BeaconStore does not resolve: '.$e->getMessage());
        }

        if ($store instanceof NullBeaconStore && $this->config->get('scarlett-player.beacons.enabled')) {
            return CheckResult::warn('['.NullBeaconStore::class.'] is bound: beacons are accepted and discarded');
        }

        return CheckResult::pass('['.$store::class.'] is bound');
    }

    /**
     * Whatever the host bound, seen through the contract: the binding is the thing
     * under test, so nothing about its class is assumed here.
     */
    private function store(): BeaconStore
    {
        return $this->container->make(BeaconStore::class);
    }
}
