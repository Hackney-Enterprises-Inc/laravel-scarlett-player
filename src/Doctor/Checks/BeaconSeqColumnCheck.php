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
 * Older published migrations lack the raw event ordering column. The store still
 * accepts their beacons; this warning tells the host how to retain the order key.
 */
class BeaconSeqColumnCheck implements Check
{
    public function __construct(
        private readonly Repository $config,
        private readonly ConnectionResolverInterface $database,
    ) {}

    public function name(): string
    {
        return 'beacon seq column';
    }

    public function run(): CheckResult
    {
        if (! $this->config->get('scarlett-player.beacons.store_raw_events')) {
            return CheckResult::pass('beacons.store_raw_events is off; no raw events are stored');
        }

        try {
            $connection = $this->database->connection();

            if (! $connection instanceof Connection) {
                return CheckResult::warn('the default database connection is not an Illuminate\\Database\\Connection; cannot inspect it');
            }

            $schema = $connection->getSchemaBuilder();

            if (! $schema->hasTable(EloquentBeaconStore::EVENTS)) {
                return CheckResult::warn('['.EloquentBeaconStore::EVENTS.'] does not exist yet: publish scarlett-migrations and migrate');
            }

            $present = $schema->hasColumn(EloquentBeaconStore::EVENTS, 'seq');
        } catch (Throwable $e) {
            return CheckResult::warn('could not inspect ['.EloquentBeaconStore::EVENTS.']: '.$e->getMessage());
        }

        if (! $present) {
            return CheckResult::warn("scarlett_beacon_events has no seq column; raw events from player 1.19.3+ are stored without their order key. Add it: \$table->unsignedInteger('seq')->nullable(); \$table->index(['view_id', 'occurred_at', 'seq']);");
        }

        return CheckResult::pass('['.EloquentBeaconStore::EVENTS.'.seq] exists');
    }
}
