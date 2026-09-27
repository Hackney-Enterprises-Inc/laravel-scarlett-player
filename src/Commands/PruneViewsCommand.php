<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Commands;

use Hei\ScarlettPlayer\Stores\EloquentBeaconStore;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Date;

/**
 * Deletes beacon data past its retention: raw events after beacons.retention.events
 * days (by when the server received them), and views with their errors after
 * beacons.retention.views days (by the view's last update). A null retention keeps
 * that table forever. Deletes in batches so a large table is not locked in one go.
 *
 * Only the tables EloquentBeaconStore writes; a host store prunes its own.
 */
class PruneViewsCommand extends Command
{
    private const BATCH = 1000;

    protected $signature = 'scarlett:views:prune
        {--days= : Days of raw beacon events to keep, instead of beacons.retention.events}';

    protected $description = 'Delete Scarlett beacon events, views and view errors past their retention';

    public function handle(Repository $config, ConnectionResolverInterface $database): int
    {
        $option = $this->option('days');

        if ($option !== null && ! ctype_digit($option)) {
            $this->error('--days takes a whole number of days.');

            return self::FAILURE;
        }

        $eventDays = $option !== null ? (int) $option : $this->days($config->get('scarlett-player.beacons.retention.events'));
        $viewDays = $this->days($config->get('scarlett-player.beacons.retention.views'));
        $connection = $database->connection();

        $rows = [];

        foreach ([
            [EloquentBeaconStore::EVENTS, 'received_at', $eventDays],
            [EloquentBeaconStore::ERRORS, 'received_at', $viewDays],
            [EloquentBeaconStore::VIEWS, 'updated_at', $viewDays],
        ] as [$table, $column, $days]) {
            if ($days === null) {
                $rows[] = [$table, 'kept (no retention)', '0'];

                continue;
            }

            $cutoff = Date::now()->utc()->subDays($days)->format('Y-m-d H:i:s.v');
            $deleted = 0;

            do {
                $ids = $connection->table($table)->where($column, '<', $cutoff)->orderBy('id')->limit(self::BATCH)->pluck('id')->all();

                if ($ids !== []) {
                    $deleted += $connection->table($table)->whereIn('id', $ids)->delete();
                }
            } while (count($ids) === self::BATCH);

            $rows[] = [$table, "older than {$days} days", (string) $deleted];
        }

        $this->table(['Table', 'Pruned', 'Rows deleted'], $rows);

        return self::SUCCESS;
    }

    private function days(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }
}
