<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Data\BeaconPayload;
use Hei\ScarlettPlayer\Jobs\ProcessBeacon;
use Hei\ScarlettPlayer\Stores\EloquentBeaconStore;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\Beacons;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * scarlett:views:backfill-gauges fills the canonical ratio columns from the
 * legacy gauge columns, dry run first. A gauge's scale comes only from its
 * own retained raw evidence (the beacon that wrote the legacy value, matched
 * by the legacy stamp and value), never from the row's set-once version or a
 * value's magnitude. A canonical stamp marks a measurement processed, so a
 * rerun never divides twice or overwrites a fresher measurement.
 */

beforeEach(function (): void {
    $this->usesMigrations();
    config()->set('queue.default', 'sync');
});

/** A stamp as the store writes it; engines render trailing zeros differently. */
function backfillStamp(object $row, string $column): ?string
{
    return $row->{$column} === null ? null : Carbon::parse((string) $row->{$column})->format('Y-m-d H:i:s.v');
}

/** Seed a view row with legacy gauge values and matching raw evidence rows. */
function seedBackfillView(
    string $viewId,
    ?float $completion,
    ?string $completionAt,
    ?float $rebuffer,
    ?string $rebufferAt,
    array $evidence = [],
    bool $live = false,
): void {
    DB::table('scarlett_views')->insert([
        'view_id' => $viewId,
        'session_id' => 's',
        'viewer_id' => 'v',
        'video_id' => 'm',
        'is_live' => $live,
        'completion_rate' => $completion,
        'completion_rate_at' => $completionAt,
        'rebuffer_ratio' => $rebuffer,
        'rebuffer_ratio_at' => $rebufferAt,
    ]);

    foreach ($evidence as $index => $payload) {
        DB::table('scarlett_beacon_events')->insert([
            'view_id' => $viewId,
            'event' => (string) ($payload['event'] ?? 'viewEnd'),
            'event_key' => sha1($viewId.(string) $index),
            'occurred_at' => $payload['occurred_at'],
            'payload' => (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'received_at' => '2026-10-09 12:00:00.000',
        ]);
    }
}

/** A raw viewEnd payload matching a legacy gauge value at its stamp. */
function evidencePayload(string $stamp, float $completionRate, array $extra = []): array
{
    return array_replace([
        'event' => 'viewEnd',
        'timestamp' => 1790000000000,
        'occurred_at' => $stamp,
        'viewId' => 'ignored',
        'playerName' => 'scarlett-player',
        'playerVersion' => '1.19.1',
        'completionRate' => $completionRate,
    ], $extra);
}

it('defaults to a dry run: nothing is written, the report names the buckets', function (): void {
    $stamp = EloquentBeaconStore::clientTime(Beacons::T0 + 10);
    seedBackfillView('proven', 37.5, $stamp, null, null, [
        evidencePayload($stamp, 37.5, ['playerVersion' => '1.22.0']),
    ]);

    $this->artisan('scarlett:views:backfill-gauges')
        ->expectsOutputToContain('dry run')
        ->expectsOutputToContain('proven percent')
        ->assertSuccessful();

    $row = DB::table('scarlett_views')->where('view_id', 'proven')->sole();
    expect($row->completion_ratio)->toBeNull()
        ->and($row->completion_ratio_at)->toBeNull();
});

it('fills proven percent and proven ratio from retained evidence with the legacy stamp', function (): void {
    $completionStamp = EloquentBeaconStore::clientTime(Beacons::T0 + 10);
    $rebufferStamp = EloquentBeaconStore::clientTime(Beacons::T0 + 20);
    seedBackfillView('proven', 37.5, $completionStamp, 44.44, $rebufferStamp, [
        evidencePayload($completionStamp, 37.5, ['playerVersion' => '1.22.0']),
        array_replace(evidencePayload($rebufferStamp, 0, ['playerVersion' => '1.22.0', 'gaugeScale' => 'percent']), [
            'completionRate' => null,
            'rebufferRatio' => 44.44,
        ]),
    ]);

    $this->artisan('scarlett:views:backfill-gauges', ['--apply' => true])->assertSuccessful();

    $row = DB::table('scarlett_views')->where('view_id', 'proven')->sole();
    expect((float) $row->completion_ratio)->toBe(0.375)
        ->and(backfillStamp($row, 'completion_ratio_at'))->toBe($completionStamp)
        ->and((float) $row->rebuffer_fraction)->toBe(0.4444)
        ->and(backfillStamp($row, 'rebuffer_fraction_at'))->toBe($rebufferStamp)
        // The legacy columns are preserved.
        ->and((float) $row->completion_rate)->toBe(37.5)
        ->and((float) $row->rebuffer_ratio)->toBe(44.44);
});

it('writes unavailable once for ambiguous and missing-evidence measurements', function (): void {
    $ambiguousStamp = EloquentBeaconStore::clientTime(Beacons::T0 + 10);
    $missingStamp = EloquentBeaconStore::clientTime(Beacons::T0 + 20);
    // No marker and an unaudited version: the evidence cannot prove a scale.
    seedBackfillView('ambiguous', 15.14, $ambiguousStamp, null, null, [
        evidencePayload($ambiguousStamp, 15.14),
    ]);
    // The raw retention is gone (raw storage off, pruned or excluded).
    seedBackfillView('missing', 50.0, $missingStamp, null, null, []);

    $this->artisan('scarlett:views:backfill-gauges', ['--apply' => true])
        ->expectsOutputToContain('ambiguous')
        ->expectsOutputToContain('missing raw evidence')
        ->assertSuccessful();

    foreach (['ambiguous', 'missing'] as $viewId) {
        $row = DB::table('scarlett_views')->where('view_id', $viewId)->sole();
        expect($row->completion_ratio)->toBeNull()
            ->and(backfillStamp($row, 'completion_ratio_at'))->toBe($viewId === 'ambiguous' ? $ambiguousStamp : $missingStamp);
    }
});

it('writes unavailable for an explicitly invalid marker in the evidence', function (): void {
    $stamp = EloquentBeaconStore::clientTime(Beacons::T0 + 10);
    seedBackfillView('invalid', 37.5, $stamp, null, null, [
        evidencePayload($stamp, 37.5, ['gaugeScale' => 'fractions']),
    ]);

    $this->artisan('scarlett:views:backfill-gauges', ['--apply' => true])
        ->expectsOutputToContain('invalid marker')
        ->assertSuccessful();

    $row = DB::table('scarlett_views')->where('view_id', 'invalid')->sole();
    expect($row->completion_ratio)->toBeNull()
        ->and(backfillStamp($row, 'completion_ratio_at'))->toBe($stamp);
});

it('writes a clamped value and reports it', function (): void {
    $stamp = EloquentBeaconStore::clientTime(Beacons::T0 + 10);
    seedBackfillView('clamped', 150.0, $stamp, null, null, [
        evidencePayload($stamp, 150.0, ['gaugeScale' => 'percent']),
    ]);

    $this->artisan('scarlett:views:backfill-gauges', ['--apply' => true])
        ->expectsOutputToContain('clamped')
        ->assertSuccessful();

    $row = DB::table('scarlett_views')->where('view_id', 'clamped')->sole();
    expect((float) $row->completion_ratio)->toBe(1.0)
        ->and(backfillStamp($row, 'completion_ratio_at'))->toBe($stamp);
});

it('stamps live rows unavailable and leaves unmeasured rows alone', function (): void {
    $liveStamp = EloquentBeaconStore::clientTime(Beacons::T0 + 10);
    seedBackfillView('live', 50.0, $liveStamp, null, null, [
        evidencePayload($liveStamp, 50.0, ['playerVersion' => '1.22.0']),
    ], live: true);
    // A legacy value without a stamp is an out-of-band write: insufficient provenance.
    DB::table('scarlett_views')->insert([
        'view_id' => 'nostamp', 'session_id' => 's', 'viewer_id' => 'v', 'video_id' => 'm',
        'completion_rate' => 25.0, 'completion_rate_at' => null,
    ]);
    // No legacy measurement at all: nothing to backfill, no processed stamp.
    DB::table('scarlett_views')->insert([
        'view_id' => 'never', 'session_id' => 's', 'viewer_id' => 'v', 'video_id' => 'm',
    ]);

    $this->artisan('scarlett:views:backfill-gauges', ['--apply' => true])
        ->expectsOutputToContain('insufficient provenance')
        ->assertSuccessful();

    $live = DB::table('scarlett_views')->where('view_id', 'live')->sole();
    expect($live->completion_ratio)->toBeNull()
        ->and(backfillStamp($live, 'completion_ratio_at'))->toBe($liveStamp);

    $nostamp = DB::table('scarlett_views')->where('view_id', 'nostamp')->sole();
    expect($nostamp->completion_ratio)->toBeNull()
        ->and($nostamp->completion_ratio_at)->toBeNull();

    $never = DB::table('scarlett_views')->where('view_id', 'never')->sole();
    expect($never->completion_ratio_at)->toBeNull()
        ->and($never->rebuffer_fraction_at)->toBeNull();
});

it('treats tied conflicting evidence as ambiguous', function (): void {
    $stamp = EloquentBeaconStore::clientTime(Beacons::T0 + 10);
    // Same stamp and value, but one beacon declares ratio and one percent:
    // 0.5 as ratio is 0.5, as percent 0.005. The tie cannot be resolved.
    seedBackfillView('tied', 0.5, $stamp, null, null, [
        evidencePayload($stamp, 0.5, ['gaugeScale' => 'ratio']),
        evidencePayload($stamp, 0.5, ['gaugeScale' => 'percent']),
    ]);

    $this->artisan('scarlett:views:backfill-gauges', ['--apply' => true])->assertSuccessful();

    $row = DB::table('scarlett_views')->where('view_id', 'tied')->sole();
    expect($row->completion_ratio)->toBeNull()
        ->and(backfillStamp($row, 'completion_ratio_at'))->toBe($stamp);
});

it('never divides twice and never overwrites a fresher measurement on rerun', function (): void {
    $stamp = EloquentBeaconStore::clientTime(Beacons::T0 + 10);
    seedBackfillView('rerun', 37.5, $stamp, null, null, [
        evidencePayload($stamp, 37.5, ['playerVersion' => '1.22.0']),
    ]);

    $this->artisan('scarlett:views:backfill-gauges', ['--apply' => true])->assertSuccessful();
    $row = DB::table('scarlett_views')->where('view_id', 'rerun')->sole();
    expect((float) $row->completion_ratio)->toBe(0.375);

    // A fresh beacon now writes a newer legacy measurement and its canonical
    // value through ingest; a rerun must leave both alone.
    app()->call([new ProcessBeacon(Beacons::payload('viewEnd', 20, [
        'exitType' => 'abandoned', 'completionRate' => 50, 'gaugeScale' => 'percent',
    ], 'rerun')), 'handle']);
    $row = DB::table('scarlett_views')->where('view_id', 'rerun')->sole();
    expect((float) $row->completion_ratio)->toBe(0.5)
        ->and(backfillStamp($row, 'completion_ratio_at'))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 20));

    $this->artisan('scarlett:views:backfill-gauges', ['--apply' => true])
        ->expectsOutputToContain('already processed')
        ->assertSuccessful();
    $row = DB::table('scarlett_views')->where('view_id', 'rerun')->sole();
    expect((float) $row->completion_ratio)->toBe(0.5)
        ->and((float) $row->completion_rate)->toBe(50.0);
});

