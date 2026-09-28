<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Doctor\Checks;

use Hei\ScarlettPlayer\Contracts\ResolvesBeaconContext;
use Hei\ScarlettPlayer\Doctor\Check;
use Hei\ScarlettPlayer\Doctor\CheckResult;
use Hei\ScarlettPlayer\Stores\EloquentBeaconStore;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Throwable;

/**
 * beacons.context names a ResolvesBeaconContext, and scarlett_views has the server
 * columns it writes to. A beacon with server context fails in the store without them;
 * a table migrated by 0.1.0, before the columns existed, finds out here.
 */
class BeaconContextCheck implements Check
{
    public function __construct(
        private readonly Repository $config,
        private readonly ConnectionResolverInterface $database,
    ) {}

    public function name(): string
    {
        return 'beacon context';
    }

    public function run(): CheckResult
    {
        $class = $this->config->get('scarlett-player.beacons.context');

        if ($class === null || $class === '') {
            return CheckResult::pass('beacons.context is not set; no server context is attached');
        }

        if (! is_string($class) || ! class_exists($class)) {
            return CheckResult::fail('beacons.context ['.(is_string($class) ? $class : get_debug_type($class)).'] is not a class');
        }

        if (! is_subclass_of($class, ResolvesBeaconContext::class)) {
            return CheckResult::fail('beacons.context ['.$class.'] does not implement '.ResolvesBeaconContext::class.': every beacon is answered 500');
        }

        if (! in_array($this->config->get('scarlett-player.beacons.store'), ['eloquent', EloquentBeaconStore::class], true)) {
            return CheckResult::pass('beacons.context is ['.$class.']; the configured store decides where its fields go');
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

            $present = $schema->hasColumns(EloquentBeaconStore::VIEWS, ['server', 'server_stamps']);
        } catch (Throwable $e) {
            return CheckResult::warn('could not inspect ['.EloquentBeaconStore::VIEWS.']: '.$e->getMessage());
        }

        if (! $present) {
            return CheckResult::fail('beacons.context is set but ['.EloquentBeaconStore::VIEWS.'.server] does not exist (a table migrated by 0.1.0): add the two nullable json columns server and server_stamps in a host migration. Until then every beacon with server context fails in the queue.');
        }

        return CheckResult::pass('beacons.context is ['.$class.'] and ['.EloquentBeaconStore::VIEWS.'.server] exists');
    }
}
