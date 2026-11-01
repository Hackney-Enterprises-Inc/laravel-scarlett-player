<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Exceptions\UpgradeMigrationOrderException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Clock-skew correction columns (0.9.0). Ingest now writes every *_at column on the
 * server clock: the beacon's timestamp shifted by (receipt - sentAt). These columns
 * keep what was corrected. scarlett_beacon_events and scarlett_view_errors get
 * client_occurred_at (the viewer's own clock, as sent) and clock_offset_ms (the shift
 * applied, null when none); scarlett_views gets clock_offset_ms and clock_source
 * ("sentAt", "none" or "rejected"), written once by the beacon that creates the row.
 *
 * Existing rows are not rewritten: sentAt was never stored, so nothing can be
 * backfilled. All columns are nullable and unindexed.
 *
 * Idempotent: a column that already exists is left alone, so a second published copy,
 * a host that added the columns itself, or a host that already ran a copy named
 * 0001_01_01_000009_... migrates as a no-op.
 */
return new class extends Migration
{
    private const TABLES = [
        'scarlett_views' => [
            'clock_offset_ms' => ['bigInteger'],
            'clock_source' => ['string', 16],
        ],
        'scarlett_beacon_events' => [
            'client_occurred_at' => ['dateTime', 3],
            'clock_offset_ms' => ['bigInteger'],
        ],
        'scarlett_view_errors' => [
            'client_occurred_at' => ['dateTime', 3],
            'clock_offset_ms' => ['bigInteger'],
        ],
    ];

    /** Every copy of this upgrade, dated or not, ends with this name. */
    private const NAME = '_add_clock_correction_to_scarlett_tables';

    public function up(): void
    {
        // Fail before changing anything if this copy sorts before a create migration.
        foreach (array_keys(self::TABLES) as $table) {
            if (! Schema::hasTable($table)) {
                throw UpgradeMigrationOrderException::tableMissing(basename(__FILE__), $table, '0.9.0');
            }
        }

        foreach (self::TABLES as $table => $columns) {
            $this->addMissing($table, $columns);
        }
    }

    public function down(): void
    {
        // Another recorded copy of this upgrade still owns the columns.
        if ($this->recordedCopies() > 1) {
            return;
        }

        foreach (self::TABLES as $table => $columns) {
            $this->dropPresent($table, array_keys($columns));
        }
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
