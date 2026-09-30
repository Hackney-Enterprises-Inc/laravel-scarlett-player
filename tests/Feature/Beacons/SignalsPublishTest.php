<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\ScarlettPlayerServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

it('publishes only the signals upgrade after Laravel timestamped the four original migrations', function (): void {
    $root = sys_get_temp_dir().'/scarlett-signals-publish-'.bin2hex(random_bytes(8));
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
        $originals = glob($destination.'/*.php');
        expect($originals)->toHaveCount(4);
        foreach ($originals as $file) {
            expect(basename($file))->toStartWith('2026_09_29_');
        }
        $this->artisan('migrate', $options)->assertSuccessful();
        DB::table('scarlett_views')->insert(['view_id' => 'old', 'session_id' => 's', 'viewer_id' => 'v', 'video_id' => 'm', 'qoe_score' => 65]);
        Date::setTestNow('2026-09-30 12:00:00');
        $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-signals'])->assertSuccessful();
        expect(glob($destination.'/*.php'))->toHaveCount(5);
        $this->artisan('migrate', $options)->assertSuccessful();
        expect(Schema::hasColumn('scarlett_views', 'qoe_version'))->toBeTrue()
            ->and((int) DB::table('scarlett_views')->where('view_id', 'old')->value('qoe_version'))->toBe(1);
        Date::setTestNow('2026-10-01 12:00:00');
        $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-signals'])->assertSuccessful();
        $this->artisan('migrate', $options)->assertSuccessful();
        expect(glob($destination.'/*.php'))->toHaveCount(5)
            ->and(DB::table('migrations')->count())->toBe(5);
    } finally {
        Artisan::call('migrate:reset', $options);
        Date::setTestNow();
        app()->useDatabasePath($oldDatabasePath);
        File::deleteDirectory($root);
    }
});
