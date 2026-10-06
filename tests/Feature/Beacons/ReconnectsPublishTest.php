<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\ScarlettPlayerServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

it('publishes only the reconnects upgrade on a host with timestamped originals and the signals upgrade', function (): void {
    $root = sys_get_temp_dir().'/scarlett-reconnects-publish-'.bin2hex(random_bytes(8));
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
        DB::table('scarlett_views')->insert(['view_id' => 'old', 'session_id' => 's', 'viewer_id' => 'v', 'video_id' => 'm', 'avg_bitrate' => 0, 'max_bitrate' => 0]);

        Date::setTestNow('2026-10-06 12:00:00');
        $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-reconnects'])->assertSuccessful();
        expect(glob($destination.'/*.php'))->toHaveCount(6)
            ->and(file_exists($destination.'/0001_01_01_000006_add_reconnects_to_scarlett_tables.php'))->toBeTrue();
        $this->artisan('migrate', $options)->assertSuccessful();
        expect(Schema::hasColumns('scarlett_views', ['element_seek_count', 'reconnect_count', 'reconnect_ms', 'dvr_ms', 'pause_ms', 'media_duration', 'media_duration_at']))->toBeTrue()
            ->and(Schema::hasColumns('scarlett_view_errors', ['network_state', 'ready_state', 'online', 'source_host', 'reconnecting']))->toBeTrue();

        // Historical zero bitrates are not rewritten.
        $old = DB::table('scarlett_views')->where('view_id', 'old')->sole();
        expect((int) $old->avg_bitrate)->toBe(0)->and((int) $old->max_bitrate)->toBe(0)
            ->and($old->reconnect_count)->toBeNull()
            ->and($old->pause_ms)->toBeNull()
            ->and($old->media_duration)->toBeNull();

        Date::setTestNow('2026-10-07 12:00:00');
        $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-reconnects'])->assertSuccessful();
        $this->artisan('migrate', $options)->assertSuccessful();
        expect(glob($destination.'/*.php'))->toHaveCount(6)
            ->and(DB::table('migrations')->count())->toBe(6);
    } finally {
        Artisan::call('migrate:reset', $options);
        Date::setTestNow();
        app()->useDatabasePath($oldDatabasePath);
        File::deleteDirectory($root);
    }
});

it('reverses only the columns the reconnects migration added', function (): void {
    $this->usesMigrations();
    $migration = require __DIR__.'/../../../database/migrations/0001_01_01_000006_add_reconnects_to_scarlett_tables.php';

    $migration->down();
    try {
        expect(Schema::hasColumn('scarlett_views', 'reconnect_count'))->toBeFalse()
            ->and(Schema::hasColumn('scarlett_views', 'pause_ms'))->toBeFalse()
            ->and(Schema::hasColumn('scarlett_views', 'media_duration'))->toBeFalse()
            ->and(Schema::hasColumn('scarlett_views', 'media_duration_at'))->toBeFalse()
            ->and(Schema::hasColumn('scarlett_view_errors', 'reconnecting'))->toBeFalse()
            ->and(Schema::hasColumns('scarlett_views', ['seek_count', 'warning_count', 'qoe_version']))->toBeTrue()
            ->and(Schema::hasColumns('scarlett_view_errors', ['fatal', 'severity']))->toBeTrue();
    } finally {
        $migration->up();
    }
});
