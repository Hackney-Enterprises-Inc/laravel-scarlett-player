<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Partner embeds (0.11.0). One row per partner site allowed to frame the /v/{uuid}
 * embed page: ref is the analytics identity (immutable), key_id the random value
 * that rides on the page URL (regenerated on rotate and restore), origins the strict
 * origin list the page's frame-ancestors is built from, expires_at the contract end
 * and revoked_at the kill switch, both read on every page load.
 *
 * Idempotent: the table is created only when absent, and each UNIQUE index is ensured
 * by its columns outside that guard, so a run that died between the CREATE and an
 * index statement (MySQL DDL is not transactional) is finished by the rerun with the
 * rows already inserted intact. The dates are dateTime, not timestamp: MySQL's
 * TIMESTAMP stops at 2038.
 *
 * Rolling back drops the table and its revocation state, but only for the last
 * recorded copy of this migration.
 */
return new class extends Migration
{
    private const TABLE = 'scarlett_partners';

    /** Column => index name. */
    private const UNIQUES = [
        'ref' => 'scarlett_partners_ref_unique',
        'key_id' => 'scarlett_partners_key_id_unique',
    ];

    /** Every copy of this migration, dated or not, ends with this name. */
    private const NAME = '_create_scarlett_partners_table';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();
                $table->string('ref', 64);
                $table->string('key_id', 32);
                $table->string('name', 191);
                $table->json('origins');
                $table->dateTime('expires_at')->nullable();
                $table->dateTime('revoked_at')->nullable();
                $table->timestamps();
            });
        }

        foreach (self::UNIQUES as $column => $name) {
            if (! Schema::hasIndex(self::TABLE, [$column], 'unique')) {
                Schema::table(self::TABLE, function (Blueprint $table) use ($column, $name): void {
                    $table->unique($column, $name);
                });
            }
        }
    }

    public function down(): void
    {
        // Another recorded copy of this migration still owns the table.
        if ($this->recordedCopies() > 1) {
            return;
        }

        Schema::dropIfExists(self::TABLE);
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
