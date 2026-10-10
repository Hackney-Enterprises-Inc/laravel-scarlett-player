<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Contracts\BeaconStore;
use Hei\ScarlettPlayer\Doctor\CheckResult;
use Hei\ScarlettPlayer\Doctor\Checks\BeaconUpgradeMigrationOrderCheck;
use Hei\ScarlettPlayer\Doctor\CheckStatus;
use Hei\ScarlettPlayer\Exceptions\UpgradeMigrationOrderException;
use Hei\ScarlettPlayer\ScarlettPlayerServiceProvider;
use Hei\ScarlettPlayer\Stores\NullBeaconStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/*
 * A host's create migrations are normally published with Laravel's dated names. The
 * upgrade tags must land after them, so a fresh `migrate` (new environment, test
 * database) never alters a table before it exists, and every upgrade copy must be
 * a no-op where its columns already exist.
 */

const UPGRADE_SIGNALS_VIEWS = [
    'qoe_version', 'warning_count', 'fatal_error_category', 'anonymous', 'page_url',
    'referrer_origin', 'page_load_to_init_ms', 'player_init_ms',
    'segment_count', 'segment_count_at', 'segment_bytes', 'segment_bytes_at',
    'segment_load_avg_ms', 'segment_load_avg_ms_at', 'segment_load_max_ms', 'segment_load_max_ms_at',
    'segment_errors', 'segment_errors_at', 'segment_throughput_bps', 'segment_throughput_bps_at',
    'decoded_frames', 'decoded_frames_at', 'dropped_frames', 'dropped_frames_at',
];

const UPGRADE_SIGNALS_ERRORS = [
    'category', 'severity', 'http_status', 'media_error_code', 'attempts',
    'retries_exhausted', 'reconnect_exhausted', 'timed_out',
];

const UPGRADE_RECONNECTS_VIEWS = [
    'element_seek_count', 'reconnect_count', 'reconnect_ms', 'dvr_ms', 'pause_ms',
    'media_duration', 'media_duration_at',
];

const UPGRADE_RECONNECTS_ERRORS = ['network_state', 'ready_state', 'online', 'source_host', 'reconnecting'];

const UPGRADE_GAUGES_VIEWS = ['completion_ratio', 'completion_ratio_at', 'rebuffer_fraction', 'rebuffer_fraction_at'];

const UPGRADE_SIGNALS_FILE = '0001_01_01_000005_add_signals_to_scarlett_tables.php';

const UPGRADE_RECONNECTS_FILE = '0001_01_01_000006_add_reconnects_to_scarlett_tables.php';

const UPGRADE_GAUGES_FILE = '0001_01_01_000007_add_gauges_to_scarlett_tables.php';

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/scarlett-upgrade-order-'.bin2hex(random_bytes(8));
    $this->oldDatabasePath = app()->databasePath();
    $this->destination = $this->root.'/database/migrations';
    $source = $this->root.'/legacy';
    File::makeDirectory($source, 0755, true);
    File::makeDirectory($this->destination, 0755, true);
    foreach (glob(__DIR__.'/../../../database/migrations/0001_01_01_00000[1234]_*.php') as $file) {
        File::copy($file, $source.'/'.basename($file));
    }
    app()->useDatabasePath($this->root.'/database');
    config()->set('database.migrations.update_date_on_publish', true);

    // The create migrations as a host published them before signals existed.
    $legacyProvider = new class(app()) extends ServiceProvider
    {
        public function publishLegacy(string $source, string $destination): void
        {
            $this->publishesMigrations([$source => $destination], 'scarlett-test-legacy');
        }
    };
    $legacyProvider->publishLegacy($source, $this->destination);
    (new ScarlettPlayerServiceProvider(app()))->boot();
    $this->migrateOptions = ['--path' => $this->destination, '--realpath' => true, '--force' => true];
    dropUpgradeTables();
});