it('leaves a pre-upgrade gauge to the backfill when a delayed older beacon arrives first', function (): void {
    config()->set('scarlett-player.beacons.store_raw_events', true);
    $legacyAt = EloquentBeaconStore::clientTime(Beacons::T0 + 20);
    seedBackfillView(Beacons::VIEW, 75.0, $legacyAt, null, null, [
        evidencePayload($legacyAt, 75.0, ['gaugeScale' => 'percent']),
    ]);

    // Older than the legacy measurement: the legacy column keeps 75, and the
    // canonical column must stay unstamped for the backfill to convert 75.
    app()->call([new ProcessBeacon(Beacons::payload('viewEnd', 10, ['exitType' => 'abandoned', 'completionRate' => 25, 'gaugeScale' => 'percent'])), 'handle']);
    $row = DB::table('scarlett_views')->where('view_id', Beacons::VIEW)->sole();
    expect($row->completion_ratio)->toBeNull()
        ->and($row->completion_ratio_at)->toBeNull();

    $this->artisan('scarlett:views:backfill-gauges', ['--apply' => true])->assertSuccessful();

    $row = DB::table('scarlett_views')->where('view_id', Beacons::VIEW)->sole();
    expect((float) $row->completion_rate)->toBe(75.0)
        ->and((float) $row->completion_ratio)->toBe(0.75)
        ->and(backfillStamp($row, 'completion_ratio_at'))->toBe($legacyAt);

    // A beacon at least as new as the legacy measurement still writes at once.
    app()->call([new ProcessBeacon(Beacons::payload('viewEnd', 30, ['exitType' => 'abandoned', 'completionRate' => 90, 'gaugeScale' => 'percent'])), 'handle']);
    expect((float) DB::table('scarlett_views')->where('view_id', Beacons::VIEW)->sole()->completion_ratio)->toBe(0.9);
});

