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

/** Report a missing start state upgrade (0.10.0) while the store continues without those columns. */
class BeaconStartStateColumnsCheck implements Check
{
    public function __construct(
        private readonly ConnectionResolverInterface $database,
        private readonly Container $container,
    ) {}

    public function name(): string
    {
        return 'beacon start state columns';
    }

    public function run(): CheckResult
    {
        try {
            if (! $this->store() instanceof EloquentBeaconStore) {
                return CheckResult::pass('the bound beacon store does not use the Eloquent start state columns');
            }

            $connection = $this->database->connection();

            if (! $connection instanceof Connection) {
                return CheckResult::warn('cannot inspect start state columns on this database connection');
            }

            $missing = [];
            foreach (EloquentBeaconStore::START_STATE_COLUMNS as $table => $required) {
                $present = $connection->getSchemaBuilder()->getColumnListing($table);
                foreach (array_diff($required, $present) as $column) {
                    $missing[] = $table.'.'.$column;
                }
            }
        } catch (Throwable $e) {
            return CheckResult::warn('could not inspect start state columns: '.$e->getMessage());
        }

        if ($missing !== []) {
            return CheckResult::warn('Missing start state columns: '.implode(', ', $missing).'. Ingest keeps working but skips the view\'s start_state and the error row\'s stalled flag; both stay readable in the raw log and the custom dimensions. On existing tables publish only --tag=scarlett-migrations-startstate, migrate, then restart queue workers. Fresh installs use scarlett-migrations.');
        }

        return CheckResult::pass('all beacon start state columns exist');
    }

    /** Resolve the host's actual binding, including custom and null stores. */
    private function store(): BeaconStore
    {
        return $this->container->make(BeaconStore::class);
    }
}