afterEach(function (): void {
    try {
        // Cleanup only: a host's own 0.5.0-style down() cannot drop columns already gone.
        Artisan::call('migrate:reset', $this->migrateOptions);
    } catch (Throwable) {
    } finally {
        dropUpgradeTables();
        Date::setTestNow();
        app()->useDatabasePath($this->oldDatabasePath);
        File::deleteDirectory($this->root);
    }
});

/** A server database outlives the test, so start and end without these tables. */
function dropUpgradeTables(): void
{
    foreach (['scarlett_clips', 'scarlett_view_errors', 'scarlett_beacon_events', 'scarlett_views'] as $table) {
        Schema::dropIfExists($table);
    }
    if (Schema::hasTable('migrations')) {
        DB::table('migrations')->where('migration', 'like', '%scarlett%')->delete();
    }
}

/** @return list<string> */
function upgradeFiles(string $destination): array
{
    return array_map('basename', glob($destination.'/*.php'));
}

it('runs a fresh migrate on a host with dated create migrations and all published upgrades', function (): void {
    Date::setTestNow('2026-09-29 12:00:00');
    $this->artisan('vendor:publish', ['--tag' => 'scarlett-test-legacy'])->assertSuccessful();
    Date::setTestNow('2026-09-30 12:00:00');
    $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-signals'])->assertSuccessful();
    Date::setTestNow('2026-10-06 12:00:00');
    $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-reconnects'])->assertSuccessful();
    Date::setTestNow('2026-10-09 12:00:00');
    $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-gauges'])->assertSuccessful();

    expect(upgradeFiles($this->destination))->toBe([
        '2026_09_29_120001_create_scarlett_views_table.php',
        '2026_09_29_120002_create_scarlett_beacon_events_table.php',
        '2026_09_29_120003_create_scarlett_view_errors_table.php',
        '2026_09_29_120004_create_scarlett_clips_table.php',
        '2026_09_30_120001_add_signals_to_scarlett_tables.php',
        '2026_10_06_120001_add_reconnects_to_scarlett_tables.php',
        '2026_10_09_120001_add_gauges_to_scarlett_tables.php',
    ]);

    // A new environment: nothing has run yet.
    $this->artisan('migrate', $this->migrateOptions)->assertSuccessful();

    expect(upgradeColumnsPresent())->toBeTrue()
        ->and(DB::table('migrations')->where('migration', 'like', '%scarlett%')->count())->toBe(7);

    $this->artisan('migrate:reset', $this->migrateOptions)->assertSuccessful();
    expect(Schema::hasTable('scarlett_views'))->toBeFalse()
        ->and(Schema::hasTable('scarlett_view_errors'))->toBeFalse();
});

/** The create migrations dated and migrated, as on a host before the upgrades. */
function migratedUpgradeHost(object $test): void
{
    Date::setTestNow('2026-09-29 12:00:00');
    $test->artisan('vendor:publish', ['--tag' => 'scarlett-test-legacy'])->assertSuccessful();
    $test->artisan('migrate', $test->migrateOptions)->assertSuccessful();
    DB::table('scarlett_views')->insert([
        ['view_id' => 'scored', 'session_id' => 's', 'viewer_id' => 'v', 'video_id' => 'm', 'qoe_score' => 65],
        ['view_id' => 'unscored', 'session_id' => 's', 'viewer_id' => 'v', 'video_id' => 'm', 'qoe_score' => null],
    ]);
}

function upgradeQoeVersion(string $viewId): ?int
{
    $value = DB::table('scarlett_views')->where('view_id', $viewId)->value('qoe_version');

    return $value === null ? null : (int) $value;
}

function upgradeColumnsPresent(): bool
{
    return Schema::hasColumns('scarlett_views', [...UPGRADE_SIGNALS_VIEWS, ...UPGRADE_RECONNECTS_VIEWS, ...UPGRADE_GAUGES_VIEWS])
        && Schema::hasColumns('scarlett_view_errors', [...UPGRADE_SIGNALS_ERRORS, ...UPGRADE_RECONNECTS_ERRORS]);
}