it('lets a beacon newer than a pre-upgrade gauge write the canonical column straight away', function (): void {
    seedBackfillView(Beacons::VIEW, 75.0, EloquentBeaconStore::clientTime(Beacons::T0 + 20), null, null);

    app()->call([new ProcessBeacon(Beacons::payload('viewEnd', 20, ['exitType' => 'abandoned', 'completionRate' => 80, 'gaugeScale' => 'percent'])), 'handle']);

    $row = DB::table('scarlett_views')->where('view_id', Beacons::VIEW)->sole();
    expect((float) $row->completion_ratio)->toBe(0.8)
        ->and(backfillStamp($row, 'completion_ratio_at'))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 20));
});

/**
 * Ingest beacons on a schema without the gauge columns, as before the
 * upgrade, then add the columns: the backfill's input.
 *
 * @param  list<BeaconPayload>  $payloads
 */
function ingestBeforeGaugeColumns(array $payloads): void
{
    config()->set('scarlett-player.beacons.store_raw_events', true);
    $migration = require __DIR__.'/../../../database/migrations/0001_01_01_000007_add_gauges_to_scarlett_tables.php';
    $migration->down();

    try {
        foreach ($payloads as $payload) {
            app()->call([new ProcessBeacon($payload), 'handle']);
        }
    } finally {
        $migration->up();
    }
}

