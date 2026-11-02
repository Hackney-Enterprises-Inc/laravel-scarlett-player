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

/** Report a missing clock-skew upgrade (0.9.0) while the store continues without those columns. */
class BeaconClockColumnsCheck implements Check
{
    public function __construct(
        private readonly ConnectionResolverInterface $database,
        private readonly Container $container,
    ) {}

    public function name(): string
    {
        return 'beacon clock columns';
    }

    public function run(): CheckResult
    {
        try {
            if (! $this->store() instanceof EloquentBeaconStore) {
                return CheckResult::pass('the bound beacon store does not use the Eloquent clock columns');
            }

            $connection = $this->database->connection();

            if (! $connection instanceof Connection) {
                return CheckResult::warn('cannot inspect clock columns on this database connection');
            }

            $missing = [];
            foreach (EloquentBeaconStore::CLOCK_COLUMNS as $table => $required) {
                $present = $connection->getSchemaBuilder()->getColumnListing($table);
                foreach (array_diff($required, $present) as $column) {
                    $missing[] = $table.'.'.$column;
                }
            }
        } catch (Throwable $e) {
            return CheckResult::warn('could not inspect clock columns: '.$e->getMessage());
        }

        if ($missing !== []) {
            return CheckResult::warn('Missing clock columns: '.implode(', ', $missing).'. Ingest still corrects every *_at column but skips the raw client time, the shift applied and the clock_source of the view. On existing tables publish only --tag=scarlett-migrations-clock, migrate, then restart queue workers. Fresh installs use scarlett-migrations.');
        }

        return CheckResult::pass('all beacon clock columns exist');
    }

    /** Resolve the host's actual binding, including custom and null stores. */
    private function store(): BeaconStore
    {
        return $this->container->make(BeaconStore::class);
    }
}
