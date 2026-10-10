<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Exceptions\UpgradeMigrationOrderException;
use Hei\ScarlettPlayer\ScarlettPlayerServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

it('publishes only the gauges upgrade on a host with timestamped originals and the earlier upgrades', function (): void {
    $root = sys_get_temp_dir().'/scarlett-gauges-publish-'.bin2hex(random_bytes(8));
    $oldDatabasePath = app()->databasePath();
    $source = $root.'/legacy';
    $destination = $root.'/database/migrations';
    File::makeDirectory($source, 0755, true);
    File::makeDirectory($destination, 0755, true);
    foreach (glob(__DIR__.'/../../../database/migrations/0001_01_01_00000[1234]_*.php') as $file) {
        File::copy($file, $source.'/'.basename($file));
    }
    app()->useDatabasePath($root.'/database');
    config()->set('database.migrations.update_date_on_publish', true);
    $legacyProvider = new class(app()) extends ServiceProvider
    {
        public function publishLegacy(string $source, string $destination): void
        {
            $this->publishesMigrations([$source => $destination], 'scarlett-test-legacy');
        }
    };
    $legacyProvider->publishLegacy($source, $destination);
    (new ScarlettPlayerServiceProvider(app()))->boot();
    $options = ['--path' => $destination, '--realpath' => true, '--force' => true];
    try {
        Date::setTestNow('2026-09-29 12:00:00');
        $this->artisan('vendor:publish', ['--tag' => 'scarlett-test-legacy'])->assertSuccessful();
        $this->artisan('migrate', $options)->assertSuccessful();
        Date::setTestNow('2026-09-30 12:00:00');
        $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-signals'])->assertSuccessful();
        $this->artisan('migrate', $options)->assertSuccessful();
        Date::setTestNow('2026-10-06 12:00:00');
        $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-reconnects'])->assertSuccessful();
        $this->artisan('migrate', $options)->assertSuccessful();
        DB::table('scarlett_views')->insert([
            'view_id' => 'old', 'session_id' => 's', 'viewer_id' => 'v', 'video_id' => 'm',
            'completion_rate' => 37.5, 'rebuffer_ratio' => 12.543,
        ]);

        Date::setTestNow('2026-10-09 12:00:00');
        $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-gauges'])->assertSuccessful();
        expect(glob($destination.'/*.php'))->toHaveCount(7)
            ->and(file_exists($destination.'/2026_10_09_120001_add_gauges_to_scarlett_tables.php'))->toBeTrue();
        $this->artisan('migrate', $options)->assertSuccessful();
        expect(Schema::hasColumns('scarlett_views', ['completion_ratio', 'completion_ratio_at', 'rebuffer_fraction', 'rebuffer_fraction_at']))->toBeTrue();

        // Historical legacy gauge values are not rewritten by the migration;
        // scale decisions belong to ingest and the reviewed backfill only.
        $old = DB::table('scarlett_views')->where('view_id', 'old')->sole();
        expect((float) $old->completion_rate)->toBe(37.5)
            ->and((float) $old->rebuffer_ratio)->toBe(12.543)
            ->and($old->completion_ratio)->toBeNull()
            ->and($old->rebuffer_fraction)->toBeNull();

        Date::setTestNow('2026-10-10 12:00:00');
        $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-gauges'])->assertSuccessful();
        $this->artisan('migrate', $options)->assertSuccessful();
        // A repeat publish adds a second dated copy, which migrates as a no-op.
        expect(glob($destination.'/*.php'))->toHaveCount(8)
            ->and(DB::table('migrations')->count())->toBe(8)
            ->and(Schema::hasColumns('scarlett_views', ['completion_ratio', 'rebuffer_fraction']))->toBeTrue();
    } finally {
        Artisan::call('migrate:reset', $options);
        Date::setTestNow();
        app()->useDatabasePath($oldDatabasePath);
        File::deleteDirectory($root);
    }
});

it('reverses only the columns the gauges migration added', function (): void {
    $this->usesMigrations();
    $migration = require __DIR__.'/../../../database/migrations/0001_01_01_000007_add_gauges_to_scarlett_tables.php';

    $migration->down();
    try {
        expect(Schema::hasColumn('scarlett_views', 'completion_ratio'))->toBeFalse()
            ->and(Schema::hasColumn('scarlett_views', 'completion_ratio_at'))->toBeFalse()
            ->and(Schema::hasColumn('scarlett_views', 'rebuffer_fraction'))->toBeFalse()
            ->and(Schema::hasColumn('scarlett_views', 'rebuffer_fraction_at'))->toBeFalse()
            ->and(Schema::hasColumns('scarlett_views', ['completion_rate', 'rebuffer_ratio', 'seek_count', 'qoe_version', 'reconnect_count']))->toBeTrue();
    } finally {
        $migration->up();
    }
});

it('stops before changing anything when the gauges copy sorts before the views table', function (): void {
    $this->usesMigrations();
    Schema::dropIfExists('scarlett_views');

    $migration = require __DIR__.'/../../../database/migrations/0001_01_01_000007_add_gauges_to_scarlett_tables.php';

    try {
        expect(fn (): mixed => $migration->up())->toThrow(UpgradeMigrationOrderException::class, 'scarlett_views');
    } finally {
        // Recreate the schema the rest of the suite expects.
        (require __DIR__.'/../../../database/migrations/0001_01_01_000001_create_scarlett_views_table.php')->up();
        (require __DIR__.'/../../../database/migrations/0001_01_01_000005_add_signals_to_scarlett_tables.php')->up();
        (require __DIR__.'/../../../database/migrations/0001_01_01_000006_add_reconnects_to_scarlett_tables.php')->up();
        $migration->up();
    }
});