it('backfills an explicit null marker as invalid, as ingest stores it', function (): void {
    ingestBeforeGaugeColumns([Beacons::payload('viewEnd', 10, [
        'exitType' => 'abandoned', 'completionRate' => 50, 'gaugeScale' => null,
        'playerName' => 'scarlett-player', 'playerVersion' => '1.22.0',
    ])]);

    $this->artisan('scarlett:views:backfill-gauges', ['--apply' => true])
        ->expectsOutputToContain('invalid marker')
        ->assertSuccessful();

    $row = DB::table('scarlett_views')->where('view_id', Beacons::VIEW)->sole();
    expect((float) $row->completion_rate)->toBe(50.0)
        ->and($row->completion_ratio)->toBeNull()
        ->and(backfillStamp($row, 'completion_ratio_at'))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 10));
});

it('treats evidence whose scale keys the server context set as ambiguous', function (array $browser, array $server): void {
    // The raw log overlays the server's keys, which would prove a scale the
    // browser's own keys (what ingest reads) never established.
    ingestBeforeGaugeColumns([Beacons::payload('viewEnd', 10, [
        'exitType' => 'abandoned', 'completionRate' => 0.5, ...$browser,
    ])->withServer($server)]);

    $this->artisan('scarlett:views:backfill-gauges', ['--apply' => true])->assertSuccessful();

    $row = DB::table('scarlett_views')->where('view_id', Beacons::VIEW)->sole();
    expect($row->completion_ratio)->toBeNull()
        ->and(backfillStamp($row, 'completion_ratio_at'))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 10));
})->with([
    'marker' => [['playerName' => 'scarlett-player', 'playerVersion' => '1.22.0'], ['gaugeScale' => 'ratio']],
    'producer' => [['playerName' => 'other-player', 'playerVersion' => '1.22.0'], ['playerName' => 'scarlett-player']],
    'version' => [['playerName' => 'scarlett-player', 'playerVersion' => '1.21.0'], ['playerVersion' => '1.22.0']],
]);