function upgradeColumnsAbsent(): bool
{
    $views = Schema::getColumnListing('scarlett_views');
    $errors = Schema::getColumnListing('scarlett_view_errors');

    return array_intersect($views, [...UPGRADE_SIGNALS_VIEWS, ...UPGRADE_RECONNECTS_VIEWS, ...UPGRADE_GAUGES_VIEWS]) === []
        && array_intersect($errors, [...UPGRADE_SIGNALS_ERRORS, ...UPGRADE_RECONNECTS_ERRORS]) === [];
}

it('upgrades an existing migrated database and rolls each upgrade back', function (): void {
    migratedUpgradeHost($this);

    Date::setTestNow('2026-09-30 12:00:00');
    $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-signals'])->assertSuccessful();
    $this->artisan('migrate', $this->migrateOptions)->assertSuccessful();
    expect(upgradeQoeVersion('scored'))->toBe(1)
        ->and(upgradeQoeVersion('unscored'))->toBeNull();

    Date::setTestNow('2026-10-06 12:00:00');
    $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-reconnects'])->assertSuccessful();
    $this->artisan('migrate', $this->migrateOptions)->assertSuccessful();

    Date::setTestNow('2026-10-09 12:00:00');
    $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-gauges'])->assertSuccessful();
    $this->artisan('migrate', $this->migrateOptions)->assertSuccessful();
    expect(upgradeColumnsPresent())->toBeTrue();

    $this->artisan('migrate:rollback', [...$this->migrateOptions, '--step' => 1])->assertSuccessful();
    expect(Schema::hasColumns('scarlett_views', [...UPGRADE_SIGNALS_VIEWS, ...UPGRADE_RECONNECTS_VIEWS]))->toBeTrue()
        ->and(array_intersect(Schema::getColumnListing('scarlett_views'), UPGRADE_GAUGES_VIEWS))->toBe([]);
    $this->artisan('migrate:rollback', [...$this->migrateOptions, '--step' => 1])->assertSuccessful();
    expect(Schema::hasColumns('scarlett_views', UPGRADE_SIGNALS_VIEWS))->toBeTrue()
        ->and(array_intersect(Schema::getColumnListing('scarlett_views'), UPGRADE_RECONNECTS_VIEWS))->toBe([])
        ->and(array_intersect(Schema::getColumnListing('scarlett_view_errors'), UPGRADE_RECONNECTS_ERRORS))->toBe([]);

    $this->artisan('migrate:rollback', [...$this->migrateOptions, '--step' => 1])->assertSuccessful();
    expect(upgradeColumnsAbsent())->toBeTrue()
        ->and(DB::table('scarlett_views')->count())->toBe(2);
});

it('migrates a repeat publish as a no-op that backfills nothing and keeps the columns on rollback', function (): void {
    migratedUpgradeHost($this);

    Date::setTestNow('2026-09-30 12:00:00');
    $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-signals'])->assertSuccessful();
    $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-reconnects'])->assertSuccessful();
    $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-gauges'])->assertSuccessful();
    $this->artisan('migrate', $this->migrateOptions)->assertSuccessful();

    // A later score the store wrote without a version must not become v1.
    DB::table('scarlett_views')->where('view_id', 'unscored')->update(['qoe_score' => 80]);

    Date::setTestNow('2026-10-07 12:00:00');
    $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-signals'])->assertSuccessful();
    $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-reconnects'])->assertSuccessful();
    $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-gauges'])->assertSuccessful();
    expect(array_values(array_filter(upgradeFiles($this->destination), fn (string $file): bool => ! str_contains($file, '_create_'))))->toBe([
        // Each command dates from its own start; the three upgrades are independent.
        '2026_09_30_120001_add_gauges_to_scarlett_tables.php',
        '2026_09_30_120001_add_reconnects_to_scarlett_tables.php',
        '2026_09_30_120001_add_signals_to_scarlett_tables.php',
        '2026_10_07_120001_add_gauges_to_scarlett_tables.php',
        '2026_10_07_120001_add_reconnects_to_scarlett_tables.php',
        '2026_10_07_120001_add_signals_to_scarlett_tables.php',
    ]);
    $this->artisan('migrate', $this->migrateOptions)->assertSuccessful();
    expect(upgradeQoeVersion('scored'))->toBe(1)
        ->and(upgradeQoeVersion('unscored'))->toBeNull()
        ->and(DB::table('migrations')->where('migration', 'like', '%scarlett%')->count())->toBe(10);

    // Rolling back the duplicates leaves the columns the first copies added.
    $this->artisan('migrate:rollback', [...$this->migrateOptions, '--step' => 3])->assertSuccessful();
    expect(upgradeColumnsPresent())->toBeTrue();

    // The last copy of each takes its columns with it.
    $this->artisan('migrate:rollback', [...$this->migrateOptions, '--step' => 3])->assertSuccessful();
    expect(upgradeColumnsAbsent())->toBeTrue();
});

