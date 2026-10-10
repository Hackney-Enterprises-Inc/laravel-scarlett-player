<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Doctor\Checks;

use Hei\ScarlettPlayer\Contracts\BeaconStore;
use Hei\ScarlettPlayer\Doctor\Check;
use Hei\ScarlettPlayer\Doctor\CheckResult;
use Hei\ScarlettPlayer\Stores\EloquentBeaconStore;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Throwable;

/** Report a missing gauges upgrade while the store continues without those columns. */
class BeaconGaugeColumnsCheck implements Check
{
    public function __construct(
        private readonly ConnectionResolverInterface $database,
        private readonly Container $container,
    ) {}

    public function name(): string
    {
        return 'beacon gauge columns';
    }

    public function run(): CheckResult
    {
        try {
            if (! $this->store() instanceof EloquentBeaconStore) {
                return CheckResult::pass('the bound beacon store does not use the Eloquent gauge columns');
            }

            $connection = $this->database->connection();

            if (! $connection instanceof Connection) {
                return CheckResult::warn('cannot inspect gauge columns on this database connection');
            }

            $missing = [];
            foreach (EloquentBeaconStore::GAUGE_COLUMNS as $table => $required) {
                $present = $connection->getSchemaBuilder()->getColumnListing($table);
                foreach (array_diff($required, $present) as $column) {
                    $missing[] = $table.'.'.$column;
                }
            }
        } catch (Throwable $e) {
            return CheckResult::warn('could not inspect gauge columns: '.$e->getMessage());
        }

        if ($missing !== []) {
            return CheckResult::warn('Missing canonical gauge columns: '.implode(', ', $missing).'. Ingest keeps the legacy gauge columns and skips the canonical ones (the raw log keeps the values). On existing tables publish only --tag=scarlett-migrations-gauges, migrate, restart queue workers, then review the dry run of scarlett:views:backfill-gauges (it writes nothing without --apply). Fresh installs use scarlett-migrations.');
        }

        return CheckResult::pass('all beacon gauge columns exist');
    }

    /** Resolve the host's actual binding, including custom and null stores. */
    private function store(): BeaconStore
    {
        return $this->container->make(BeaconStore::class);
    }
}
