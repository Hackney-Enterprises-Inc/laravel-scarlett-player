<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Exceptions\UpgradeMigrationOrderException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Idempotent: a column that already exists is left alone, so a second published copy,
 * a host that added the columns itself, or a host that already ran the 0.4/0.5 copy
 * named 0001_01_01_000005_... migrates as a no-op. On a schema without the columns it
 * adds exactly what 0.5.0 added, in the same order.
 *
 * MySQL DDL is not transactional, so a run can stop between ALTERs. qoe_version and
 * its backfill come first, alone; a rerun that finds qoe_version as the only signals
 * column cannot tell whether that backfill ran, so it runs it again for scored rows
 * still without a version.
 */
return new class extends Migration
{
    private const INTERVALS = [
        'segment_count', 'segment_bytes', 'segment_load_avg_ms', 'segment_load_max_ms',
        'segment_errors', 'segment_throughput_bps', 'decoded_frames', 'dropped_frames',
    ];

    private const ERROR_COLUMNS = [
        'category' => ['string'],
        'severity' => ['string'],
        'http_status' => ['unsignedInteger'],
        'media_error_code' => ['unsignedInteger'],
        'attempts' => ['unsignedInteger'],
        'retries_exhausted' => ['boolean'],
        'reconnect_exhausted' => ['boolean'],
        'timed_out' => ['boolean'],
    ];

    /** Every copy of this upgrade, dated or not, ends with this name. */
    private const NAME = '_add_signals_to_scarlett_tables';

    public function up(): void
    {
        // Fail before changing anything if this copy sorts before a create migration.
        foreach (['scarlett_views', 'scarlett_view_errors'] as $table) {
            if (! Schema::hasTable($table)) {
                throw UpgradeMigrationOrderException::tableMissing(basename(__FILE__), $table, '0.4.0');
            }
        }

        $views = $this->viewColumns();
        $present = [
            ...array_map('strtolower', Schema::getColumnListing('scarlett_views')),
            ...array_map('strtolower', Schema::getColumnListing('scarlett_view_errors')),
        ];
        $others = array_diff([...array_keys($views), ...array_keys(self::ERROR_COLUMNS)], ['qoe_version']);

        if ($this->addMissing('scarlett_views', ['qoe_version' => $views['qoe_version']]) !== []) {
            $this->backfill();
        } elseif (array_intersect($others, $present) === []) {
            // An earlier run stopped after adding qoe_version; its backfill may not have run.
            $this->backfill();
        }

        $this->addMissing('scarlett_views', $views);
        $this->addMissing('scarlett_view_errors', self::ERROR_COLUMNS);
    }

    public function down(): void
    {
        // Another recorded copy of this upgrade still owns the columns.
        if ($this->recordedCopies() > 1) {
            return;
        }

        $this->dropPresent('scarlett_view_errors', array_keys(self::ERROR_COLUMNS));
        $this->dropPresent('scarlett_views', array_keys($this->viewColumns()));
    }

    /**
     * Scores stored before the signals contract used QoE v1. Rows that already carry a
     * version are left alone, so a resumed run never relabels a v2 score.
     */
    private function backfill(): void
    {
        DB::table('scarlett_views')->whereNotNull('qoe_score')->whereNull('qoe_version')->update(['qoe_version' => 1]);
    }

    /** @return array<string, list<string|int>> column => [Blueprint method, extra arguments] */
    private function viewColumns(): array
    {
        $columns = [
            'qoe_version' => ['unsignedInteger'],
            'warning_count' => ['unsignedInteger'],
            'fatal_error_category' => ['string'],
            'anonymous' => ['boolean'],
            'page_url' => ['text'],
            'referrer_origin' => ['text'],
            'page_load_to_init_ms' => ['double'],
            'player_init_ms' => ['double'],
        ];

        // These are the latest reported intervals, not cumulative view totals.
        foreach (self::INTERVALS as $column) {
            $columns[$column] = ['double'];
            $columns[$column.'_at'] = ['dateTime', 3];
        }

        return $columns;
    }

    /**
     * Add the nullable columns the table lacks, in definition order.
     *
     * @param  array<string, list<string|int>>  $columns
     * @return list<string> the columns added
     */
    private function addMissing(string $table, array $columns): array
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

        return $missing;
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
