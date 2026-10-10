<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Exceptions\UpgradeMigrationOrderException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Canonical gauge columns. The legacy completion_rate and rebuffer_ratio keep
 * the received wire values (percent for Scarlett 1.x); ingest additionally
 * normalizes each gauge into a canonical 0..1 ratio with the timestamp of the
 * measurement that wrote it. completion_ratio / completion_ratio_at come from
 * completionRate, rebuffer_fraction / rebuffer_fraction_at from rebufferRatio,
 * each gauge stamped independently. A canonical null with a stamp is a
 * processed unavailable measurement (a live view, an unknown scale), which a
 * backfill resumes from instead of refilling with an old value.
 *
 * Existing rows are not rewritten: historical scales are never guessed by
 * magnitude, so rows keep whatever the legacy columns hold until the dry-run
 * backfill command (scarlett:views:backfill-gauges) proves a scale from each
 * measurement's own retained evidence.
 *
 * Idempotent: a column that already exists is left alone, so a second published
 * copy, a host that added the columns itself, or a host that already ran a copy
 * named 0001_01_01_000007_... migrates as a no-op.
 */
return new class extends Migration
{
    private const VIEW_COLUMNS = [
        'completion_ratio' => ['double'],
        'completion_ratio_at' => ['dateTime', 3],
        'rebuffer_fraction' => ['double'],
        'rebuffer_fraction_at' => ['dateTime', 3],
    ];

    /** Every copy of this upgrade, dated or not, ends with this name. */
    private const NAME = '_add_gauges_to_scarlett_tables';

    public function up(): void
    {
        // Fail before changing anything if this copy sorts before a create migration.
        if (! Schema::hasTable('scarlett_views')) {
            throw UpgradeMigrationOrderException::tableMissing(basename(__FILE__), 'scarlett_views', '0.6.0');
        }

        $this->addMissing('scarlett_views', self::VIEW_COLUMNS);
    }

    public function down(): void
    {
        // Another recorded copy of this upgrade still owns the columns.
        if ($this->recordedCopies() > 1) {
            return;
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
