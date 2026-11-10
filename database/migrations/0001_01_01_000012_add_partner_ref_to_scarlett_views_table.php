<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Exceptions\UpgradeMigrationOrderException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Partner attribution (0.11.0). partner_ref is the ref of the partner whose signed
 * embed produced the view, copied once per view from the verified server context key
 * scarlett_partner and indexed so a partner's views can be listed. It is not a
 * foreign key: the analytics row outlives the partner. Existing rows are not
 * backfilled.
 *
 * Idempotent: a column or index that already exists is left alone, so a second
 * published copy or a host that added them itself migrates as a no-op. The index
 * predicate is the same both ways: any index covering exactly partner_ref counts,
 * whatever its name, and down() drops those by what it finds, never by an assumed
 * name. Only the last recorded copy rolls back, and rolling back never touches
 * scarlett_partners.
 */
return new class extends Migration
{
    private const TABLE = 'scarlett_views';

    private const COLUMN = 'partner_ref';

    private const INDEX = 'scarlett_views_partner_ref_index';

    /** Every copy of this upgrade, dated or not, ends with this name. */
    private const NAME = '_add_partner_ref_to_scarlett_views_table';

    public function up(): void
    {
        // Fail before changing anything if this copy sorts before the views create.
        if (! Schema::hasTable(self::TABLE)) {
            throw UpgradeMigrationOrderException::tableMissing(basename(__FILE__), self::TABLE, '0.11.0');
        }

        if (! $this->hasColumn()) {
            Schema::table(self::TABLE, function (Blueprint $blueprint): void {
                $blueprint->string(self::COLUMN, 64)->nullable();
            });
        }

        if ($this->indexes() === []) {
            Schema::table(self::TABLE, function (Blueprint $blueprint): void {
                $blueprint->index(self::COLUMN, self::INDEX);
            });
        }
    }

    public function down(): void
    {
        // Another recorded copy of this upgrade still owns the column.
        if ($this->recordedCopies() > 1 || ! Schema::hasTable(self::TABLE)) {
            return;
        }

        $constraints = $this->uniqueConstraintNames();

        foreach ($this->indexes() as $index) {
            // PostgreSQL refuses to drop the index behind a UNIQUE constraint, only the
            // constraint; a standalone unique index and an ordinary one are plain indexes.
            $dropsConstraint = $index['unique'] && in_array($index['name'], $constraints, true);

            Schema::table(self::TABLE, function (Blueprint $blueprint) use ($index, $dropsConstraint): void {
                $dropsConstraint ? $blueprint->dropUnique($index['name']) : $blueprint->dropIndex($index['name']);
            });
        }

        if ($this->hasColumn()) {
            Schema::table(self::TABLE, function (Blueprint $blueprint): void {
                $blueprint->dropColumn(self::COLUMN);
            });
        }
    }

    private function hasColumn(): bool
    {
        return in_array(self::COLUMN, array_map('strtolower', Schema::getColumnListing(self::TABLE)), true);
    }

    /**
     * The non-primary indexes covering exactly partner_ref, with their unique flag.
     *
     * @return list<array{name: string, unique: bool}>
     */
    private function indexes(): array
    {
        $found = [];

        foreach (Schema::getIndexes(self::TABLE) as $index) {
            if (! $index['primary'] && array_map('strtolower', $index['columns']) === [self::COLUMN]) {
                $found[] = ['name' => $index['name'], 'unique' => (bool) $index['unique']];
            }
        }

        return $found;
    }

    /**
     * Names of the UNIQUE constraints of the table. Only PostgreSQL tells a constraint from
     * a standalone unique index (the index behind a constraint carries the constraint's
     * name there); MySQL and SQLite drop a unique index and a constraint the same way.
     *
     * @return list<string>
     */
    private function uniqueConstraintNames(): array
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return [];
        }

        $rows = $connection->select(
            "select c.conname as name from pg_constraint c join pg_class t on t.oid = c.conrelid where c.contype = 'u' and t.relname = ? and t.relnamespace = (select oid from pg_namespace where nspname = current_schema())",
            [$connection->getTablePrefix().self::TABLE],
        );

        return array_map(fn (object $row): string => (string) $row->name, $rows);
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