it('proves the scale from an event newer than the server key it did not carry', function (): void {
    // The merged view map holds playerVersion from an earlier heartbeat; the
    // viewEnd is newer, so it carried none (it would have won the merge).
    ingestBeforeGaugeColumns([
        Beacons::payload('heartbeat', 5, [])->withServer(['playerVersion' => '1.21.0']),
        Beacons::payload('viewEnd', 10, [
            'exitType' => 'abandoned', 'completionRate' => 37.5,
            'playerName' => 'scarlett-player', 'playerVersion' => '1.22.0',
        ]),
    ]);

    $this->artisan('scarlett:views:backfill-gauges', ['--apply' => true])->assertSuccessful();

    $row = DB::table('scarlett_views')->where('view_id', Beacons::VIEW)->sole();
    expect((float) $row->completion_ratio)->toBe(0.375)
        ->and(backfillStamp($row, 'completion_ratio_at'))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 10));
});

it('leaves a measurement unprocessed when a later beacon set a server scale key', function (): void {
    // A later beacon wrote playerVersion to the merged map; whether the older
    // viewEnd carried it too cannot be proven, so nothing is stamped.
    ingestBeforeGaugeColumns([
        Beacons::payload('viewEnd', 10, [
            'exitType' => 'abandoned', 'completionRate' => 37.5,
            'playerName' => 'scarlett-player', 'playerVersion' => '1.22.0',
        ]),
        Beacons::payload('heartbeat', 20, [])->withServer(['playerVersion' => '1.21.0']),
    ]);

    $this->artisan('scarlett:views:backfill-gauges', ['--apply' => true])
        ->expectsOutputToContain('server ownership unknown')
        ->assertSuccessful();

    $row = DB::table('scarlett_views')->where('view_id', Beacons::VIEW)->sole();
    expect($row->completion_ratio)->toBeNull()
        ->and($row->completion_ratio_at)->toBeNull();
});

it('still proves the scale when the server context sets only unrelated keys', function (): void {
    ingestBeforeGaugeColumns([Beacons::payload('viewEnd', 10, [
        'exitType' => 'abandoned', 'completionRate' => 37.5,
        'playerName' => 'scarlett-player', 'playerVersion' => '1.22.0',
    ])->withServer(['tenant' => 7, 'gaugeScale' => null])]);

    $this->artisan('scarlett:views:backfill-gauges', ['--apply' => true])->assertSuccessful();

    expect((float) DB::table('scarlett_views')->where('view_id', Beacons::VIEW)->sole()->completion_ratio)->toBe(0.375);
});

it('walks every row in bounded keyset batches', function (): void {
    foreach (range(1, 5) as $index) {
        $stamp = EloquentBeaconStore::clientTime(Beacons::T0 + $index);
        seedBackfillView(sprintf('batch-%d', $index), 25.0, $stamp, null, null, [
            evidencePayload($stamp, 25.0, ['playerVersion' => '1.22.0']),
        ]);
    }

    $this->artisan('scarlett:views:backfill-gauges', ['--apply' => true, '--batch' => 2])->assertSuccessful();

    foreach (range(1, 5) as $index) {
        $row = DB::table('scarlett_views')->where('view_id', sprintf('batch-%d', $index))->sole();
        expect((float) $row->completion_ratio)->toBe(0.25)
            ->and(backfillStamp($row, 'completion_ratio_at'))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + $index));
    }
});

it('refuses to run before the gauge columns exist', function (): void {
    $migration = require __DIR__.'/../../../database/migrations/0001_01_01_000007_add_gauges_to_scarlett_tables.php';
    $migration->down();

    try {
        $this->artisan('scarlett:views:backfill-gauges')
            ->expectsOutputToContain('gauge columns')
            ->assertFailed();
    } finally {
        $migration->up();
    }
});
