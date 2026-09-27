<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Doctor\Checks;

use Hei\ScarlettPlayer\Doctor\Check;
use Hei\ScarlettPlayer\Doctor\CheckResult;
use Illuminate\Contracts\Config\Repository;

/**
 * The beacon API key is set and clean. Reported by naming the key, never the value:
 * a trailing newline from a .env file is the classic way to get a key that never
 * matches.
 */
class BeaconKeyCheck implements Check
{
    public function __construct(
        private readonly Repository $config,
    ) {}

    public function name(): string
    {
        return 'beacon key';
    }

    public function run(): CheckResult
    {
        if (! $this->config->get('scarlett-player.routes.beacons') || ! $this->config->get('scarlett-player.beacons.enabled')) {
            return CheckResult::pass('beacons are off; no key needed');
        }

        $key = $this->config->get('scarlett-player.beacons.key');

        if (! is_string($key) || $key === '') {
            return CheckResult::fail('beacons.key is empty: set SCARLETT_BEACON_KEY');
        }

        if (trim($key) !== $key) {
            return CheckResult::fail('beacons.key has leading or trailing whitespace; the player will never match it');
        }

        return CheckResult::pass('beacons.key is set');
    }
}