it('repairs a host that already ran a 0.5.0 fixed-name upgrade by deleting it and publishing again', function (): void {
    migratedUpgradeHost($this);

    // The 0.5.0 tags wrote these names; a database that ran them is correct.
    foreach ([UPGRADE_SIGNALS_FILE, UPGRADE_RECONNECTS_FILE] as $file) {
        File::copy(__DIR__.'/../../Fixtures/migrations/0.5.0/'.$file, $this->destination.'/'.$file);
    }
    $this->artisan('migrate', $this->migrateOptions)->assertSuccessful();
    expect(Schema::hasColumns('scarlett_views', [...UPGRADE_SIGNALS_VIEWS, ...UPGRADE_RECONNECTS_VIEWS]))->toBeTrue()
        ->and(upgradeQoeVersion('scored'))->toBe(1);
    $this->artisan('scarlett:doctor')->expectsOutputToContain('0001_01_01_000005_add_signals_to_scarlett_tables.php sorts before');

    // While the fixed-name file exists, the framework skips the publish.
    $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-signals'])->assertSuccessful();
    expect(upgradeFiles($this->destination))->toContain(UPGRADE_SIGNALS_FILE)->toHaveCount(6);

    // The documented repair.
    DB::table('scarlett_views')->where('view_id', 'unscored')->update(['qoe_score' => 80]);
    File::delete([$this->destination.'/'.UPGRADE_SIGNALS_FILE, $this->destination.'/'.UPGRADE_RECONNECTS_FILE]);
    Date::setTestNow('2026-10-07 12:00:00');
    $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-signals'])->assertSuccessful();
    $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-reconnects'])->assertSuccessful();
    $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-gauges'])->assertSuccessful();
    expect(upgradeFiles($this->destination))->toContain('2026_10_07_120001_add_signals_to_scarlett_tables.php', '2026_10_07_120001_add_reconnects_to_scarlett_tables.php', '2026_10_07_120001_add_gauges_to_scarlett_tables.php')
        ->not->toContain(UPGRADE_SIGNALS_FILE, UPGRADE_RECONNECTS_FILE);
    $this->artisan('migrate', $this->migrateOptions)->assertSuccessful();
    expect(upgradeColumnsPresent())->toBeTrue()
        ->and(upgradeQoeVersion('unscored'))->toBeNull();
    $this->artisan('scarlett:doctor')->expectsOutputToContain('every scarlett upgrade migration sorts after');

    // The old rows still record the columns, so rolling back the dated copies
    // keeps them; the sole gauges copy takes its columns with it.
    $this->artisan('migrate:rollback', [...$this->migrateOptions, '--step' => 3])->assertSuccessful();
    expect(Schema::hasColumns('scarlett_views', [...UPGRADE_SIGNALS_VIEWS, ...UPGRADE_RECONNECTS_VIEWS]))->toBeTrue()
        ->and(array_intersect(Schema::getColumnListing('scarlett_views'), UPGRADE_GAUGES_VIEWS))->toBe([]);

    // A new environment now migrates from nothing.
    dropUpgradeTables();
    $this->artisan('migrate', $this->migrateOptions)->assertSuccessful();
    expect(upgradeColumnsPresent())->toBeTrue()
        ->and(DB::table('migrations')->where('migration', 'like', '%scarlett%')->count())->toBe(7);
});

