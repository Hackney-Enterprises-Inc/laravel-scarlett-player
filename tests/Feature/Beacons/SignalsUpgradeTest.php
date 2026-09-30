<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Contracts\BeaconStore;
use Hei\ScarlettPlayer\Doctor\CheckRegistry;
use Hei\ScarlettPlayer\Doctor\Checks\BeaconSignalsColumnsCheck;
use Hei\ScarlettPlayer\Doctor\CheckStatus;
use Hei\ScarlettPlayer\Stores\NullBeaconStore;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\Beacons;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->usesMigrations();
    config()->set('queue.default', 'sync');
    $this->signalsMigration = require __DIR__.'/../../../database/migrations/0001_01_01_000005_add_signals_to_scarlett_tables.php';
});

it('ingests legacy captured beacons and signals before the migration, on both insert and merge', function (bool $raw): void {
    $this->signalsMigration->down();
    config()->set('scarlett-player.beacons.store_raw_events', $raw);
    config()->set('scarlett-player.beacons.key', 'wire-capture-key');
    try {
        $store = app(BeaconStore::class);
        foreach (glob(__DIR__.'/../../Fixtures/wire/1.19.1/*.json') as $path) {
            $request = json_decode(file_get_contents($path), true)['request'] ?? [];
            $body = $request['body'] ?? [];
            if (! isset($body['event'])) {
                continue;
            }
            foreach ([1, 2] as $delivery) {
                $this->postJson('/api/scarlett/beacons', $body, ['X-API-Key' => 'wire-capture-key'])->assertNoContent();
            }
        }
        foreach (['heartbeat', 'error', 'viewEnd'] as $event) {
            $payload = Beacons::payload($event, 20, [
                'qoeScore' => 55, 'qoeVersion' => 2, 'warningCount' => 4,
                'pageUrl' => 'https://example.test/watch', 'anonymous' => true,
                'segmentCount' => 3, 'segmentBytes' => 30, 'decodedFrames' => 4,
                'errorCategory' => 'network', 'httpStatus' => 503, 'timedOut' => true,
            ], 'signals-without-schema');
            $store->record($payload);
            $store->record($payload);
        }
        expect(DB::table('scarlett_views')->where('view_id', 'signals-without-schema')->value('qoe_score'))->toBeNull()
            ->and(DB::table('scarlett_views')->where('view_id', '!=', 'signals-without-schema')->whereNotNull('qoe_score')->exists())->toBeTrue()
            ->and(DB::table('scarlett_view_errors')->where('view_id', 'signals-without-schema')->count())->toBe(1);
        if ($raw) {
            $json = json_decode(DB::table('scarlett_beacon_events')->where('view_id', 'signals-without-schema')->where('event', 'error')->value('payload'), true);
            expect($json)->toMatchArray(['qoeVersion' => 2, 'warningCount' => 4, 'errorCategory' => 'network']);
        }
        expect(app(BeaconSignalsColumnsCheck::class)->run()->status)->toBe(CheckStatus::Warn);
    } finally {
        $this->signalsMigration->up();
    }
})->with([true, false]);

it('handles a partially upgraded schema and refreshes cached columns with a new store instance', function (): void {
    Schema::table('scarlett_views', function ($table): void {
        $table->dropColumn('qoe_version');
        $table->dropColumn('segment_count_at');
    });
    Schema::table('scarlett_view_errors', fn ($table) => $table->dropColumn('http_status'));
    try {
        $store = app(BeaconStore::class);
        $payload = Beacons::payload('error', 0, ['qoeScore' => 80, 'qoeVersion' => 2, 'segmentCount' => 3, 'errorCategory' => 'access', 'httpStatus' => 403]);
        $store->record($payload);
        $store->record($payload);
        $result = app(BeaconSignalsColumnsCheck::class)->run();
        expect($result->status)->toBe(CheckStatus::Warn)->and($result->message)->toContain('qoe_version', 'http_status', 'scarlett-migrations-signals');
        expect(DB::table('scarlett_views')->sole()->segment_count)->toBeNull()
            ->and(DB::table('scarlett_view_errors')->sole()->category)->toBe('access');
    } finally {
        Schema::table('scarlett_views', function ($table): void {
            $table->unsignedInteger('qoe_version')->nullable();
            $table->dateTime('segment_count_at', 3)->nullable();
        });
        Schema::table('scarlett_view_errors', fn ($table) => $table->unsignedInteger('http_status')->nullable());
    }
    $next = Beacons::payload('heartbeat', 10, ['qoeScore' => 75, 'qoeVersion' => 2]);
    $store->record($next);
    expect(DB::table('scarlett_views')->sole()->qoe_version)->toBeNull();
    app()->forgetInstance(BeaconStore::class);
    app(BeaconStore::class)->record(Beacons::payload('heartbeat', 20, ['qoeScore' => 75, 'qoeVersion' => 2]));
    expect((int) DB::table('scarlett_views')->sole()->qoe_version)->toBe(2);
});

it('registers a signals doctor check which passes for complete schemas and non-Eloquent stores', function (): void {
    expect(app(CheckRegistry::class)->classes())->toContain(BeaconSignalsColumnsCheck::class)
        ->and(app(BeaconSignalsColumnsCheck::class)->run()->status)->toBe(CheckStatus::Pass);
    app()->instance(BeaconStore::class, new NullBeaconStore);
    expect(app(BeaconSignalsColumnsCheck::class)->run()->status)->toBe(CheckStatus::Pass);
});
