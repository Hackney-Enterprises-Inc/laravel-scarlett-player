<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Exceptions;

/**
 * The partner ref upgrade found a host unique index on scarlett_views.partner_ref. Thrown
 * before any change, so the database is as it was: the package never drops a host's
 * constraint for it.
 */
class PartnerRefIndexException extends ScarlettPlayerException
{
    /**
     * @param  string  $migration  the upgrade file's name
     * @param  string  $table  the views table
     * @param  string  $column  the partner ref column
     * @param  string  $index  the name of the unique index found
     */
    public static function uniqueIndex(string $migration, string $table, string $column, string $index): self
    {
        return new self("{$migration} found the unique index [{$index}] on {$table}.{$column}. {$column} must be non-unique because one partner has many views: with that index a second view for the same partner cannot be stored. Nothing was changed. Drop the index [{$index}] (for a UNIQUE constraint, drop the constraint), then migrate again.");
    }
}
