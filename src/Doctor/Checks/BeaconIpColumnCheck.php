<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Doctor\Checks;

use Hei\ScarlettPlayer\Doctor\Check;
use Hei\ScarlettPlayer\Doctor\CheckResult;
use Hei\ScarlettPlayer\Stores\EloquentBeaconStore;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Throwable;

/**
 * beacons.store_ip matches the table. The migration adds scarlett_views.ip_address
 * only when store_ip is on at migration time; turned on later, the store skips the
 * address rather than failing every beacon, and this check says so.
 */
class BeaconIpColumnCheck implements Check
{
    public function __construct(
        private readonly Repository $config,
        private readonly ConnectionResolverInterface $database,
    ) {}

    public function name(): string
    {
        return 'beacon ip column';
    }

    public function run(): CheckResult
    {
        if (! $this->config->get('scarlett-player.beacons.store_ip')) {
            return CheckResult::pass('beacons.store_ip is off; no address is stored');
        }

        try {
            $connection = $this->database->connection();

            if (! $connection instanceof Connection) {
                return CheckResult::warn('the default database connection is not an Illuminate\\Database\\Connection; cannot inspect it');
            }

            $schema = $connection->getSchemaBuilder();

            if (! $schema->hasTable(EloquentBeaconStore::VIEWS)) {
                return CheckResult::warn('['.EloquentBeaconStore::VIEWS.'] does not exist yet: publish scarlett-migrations and migrate');
            }

            $present = $schema->hasColumn(EloquentBeaconStore::VIEWS, 'ip_address');
        } catch (Throwable $e) {
            return CheckResult::warn('could not inspect ['.EloquentBeaconStore::VIEWS.']: '.$e->getMessage());
        }

        if (! $present) {
            return CheckResult::fail('beacons.store_ip is on but ['.EloquentBeaconStore::VIEWS.'.ip_address] does not exist (it is created only when store_ip is on at migration time): add the column in a host migration, or turn store_ip off. Until then no address is stored.');
        }

        return CheckResult::pass('beacons.store_ip is on and ['.EloquentBeaconStore::VIEWS.'.ip_address] exists');
    }
}