it('migrates as a no-op where the host added the columns in its own migration', function (string $hostName, bool $keepsColumns): void {
    migratedUpgradeHost($this);

    // tsp-web's pattern: the 0.5.0 bodies copied into host-dated migrations.
    File::copy(__DIR__.'/../../Fixtures/migrations/0.5.0/'.UPGRADE_SIGNALS_FILE, $this->destination.'/2026_09_30_100000_'.$hostName.'signals_to_scarlett_tables.php');
    File::copy(__DIR__.'/../../Fixtures/migrations/0.5.0/'.UPGRADE_RECONNECTS_FILE, $this->destination.'/2026_10_06_110000_'.$hostName.'reconnects_to_scarlett_tables.php');
    $this->artisan('migrate', $this->migrateOptions)->assertSuccessful();
    DB::table('scarlett_views')->where('view_id', 'unscored')->update(['qoe_score' => 80]);

    Date::setTestNow('2026-10-07 12:00:00');
    $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-signals'])->assertSuccessful();
    $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-reconnects'])->assertSuccessful();
    $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-gauges'])->assertSuccessful();
    $this->artisan('migrate', $this->migrateOptions)->assertSuccessful();
    expect(upgradeColumnsPresent())->toBeTrue()
        ->and(upgradeQoeVersion('scored'))->toBe(1)
        ->and(upgradeQoeVersion('unscored'))->toBeNull();

    // Only a host migration under the package's name counts as another copy:
    // the second signals/reconnects copies then keep their columns, while the
    // sole gauges copy rolls its own back.
    $this->artisan('migrate:rollback', [...$this->migrateOptions, '--step' => 3])->assertSuccessful();
    expect(Schema::hasColumns('scarlett_views', [...UPGRADE_SIGNALS_VIEWS, ...UPGRADE_RECONNECTS_VIEWS]))->toBe($keepsColumns)
        ->and(array_intersect(Schema::getColumnListing('scarlett_views'), UPGRADE_GAUGES_VIEWS))->toBe([]);
})->with([
    'same name as the package' => ['add_', true],
    'a host name' => ['host_', false],
]);

it('adds only the missing columns on a partial schema and backfills only with qoe_version', function (bool $hasQoeVersion): void {
    migratedUpgradeHost($this);
    Schema::table('scarlett_views', function ($table) use ($hasQoeVersion): void {
        if ($hasQoeVersion) {
            $table->unsignedInteger('qoe_version')->nullable();
        }
        $table->double('segment_count')->nullable();
        $table->unsignedInteger('reconnect_count')->nullable();
    });
    Schema::table('scarlett_view_errors', function ($table): void {
        $table->string('category')->nullable();
        $table->boolean('online')->nullable();
    });

    Date::setTestNow('2026-10-07 12:00:00');
    $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-signals'])->assertSuccessful();
    $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-reconnects'])->assertSuccessful();
    $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-gauges'])->assertSuccessful();
    $this->artisan('migrate', $this->migrateOptions)->assertSuccessful();

    expect(upgradeColumnsPresent())->toBeTrue()
        ->and(upgradeQoeVersion('scored'))->toBe($hasQoeVersion ? null : 1)
        ->and(upgradeQoeVersion('unscored'))->toBeNull();

    // A sole copy rolls back every upgrade column that exists, as 0.5.0 did.
    $this->artisan('migrate:rollback', [...$this->migrateOptions, '--step' => 3])->assertSuccessful();
    expect(upgradeColumnsAbsent())->toBeTrue();
})->with([
    'without qoe_version' => [false],
    'with qoe_version' => [true],
]);

