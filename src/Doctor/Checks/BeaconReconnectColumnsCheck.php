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

/** Report a missing player 1.22.0 upgrade while the store continues without those columns. */
class BeaconReconnectColumnsCheck implements Check
{
    public function __construct(
        private readonly ConnectionResolverInterface $database,
        private readonly Container $container,
    ) {}

    public function name(): string
    {
        return 'beacon reconnect columns';
    }

    public function run(): CheckResult
    {
        try {
            if (! $this->store() instanceof EloquentBeaconStore) {
                return CheckResult::pass('the bound beacon store does not use the Eloquent reconnect columns');
            }

            $connection = $this->database->connection();

            if (! $connection instanceof Connection) {
                return CheckResult::warn('cannot inspect reconnect columns on this database connection');
            }

            $missing = [];
            foreach (EloquentBeaconStore::RECONNECT_COLUMNS as $table => $required) {
                $present = $connection->getSchemaBuilder()->getColumnListing($table);
                foreach (array_diff($required, $present) as $column) {
                    $missing[] = $table.'.'.$column;
                }
            }
        } catch (Throwable $e) {
            return CheckResult::warn('could not inspect reconnect columns: '.$e->getMessage());
        }

        if ($missing !== []) {
            return CheckResult::warn('Missing player 1.22 columns: '.implode(', ', $missing).'. Ingest skips them (the raw log keeps the values). On existing tables publish only --tag=scarlett-migrations-reconnects, migrate, then restart queue workers. Fresh installs use scarlett-migrations.');
        }

        return CheckResult::pass('all beacon reconnect columns exist');
    }

    /** Resolve the host's actual binding, including custom and null stores. */
    private function store(): BeaconStore
    {
        return $this->container->make(BeaconStore::class);
    }
}
