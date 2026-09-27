<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The three beacon tables. Publish-only in a host; tests load them
 * through usesMigrations().
 */

it('creates the three beacon tables', function (): void {
    $this->usesMigrations();

    expect(Schema::hasTable('scarlett_views'))->toBeTrue()
        ->and(Schema::hasTable('scarlett_beacon_events'))->toBeTrue()
        ->and(Schema::hasTable('scarlett_view_errors'))->toBeTrue()
        ->and(Schema::hasColumns('scarlett_views', [
            'view_id', 'session_id', 'viewer_id', 'video_id', 'viewable_type', 'viewable_id',
            'video_title', 'is_live', 'player_version', 'player_name', 'browser', 'os', 'device_type',
            'screen_size', 'player_size', 'connection_type', 'started_at', 'first_frame_at', 'ended_at',
            'last_event_at', 'exit_type', 'startup_ms', 'watch_ms', 'play_ms', 'rebuffer_ms',
            'rebuffer_count', 'seek_count', 'pause_count', 'quality_changes', 'error_count',
            'max_bitrate', 'qoe_score', 'avg_bitrate', 'rebuffer_ratio', 'completion_rate',
            'current_position', 'metrics_at', 'custom', 'custom_at', 'created_at', 'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('scarlett_beacon_events', ['view_id', 'event', 'event_key', 'occurred_at', 'payload', 'received_at']))->toBeTrue()
        ->and(Schema::hasColumns('scarlett_view_errors', ['view_id', 'video_id', 'event_key', 'type', 'message', 'code', 'fatal', 'occurred_at', 'received_at']))->toBeTrue();
});

it('has no IP column at all unless store_ip is on when the migration runs', function (): void {
    $this->usesMigrations();

    expect(Schema::hasColumn('scarlett_views', 'ip_address'))->toBeFalse();
});

it('adds the IP column when store_ip is on at migration time', function (): void {
    config()->set('scarlett-player.beacons.store_ip', true);
    $this->usesMigrations();

    expect(Schema::hasColumn('scarlett_views', 'ip_address'))->toBeTrue();
});

it('makes view_id and each event_key unique', function (string $table, array $row): void {
    $this->usesMigrations();

    DB::table($table)->insert($row);

    expect(fn () => DB::table($table)->insert($row))->toThrow(UniqueConstraintViolationException::class);
})->with([
    'views' => ['scarlett_views', ['view_id' => 'v', 'session_id' => 's', 'viewer_id' => 'x', 'video_id' => 'm']],
    'beacon events' => ['scarlett_beacon_events', [
        'view_id' => 'v', 'event' => 'heartbeat', 'event_key' => str_repeat('a', 40),
        'occurred_at' => '2026-01-01 00:00:00.000', 'payload' => '{}', 'received_at' => '2026-01-01 00:00:00.000',
    ]],
    'view errors' => ['scarlett_view_errors', [
        'view_id' => 'v', 'video_id' => 'm', 'event_key' => str_repeat('b', 40),
        'occurred_at' => '2026-01-01 00:00:00.000', 'received_at' => '2026-01-01 00:00:00.000',
    ]],
]);

it('ships one migration file per table, numbered in the plan schema order', function (): void {
    $files = array_map('basename', glob(dirname(__DIR__, 3).'/database/migrations/0001_01_01_00000[123]_*.php') ?: []);

    expect($files)->toBe([
        '0001_01_01_000001_create_scarlett_views_table.php',
        '0001_01_01_000002_create_scarlett_beacon_events_table.php',
        '0001_01_01_000003_create_scarlett_view_errors_table.php',
    ]);
});