it('publishes all seven for a fresh install and adds only no-op copies when an upgrade tag follows', function (): void {
    Date::setTestNow('2026-10-07 12:00:00');
    $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations'])->assertSuccessful();
    expect(array_slice(upgradeFiles($this->destination), 0, 7))->toBe([
        '2026_10_07_120001_create_scarlett_views_table.php',
        '2026_10_07_120002_create_scarlett_beacon_events_table.php',
        '2026_10_07_120003_create_scarlett_view_errors_table.php',
        '2026_10_07_120004_create_scarlett_clips_table.php',
        '2026_10_07_120005_add_signals_to_scarlett_tables.php',
        '2026_10_07_120006_add_reconnects_to_scarlett_tables.php',
        '2026_10_07_120007_add_gauges_to_scarlett_tables.php',
    ]);
    $this->artisan('migrate', $this->migrateOptions)->assertSuccessful();
    expect(upgradeColumnsPresent())->toBeTrue();
    $this->artisan('scarlett:doctor')->expectsOutputToContain('every scarlett upgrade migration sorts after');

    Date::setTestNow('2026-10-08 12:00:00');
    $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-signals'])->assertSuccessful();
    $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-reconnects'])->assertSuccessful();
    $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations-gauges'])->assertSuccessful();
    $this->artisan('migrate', $this->migrateOptions)->assertSuccessful();
    expect(upgradeColumnsPresent())->toBeTrue();

    // Fresh again: every copy sorts after the create migrations.
    $this->artisan('migrate:reset', $this->migrateOptions)->assertSuccessful();
    expect(Schema::hasTable('scarlett_views'))->toBeFalse();
    $this->artisan('migrate', $this->migrateOptions)->assertSuccessful();
    expect(upgradeColumnsPresent())->toBeTrue();
});

it('publishes every migration tag in one command in a migratable order', function (): void {
    // The same path set `--provider` publishes for migrations.
    Date::setTestNow('2026-10-07 12:00:00');
    $this->artisan('vendor:publish', ['--tag' => ['scarlett-migrations', 'scarlett-migrations-signals', 'scarlett-migrations-reconnects', 'scarlett-migrations-gauges']])->assertSuccessful();
    expect(array_values(array_filter(upgradeFiles($this->destination), fn (string $file): bool => str_contains($file, '_scarlett_'))))->toBe([
        '2026_10_07_120001_create_scarlett_views_table.php',
        '2026_10_07_120002_create_scarlett_beacon_events_table.php',
        '2026_10_07_120003_create_scarlett_view_errors_table.php',
        '2026_10_07_120004_create_scarlett_clips_table.php',
        '2026_10_07_120005_add_signals_to_scarlett_tables.php',
        '2026_10_07_120006_add_reconnects_to_scarlett_tables.php',
        '2026_10_07_120007_add_gauges_to_scarlett_tables.php',
        '2026_10_07_120008_add_signals_to_scarlett_tables.php',
        '2026_10_07_120009_add_reconnects_to_scarlett_tables.php',
        '2026_10_07_120010_add_gauges_to_scarlett_tables.php',
    ]);
    $this->artisan('migrate', $this->migrateOptions)->assertSuccessful();
    expect(upgradeColumnsPresent())->toBeTrue();
});

it('does not warn when the create migrations carry fixed names too, or no migrations directory exists', function (): void {
    $check = app(BeaconUpgradeMigrationOrderCheck::class);

    // update_date_on_publish off: every file keeps its fixed name and sorts in order.
    foreach (glob(__DIR__.'/../../../database/migrations/*.php') as $file) {
        File::copy($file, $this->destination.'/'.basename($file));
    }
    expect($check->run()->status)->toBe(CheckStatus::Pass);

    File::deleteDirectory($this->destination);
    expect($check->run()->status)->toBe(CheckStatus::Pass);
});

