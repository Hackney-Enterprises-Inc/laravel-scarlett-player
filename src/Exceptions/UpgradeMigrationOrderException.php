<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Exceptions;

/**
 * An upgrade migration ran before the migration that creates its table. Thrown before
 * any change, so the database is as it was.
 */
class UpgradeMigrationOrderException extends ScarlettPlayerException
{
    /**
     * @param  string  $migration  the upgrade file's name
     * @param  string  $table  the first table it needs that does not exist
     * @param  string  $since  the first package version whose scarlett-migrations folder contains the upgrade
     */
    public static function tableMissing(string $migration, string $table, string $since): self
    {
        return new self("{$migration} ran before the migration that creates {$table}: it sorts before the scarlett create migrations. Nothing was changed. If this host published the full scarlett-migrations folder from {$since} or later, that folder already contains this upgrade: delete this copy. Otherwise rename this file so its timestamp sorts after your create_scarlett_* migrations, then migrate again. php artisan scarlett:doctor lists every misordered copy.");
    }
}
