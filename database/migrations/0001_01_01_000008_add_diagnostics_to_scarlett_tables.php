<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Exceptions\UpgradeMigrationOrderException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Troubleshooting columns (0.8.0). decoded_frames_total / dropped_frames_total
 * hold the view's running frame totals from player 1.25.0 (decodedFramesTotal /
 * droppedFramesTotal on heartbeat and viewEnd), the larger value winning. A high
 * dropped share without rebuffering points at the viewer's decoder, not the
 * network. viewer_ref is the host's own id for the viewer, copied once per view
 * from the server context key named by beacons.viewer_ref_key, and indexed so
 * support can find a customer's views.
 *
 * Existing rows are not rewritten: older views have no frame totals, and their
 * server context stays in the server column.
 *
 * Idempotent: a column or index that already exists is left alone, so a second
 * published copy, a host that added the columns itself, or a host that already
 * ran a copy named 0001_01_01_000008_... migrates as a no-op.
 */
return new class extends Migration
{
    private const VIEW_COLUMNS = [
        'decoded_frames_total' => ['unsignedBigInteger'],
        'dropped_frames_total' => ['unsignedBigInteger'],
        'viewer_ref' => ['string', 191],
    ];

    private const INDEX = 'scarlett_views_viewer_ref_index';

    /** Every copy of this upgrade, dated or not, ends with this name. */
    private const NAME = '_add_diagnostics_to_scarlett_tables';

    public function up(): void
    {
        // Fail before changing anything if this copy sorts before a create migration.
        if (! Schema::hasTable('scarlett_views')) {
            throw UpgradeMigrationOrderException::tableMissing(basename(__FILE__), 'scarlett_views', '0.8.0');
        }

        $this->addMissing('scarlett_views', self::VIEW_COLUMNS);

        if (! Schema::hasIndex('scarlett_views', ['viewer_ref'])) {
            Schema::table('scarlett_views', function (Blueprint $blueprint): void {
                $blueprint->index('viewer_ref', self::INDEX);
            });
        }
    }

    public function down(): void
    {
        // Another recorded copy of this upgrade still owns the columns.
        if ($this->recordedCopies() > 1) {
            return;
        }

        if (Schema::hasIndex('scarlett_views', self::INDEX)) {
            Schema::table('scarlett_views', function (Blueprint $blueprint): void {
                $blueprint->dropIndex(self::INDEX);
            });
        }

        $this->dropPresent('scarlett_views', array_keys(self::VIEW_COLUMNS));
    }

    /**
     * Add the nullable columns the table lacks, in definition order.
     *
     * @param  array<string, list<string|int>>  $columns
     */
    private function addMissing(string $table, array $columns): void
    {
        $present = array_map('strtolower', Schema::getColumnListing($table));
        $missing = array_values(array_diff(array_keys($columns), $present));

        if ($missing !== []) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $missing): void {
                foreach ($missing as $column) {
                    $arguments = $columns[$column];
                    $method = (string) array_shift($arguments);
                    $blueprint->{$method}($column, ...$arguments)->nullable();
                }
            });
        }
    }

    /** @param  list<string>  $columns */
    private function dropPresent(string $table, array $columns): void
    {
        $present = array_values(array_intersect($columns, array_map('strtolower', Schema::getColumnListing($table))));

        if ($present !== []) {
            Schema::table($table, function (Blueprint $blueprint) use ($present): void {
                $blueprint->dropColumn($present);
            });
        }
    }

    private function recordedCopies(): int
    {
        $repository = app('migration.repository');

        if (! $repository->repositoryExists()) {
            return 0;
        }

        return count(array_filter(
            $repository->getRan(),
            fn (string $migration): bool => str_ends_with($migration, self::NAME),
        ));
    }
};