/** Copy the four create migrations into a directory under dated names. */
function upgradeDatedCreates(string $directory, string $stamp): void
{
    File::ensureDirectoryExists($directory);
    foreach (glob(__DIR__.'/../../../database/migrations/0001_01_01_00000[1234]_*.php') as $i => $file) {
        File::copy($file, $directory.'/'.$stamp.sprintf('%02d', $i + 1).substr(basename($file), 17));
    }
}

function upgradeOrderCheck(): CheckResult
{
    return app(BeaconUpgradeMigrationOrderCheck::class)->run();
}

it('fails with a named explanation when a same-second upgrade publish sorts before the create migrations', function (string $tag, string $upgrade): void {
    // Each publish command starts its own clock, so both files get ..._120001_.
    Date::setTestNow('2026-10-07 12:00:00');
    $this->artisan('vendor:publish', ['--tag' => 'scarlett-migrations'])->assertSuccessful();
    $this->artisan('vendor:publish', ['--tag' => $tag])->assertSuccessful();
    $copy = '2026_10_07_120001_'.$upgrade.'.php';
    expect(upgradeFiles($this->destination)[0])->toBe($copy);

    $result = upgradeOrderCheck();
    expect($result->status)->toBe(CheckStatus::Warn)
        ->and($result->message)->toContain($copy.' sorts before 2026_10_07_120001_create_scarlett_views_table.php');

    expect(fn () => Artisan::call('migrate', $this->migrateOptions))
        ->toThrow(UpgradeMigrationOrderException::class, $copy);
    expect(Schema::hasTable('scarlett_views'))->toBeFalse();

    // The folder already holds this upgrade: delete the extra copy.
    File::delete($this->destination.'/'.$copy);
    expect(upgradeOrderCheck()->status)->toBe(CheckStatus::Pass);
    $this->artisan('migrate', $this->migrateOptions)->assertSuccessful();
    expect(upgradeColumnsPresent())->toBeTrue();
})->with([
    'signals' => ['scarlett-migrations-signals', 'add_signals_to_scarlett_tables'],
    'reconnects' => ['scarlett-migrations-reconnects', 'add_reconnects_to_scarlett_tables'],
    'gauges' => ['scarlett-migrations-gauges', 'add_gauges_to_scarlett_tables'],
]);

it('checks every table before altering one when an upgrade sorts between the create migrations', function (): void {
    upgradeDatedCreates($this->destination, '2026_09_29_1200');
    // After create_scarlett_views, before create_scarlett_view_errors.
    $copy = '2026_09_29_120001_x_add_signals_to_scarlett_tables.php';
    File::copy(__DIR__.'/../../../database/migrations/'.UPGRADE_SIGNALS_FILE, $this->destination.'/'.$copy);

    expect(upgradeOrderCheck()->message)->toContain($copy.' sorts before 2026_09_29_120003_create_scarlett_view_errors_table.php')
        ->not->toContain('create_scarlett_views_table');
    expect(fn () => Artisan::call('migrate', $this->migrateOptions))
        ->toThrow(UpgradeMigrationOrderException::class, 'scarlett_view_errors');
    expect(Schema::hasTable('scarlett_views'))->toBeTrue()
        ->and(Schema::hasColumn('scarlett_views', 'qoe_version'))->toBeFalse();
});

it('warns about a fixed-name upgrade in the default directory when the creates live in a registered path', function (): void {
    $custom = $this->root.'/custom-migrations';
    upgradeDatedCreates($custom, '2026_09_29_1200');
    app('migrator')->path($custom);
    File::copy(__DIR__.'/../../Fixtures/migrations/0.5.0/'.UPGRADE_SIGNALS_FILE, $this->destination.'/'.UPGRADE_SIGNALS_FILE);

    $result = upgradeOrderCheck();
    expect($result->status)->toBe(CheckStatus::Warn)
        ->and($result->message)->toContain(UPGRADE_SIGNALS_FILE.' sorts before 2026_09_29_120001_create_scarlett_views_table.php');
});

