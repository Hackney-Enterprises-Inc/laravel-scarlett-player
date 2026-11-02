<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Exceptions\UpgradeMigrationOrderException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Why a view never played, and the stall ceiling's error (0.10.0). The player's viewEnd
 * carries startState ("played", "awaitingTap", "failed" or "never"); scarlett_views keeps
 * it as start_state, written once. The terminal error of the player's stall ceiling
 * carries stalled: true; scarlett_view_errors keeps it beside reconnect_exhausted, so a
 * stall is told apart from a reconnect window that ran out. A playBlocked beacon needs
 * no column: it is a raw event and its reason and errorName are custom dimensions.
 *
 * Existing rows are not rewritten: both fields were custom dimensions before this
 * release and stay in custom there. Both columns are nullable and unindexed.
 *
 * Idempotent: a column that already exists is left alone, so a second published copy,
 * a host that added the columns itself, or a host that already ran a copy named
 * 0001_01_01_000010_... migrates as a no-op.
 */
return new class extends Migration
{
    private const TABLES = [
        'scarlett_views' => [
            'start_state' => ['string', 16],
        ],
        'scarlett_view_errors' => [
            'stalled' => ['boolean'],
        ],
    ];

    /** Every copy of this upgrade, dated or not, ends with this name. */
    private const NAME = '_add_start_state_and_stalled_to_scarlett_tables';

    public function up(): void
    {
        // Fail before changing anything if this copy sorts before a create migration.
        foreach (array_keys(self::TABLES) as $table) {
            if (! Schema::hasTable($table)) {
                throw UpgradeMigrationOrderException::tableMissing(basename(__FILE__), $table, '0.10.0');
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
