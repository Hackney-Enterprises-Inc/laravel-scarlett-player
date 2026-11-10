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

/**
 * Report a missing partner_ref column (migration 12) once partners exist. Never throws:
 * a missing scarlett_partners table or partner_ref column is a pass or a warning, so a
 * host that never adopted partners sees nothing.
 */
class BeaconPartnerColumnsCheck implements Check
{
    private const PARTNERS = 'scarlett_partners';

    public function __construct(
        private readonly ConnectionResolverInterface $database,
        private readonly Container $container,
    ) {}

    public function name(): string
    {
        return 'beacon partner columns';
    }

    public function run(): CheckResult
    {
        try {
            if (! $this->store() instanceof EloquentBeaconStore) {
                return CheckResult::pass('the bound beacon store does not use the Eloquent partner column');
            }

            $connection = $this->database->connection();

            if (! $connection instanceof Connection) {
                return CheckResult::warn('cannot inspect the partner column on this database connection');
            }

            $schema = $connection->getSchemaBuilder();

            if (! $schema->hasTable(self::PARTNERS)) {
                return CheckResult::pass('no partners table, so no partner attribution is expected');
            }

            if ($connection->table(self::PARTNERS)->doesntExist()) {
                return CheckResult::pass('no partners exist, so no partner attribution is expected');
            }

            $present = $schema->getColumnListing(EloquentBeaconStore::VIEWS);
            $missing = [];

            foreach (array_diff(EloquentBeaconStore::PARTNER_COLUMNS[EloquentBeaconStore::VIEWS], $present) as $column) {
                $missing[] = EloquentBeaconStore::VIEWS.'.'.$column;
            }
        } catch (Throwable $e) {
            return CheckResult::warn('could not inspect the partner column: '.$e->getMessage());
        }

        if ($missing !== []) {
            return CheckResult::warn('Partners exist but '.implode(', ', $missing).' is missing, so beacons are ingested without partner attribution (the raw log keeps the verified ref). Publish only --tag=scarlett-migrations-partner-ref, migrate, then restart queue workers. Fresh installs use scarlett-migrations.');
        }

        return CheckResult::pass('the partner attribution column exists');
    }

    /** Resolve the host's actual binding, including custom and null stores. */
    private function store(): BeaconStore
    {
        return $this->container->make(BeaconStore::class);
    }
}