it('names only the misordered copy when an upgrade has duplicates', function (): void {
    upgradeDatedCreates($this->destination, '2026_09_29_1200');
    File::copy(__DIR__.'/../../../database/migrations/'.UPGRADE_SIGNALS_FILE, $this->destination.'/2026_09_28_120000_add_signals_to_scarlett_tables.php');
    File::copy(__DIR__.'/../../../database/migrations/'.UPGRADE_SIGNALS_FILE, $this->destination.'/2026_09_30_120000_add_signals_to_scarlett_tables.php');
    File::copy(__DIR__.'/../../../database/migrations/'.UPGRADE_RECONNECTS_FILE, $this->destination.'/2026_10_06_120000_add_reconnects_to_scarlett_tables.php');

    $result = upgradeOrderCheck();
    expect($result->status)->toBe(CheckStatus::Warn)
        ->and($result->message)->toContain('2026_09_28_120000_add_signals_to_scarlett_tables.php')
        ->not->toContain('2026_09_30_120000_add_signals')
        ->not->toContain('add_reconnects');
});

it('lets the gauges upgrade sort between the views and errors creates, which it does not need', function (): void {
    $source = __DIR__.'/../../../database/migrations/';
    File::copy($source.'0001_01_01_000001_create_scarlett_views_table.php', $this->destination.'/2026_10_09_100000_create_scarlett_views_table.php');
    File::copy($source.UPGRADE_GAUGES_FILE, $this->destination.'/2026_10_09_100001_add_gauges_to_scarlett_tables.php');
    File::copy($source.'0001_01_01_000003_create_scarlett_view_errors_table.php', $this->destination.'/2026_10_09_100002_create_scarlett_view_errors_table.php');

    expect(upgradeOrderCheck()->status)->toBe(CheckStatus::Pass);
    $this->artisan('migrate', $this->migrateOptions)->assertSuccessful();
    expect(Schema::hasColumns('scarlett_views', UPGRADE_GAUGES_VIEWS))->toBeTrue();

    // The signals and reconnects upgrades in the same place still warn: they alter both tables.
    File::copy($source.UPGRADE_RECONNECTS_FILE, $this->destination.'/2026_10_09_100001_add_reconnects_to_scarlett_tables.php');
    $result = upgradeOrderCheck();
    expect($result->status)->toBe(CheckStatus::Warn)
        ->and($result->message)->toContain('2026_10_09_100001_add_reconnects_to_scarlett_tables.php sorts before 2026_10_09_100002_create_scarlett_view_errors_table.php')
        ->not->toContain('add_gauges');
});

it('passes for ordered layouts, missing directories, no create migrations and other stores', function (): void {
    upgradeDatedCreates($this->destination, '2026_09_29_1200');
    File::copy(__DIR__.'/../../../database/migrations/'.UPGRADE_SIGNALS_FILE, $this->destination.'/2026_09_30_120000_add_signals_to_scarlett_tables.php');
    expect(upgradeOrderCheck()->status)->toBe(CheckStatus::Pass);

    // A registered path that does not exist is ignored.
    app('migrator')->path($this->root.'/missing');
    expect(upgradeOrderCheck()->status)->toBe(CheckStatus::Pass);

    // A misordered copy warns, unless the bound store is not Eloquent.
    File::copy(__DIR__.'/../../../database/migrations/'.UPGRADE_SIGNALS_FILE, $this->destination.'/'.UPGRADE_SIGNALS_FILE);
    expect(upgradeOrderCheck()->status)->toBe(CheckStatus::Warn);
    app()->instance(BeaconStore::class, new NullBeaconStore);
    expect(upgradeOrderCheck()->status)->toBe(CheckStatus::Pass);
    app()->forgetInstance(BeaconStore::class);

    foreach (glob($this->destination.'/*_create_scarlett_*.php') as $create) {
        File::delete($create);
    }
    expect(upgradeOrderCheck()->status)->toBe(CheckStatus::Pass);

    File::deleteDirectory($this->destination);
    expect(upgradeOrderCheck()->status)->toBe(CheckStatus::Pass);
});
