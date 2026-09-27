<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Tests\Fixtures\Models;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The host tables the media tests resolve against, created on the test connection.
 *
 * Each table is dropped first: on SQLite every test gets a fresh in-memory database, but
 * on MySQL and Postgres (the beacons-databases CI job) the database outlives the test, so
 * a second create would fail with "table already exists".
 */
final class MediaSchema
{
    public static function create(): void
    {
        foreach (['videos', 'scarlett_videos'] as $table) {
            Schema::dropIfExists($table);
            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->id();
                $blueprint->uuid('uuid')->unique();
                $blueprint->string('slug')->nullable();
                $blueprint->string('hls_url')->nullable();
                $blueprint->boolean('is_live')->nullable();
                $blueprint->boolean('is_ppv')->nullable();
                $blueprint->string('ppv_flag')->nullable();
                $blueprint->float('duration_seconds')->nullable();
                $blueprint->string('storage_disk')->nullable();
                $blueprint->string('mezzanine_path')->nullable();
                $blueprint->string('title')->nullable();
                $blueprint->string('poster_url')->nullable();
            });
        }
    }

    /**
     * A host table keyed by uuid, for the string morph id.
     */
    public static function createUuidVideos(): void
    {
        Schema::dropIfExists('uuid_videos');
        Schema::create('uuid_videos', function (Blueprint $blueprint): void {
            $blueprint->uuid('id')->primary();
            $blueprint->string('title')->nullable();
        });
    }
}
