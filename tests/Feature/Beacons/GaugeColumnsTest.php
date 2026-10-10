<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Data\BeaconPayload;
use Hei\ScarlettPlayer\Doctor\Checks\BeaconGaugeColumnsCheck;
use Hei\ScarlettPlayer\Doctor\CheckStatus;
use Hei\ScarlettPlayer\Jobs\ProcessBeacon;
use Hei\ScarlettPlayer\Stores\EloquentBeaconStore;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\Beacons;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Canonical gauge columns: completion_ratio / completion_ratio_at from
 * completionRate, rebuffer_fraction / rebuffer_fraction_at from rebufferRatio,
 * normalized to 0..1 after the pipeline. The legacy columns keep the received
 * wire values unchanged. A canonical null with a stamp is a processed
 * unavailable measurement.
 */

beforeEach(function (): void {
    $this->usesMigrations();
    config()->set('queue.default', 'sync');
    $this->deliver = function (string $event, int $offsetMs, array $fields = [], string $view = Beacons::VIEW): void {
        app()->call([new ProcessBeacon(Beacons::payload($event, $offsetMs, $fields, $view)), 'handle']);
    };
    $this->deliverPayload = function (BeaconPayload $payload): void {
        app()->call([new ProcessBeacon($payload), 'handle']);
    };
    $this->stamp = function (object $row, string $column): ?string {
        return $row->{$column} === null ? null : Carbon::parse((string) $row->{$column})->format('Y-m-d H:i:s.v');
    };
});

/** The view row the merge tests read. */
function gaugeRow(string $viewId = Beacons::VIEW): object
{
    return DB::table('scarlett_views')->where('view_id', $viewId)->sole();
}

it('normalizes an explicit percent marker into the canonical ratio and keeps the legacy value', function (): void {
    ($this->deliver)('viewEnd', 10, [
        'exitType' => 'abandoned', 'completionRate' => 37.5, 'rebufferRatio' => 44.44, 'gaugeScale' => 'percent',
    ]);

    $row = gaugeRow();
    expect((float) $row->completion_rate)->toBe(37.5)
        ->and((float) $row->completion_ratio)->toBe(0.375)
        ->and(($this->stamp)($row, 'completion_ratio_at'))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 10))
        ->and((float) $row->rebuffer_ratio)->toBe(44.44)
        ->and((float) $row->rebuffer_fraction)->toBe(0.4444)
        ->and(($this->stamp)($row, 'rebuffer_fraction_at'))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 10))
        // The marker stays a custom dimension, raw log and hash unchanged.
        ->and(json_decode((string) $row->custom, true))->toBe(['gaugeScale' => 'percent']);
});

it('divides low percentages by 100 and passes explicit ratios through', function (array $fields, float $expected): void {
    ($this->deliver)('viewEnd', 10, ['exitType' => 'abandoned', ...$fields]);

    expect((float) gaugeRow()->completion_ratio)->toBe($expected);
})->with([
    'half a percent is not a half' => [['completionRate' => 0.5, 'gaugeScale' => 'percent'], 0.005],
    'one percent' => [['completionRate' => 1, 'gaugeScale' => 'percent'], 0.01],
    'explicit ratio' => [['completionRate' => 0.5, 'gaugeScale' => 'ratio'], 0.5],
    'whole ratio' => [['completionRate' => 1, 'gaugeScale' => 'ratio'], 1.0],
]);

it('uses the audited registry when the marker is absent on a 1.22.0 beacon', function (): void {
    ($this->deliver)('viewEnd', 10, [
        'exitType' => 'abandoned', 'completionRate' => 15.14,
        'playerVersion' => '1.22.0', 'playerName' => 'scarlett-player',
    ]);

    expect((float) gaugeRow()->completion_ratio)->toBe(0.1514);
});

it('writes a processed null for an unknown scale and never refills it with an older numeric', function (): void {
    // Newer measurement, unaudited version, no marker: unavailable, stamped.
    ($this->deliver)('viewEnd', 20, [
        'exitType' => 'abandoned', 'completionRate' => 50, 'playerVersion' => '1.21.0',
    ]);
    // Older valid numeric must not refill the processed null.
    ($this->deliver)('viewEnd', 10, [
        'exitType' => 'abandoned', 'completionRate' => 37.5, 'gaugeScale' => 'percent',
    ]);

    $row = gaugeRow();
    expect($row->completion_ratio)->toBeNull()
        ->and(($this->stamp)($row, 'completion_ratio_at'))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 20))
        // The legacy column keeps the newest received wire value (latest-by-timestamp).
        ->and((float) $row->completion_rate)->toBe(50.0);
});

it('replaces an older canonical value with a newer measurement and accepts exact ties', function (): void {
    ($this->deliver)('viewEnd', 10, ['exitType' => 'abandoned', 'completionRate' => 25, 'gaugeScale' => 'percent']);
    ($this->deliver)('viewEnd', 20, ['exitType' => 'abandoned', 'completionRate' => 50, 'gaugeScale' => 'percent']);

    expect((float) gaugeRow()->completion_ratio)->toBe(0.5)
        ->and(($this->stamp)(gaugeRow(), 'completion_ratio_at'))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 20));

    // Exact tie: the later-processed measurement wins, preserving >=.
    ($this->deliver)('viewEnd', 20, ['exitType' => 'abandoned', 'completionRate' => 75, 'gaugeScale' => 'percent']);

    expect((float) gaugeRow()->completion_ratio)->toBe(0.75);
});

it('writes a processed null for an explicitly invalid marker', function (): void {
    ($this->deliver)('viewEnd', 10, ['exitType' => 'abandoned', 'completionRate' => 37.5, 'gaugeScale' => 'fractions']);

    expect(gaugeRow()->completion_ratio)->toBeNull()
        ->and(($this->stamp)(gaugeRow(), 'completion_ratio_at'))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 10));
});

it('treats an explicit null marker as invalid, never falling back to the registry', function (bool $queued): void {
    $payload = Beacons::payload('viewEnd', 10, [
        'exitType' => 'abandoned', 'completionRate' => 50, 'gaugeScale' => null,
        'playerName' => 'scarlett-player', 'playerVersion' => '1.22.0',
    ]);

    if ($queued) {
        /** @var ProcessBeacon $job */
        $job = unserialize(serialize(new ProcessBeacon($payload)));
        $payload = $job->payload;
    }

    ($this->deliverPayload)($payload);

    $row = gaugeRow();
    expect($row->completion_ratio)->toBeNull()
        ->and(($this->stamp)($row, 'completion_ratio_at'))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 10))
        ->and((float) $row->completion_rate)->toBe(50.0)
        // The null is not stored and does not move the hash basis.
        ->and($row->custom)->toBeNull()
        ->and($payload->bodyHash)->toBe(Beacons::payload('viewEnd', 10, [
            'exitType' => 'abandoned', 'completionRate' => 50,
            'playerName' => 'scarlett-player', 'playerVersion' => '1.22.0',
        ])->bodyHash);
})->with(['fresh' => false, 'queued' => true]);

it('clamps an out-of-range percent and exposes it in the legacy column untouched', function (): void {
    ($this->deliver)('viewEnd', 10, ['exitType' => 'abandoned', 'completionRate' => 150, 'gaugeScale' => 'percent']);

    $row = gaugeRow();
    expect((float) $row->completion_ratio)->toBe(1.0)
        ->and((float) $row->completion_rate)->toBe(150.0);
});

it('treats a wrong-typed gauge as a custom dimension and leaves the canonical columns alone', function (): void {
    ($this->deliver)('viewEnd', 10, ['exitType' => 'abandoned', 'completionRate' => 'far', 'gaugeScale' => 'percent']);

    $row = gaugeRow();
    $custom = json_decode((string) $row->custom, true);
    ksort($custom); // MySQL reorders JSON object keys.
    expect($row->completion_ratio)->toBeNull()
        ->and($row->completion_ratio_at)->toBeNull()
        ->and($row->completion_rate)->toBeNull()
        ->and($custom)->toBe(['completionRate' => 'far', 'gaugeScale' => 'percent']);
});

it('stamps an explicit-null completion as a processed unavailable measurement', function (): void {
    ($this->deliver)('viewEnd', 10, [
        'exitType' => 'liveEnded', 'completionRate' => null, 'rebufferRatio' => 44.44,
        'isLive' => true, 'gaugeScale' => 'percent',
    ]);

    $row = gaugeRow();
    expect($row->completion_ratio)->toBeNull()
        ->and(($this->stamp)($row, 'completion_ratio_at'))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 10))
        ->and($row->completion_rate)->toBeNull()
        ->and((float) $row->rebuffer_fraction)->toBe(0.4444);
});

it('leaves the canonical columns alone for an omitted gauge', function (): void {
    ($this->deliver)('viewEnd', 10, ['exitType' => 'abandoned', 'completionRate' => 25, 'gaugeScale' => 'percent']);
    // A heartbeat carries neither gauge.
    ($this->deliver)('heartbeat', 20, ['watchTime' => 20000]);

    $row = gaugeRow();
    expect((float) $row->completion_ratio)->toBe(0.25)
        ->and(($this->stamp)($row, 'completion_ratio_at'))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 10));
});

it('clears a written canonical completion when the live classification arrives later, without a gauge', function (): void {
    ($this->deliver)('viewEnd', 10, ['exitType' => 'abandoned', 'completionRate' => 50, 'gaugeScale' => 'percent']);
    expect((float) gaugeRow()->completion_ratio)->toBe(0.5);

    ($this->deliver)('heartbeat', 20, ['isLive' => true, 'watchTime' => 20000]);

    $row = gaugeRow();
    expect($row->completion_ratio)->toBeNull()
        ->and(($this->stamp)($row, 'completion_ratio_at'))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 20))
        ->and((bool) $row->is_live)->toBeTrue()
        // The legacy column keeps the received value.
        ->and((float) $row->completion_rate)->toBe(50.0);
});

it('keeps completion_ratio null when the live classification arrives before the gauge', function (): void {
    ($this->deliver)('heartbeat', 10, ['isLive' => true, 'watchTime' => 10000]);
    ($this->deliver)('viewEnd', 20, ['exitType' => 'abandoned', 'completionRate' => 50, 'gaugeScale' => 'percent']);
    // And a later numeric update stays unavailable on the live row.
    ($this->deliver)('viewEnd', 30, ['exitType' => 'abandoned', 'completionRate' => 75, 'gaugeScale' => 'percent']);

    $row = gaugeRow();
    expect($row->completion_ratio)->toBeNull()
        ->and(($this->stamp)($row, 'completion_ratio_at'))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 10))
        ->and((float) $row->completion_rate)->toBe(75.0)
        // rebuffer is unaffected by the live rule.
        ->and($row->rebuffer_fraction_at)->toBeNull();
});

it('stamps completion unavailable when the first beacon is both live and the row insert', function (): void {
    ($this->deliver)('viewEnd', 10, ['exitType' => 'liveEnded', 'isLive' => true, 'completionRate' => null, 'rebufferRatio' => 10, 'gaugeScale' => 'percent']);

    $row = gaugeRow();
    expect($row->completion_ratio)->toBeNull()
        ->and(($this->stamp)($row, 'completion_ratio_at'))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 10))
        ->and((float) $row->rebuffer_fraction)->toBe(0.1);
});

it('keeps completion null when the live row-inserting beacon carries a numeric completion', function (): void {
    ($this->deliver)('viewEnd', 10, ['exitType' => 'liveEnded', 'isLive' => true, 'completionRate' => 50, 'rebufferRatio' => 10, 'gaugeScale' => 'percent']);

    $row = gaugeRow();
    expect($row->completion_ratio)->toBeNull()
        ->and(($this->stamp)($row, 'completion_ratio_at'))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 10))
        ->and((float) $row->rebuffer_fraction)->toBe(0.1);
});

it('writes canonical gauges on a row the viewEnd itself inserts', function (): void {
    ($this->deliver)('viewEnd', 10, ['exitType' => 'abandoned', 'completionRate' => 37.5, 'rebufferRatio' => 25, 'gaugeScale' => 'percent']);

    $row = gaugeRow();
    expect((float) $row->completion_ratio)->toBe(0.375)
        ->and((float) $row->rebuffer_fraction)->toBe(0.25)
        ->and(($this->stamp)($row, 'completion_ratio_at'))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 10));
});

it('stamps the two gauges independently', function (): void {
    ($this->deliver)('viewEnd', 10, ['exitType' => 'abandoned', 'completionRate' => 25, 'gaugeScale' => 'percent']);
    ($this->deliver)('viewEnd', 20, ['exitType' => 'abandoned', 'rebufferRatio' => 12.5, 'gaugeScale' => 'percent']);

    $row = gaugeRow();
    expect((float) $row->completion_ratio)->toBe(0.25)
        ->and(($this->stamp)($row, 'completion_ratio_at'))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 10))
        ->and((float) $row->rebuffer_fraction)->toBe(0.125)
        ->and(($this->stamp)($row, 'rebuffer_fraction_at'))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 20));
});

it('is idempotent under duplicate delivery, in both orders', function (bool $reverse): void {
    $first = ['viewEnd', 10, ['exitType' => 'abandoned', 'completionRate' => 37.5, 'gaugeScale' => 'percent']];
    $second = ['viewEnd', 20, ['exitType' => 'abandoned', 'completionRate' => 50, 'gaugeScale' => 'percent']];

    foreach (($reverse ? [$second, $first] : [$first, $second]) as $event) {
        ($this->deliver)(...$event);
        ($this->deliver)(...$event);
    }

    $row = gaugeRow();
    expect((float) $row->completion_ratio)->toBe(0.5)
        ->and(($this->stamp)($row, 'completion_ratio_at'))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 20));
})->with(['in order' => false, 'reversed' => true]);

it('keeps ingesting on an older schema without the gauge columns', function (): void {
    $migration = require __DIR__.'/../../../database/migrations/0001_01_01_000007_add_gauges_to_scarlett_tables.php';
    $migration->down();

    try {
        expect(Schema::hasColumn('scarlett_views', 'completion_ratio'))->toBeFalse();

        ($this->deliver)('viewEnd', 10, [
            'exitType' => 'abandoned', 'completionRate' => 37.5, 'rebufferRatio' => 44.44, 'gaugeScale' => 'percent',
        ]);

        $row = gaugeRow();
        expect((float) $row->completion_rate)->toBe(37.5)
            ->and((float) $row->rebuffer_ratio)->toBe(44.44)
            ->and(json_decode((string) $row->custom, true))->toBe(['gaugeScale' => 'percent']);
    } finally {
        $migration->up();
    }

    expect(Schema::hasColumn('scarlett_views', 'completion_ratio'))->toBeTrue();
});

it('writes only complete gauge pairs on a partly applied gauges migration', function (): void {
    $migration = require __DIR__.'/../../../database/migrations/0001_01_01_000007_add_gauges_to_scarlett_tables.php';
    $migration->down();

    try {
        // As MySQL leaves it when the run stops after the third ALTER.
        Schema::table('scarlett_views', function (Blueprint $table): void {
            $table->double('completion_ratio')->nullable();
            $table->dateTime('completion_ratio_at', 3)->nullable();
            $table->double('rebuffer_fraction')->nullable();
        });

        // The first inserts the row, the second merges into it.
        ($this->deliver)('viewEnd', 10, ['exitType' => 'abandoned', 'completionRate' => 25, 'rebufferRatio' => 5, 'gaugeScale' => 'percent']);
        ($this->deliver)('viewEnd', 20, ['exitType' => 'abandoned', 'completionRate' => 50, 'rebufferRatio' => 10, 'gaugeScale' => 'percent']);

        $row = gaugeRow();
        expect((float) $row->completion_ratio)->toBe(0.5)
            ->and(($this->stamp)($row, 'completion_ratio_at'))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 20))
            ->and($row->rebuffer_fraction)->toBeNull()
            ->and((float) $row->completion_rate)->toBe(50.0)
            ->and((float) $row->rebuffer_ratio)->toBe(10.0);
    } finally {
        $migration->up();
    }
});

it('warns about missing gauge columns with a command the host can run', function (): void {
    $migration = require __DIR__.'/../../../database/migrations/0001_01_01_000007_add_gauges_to_scarlett_tables.php';
    expect(app(BeaconGaugeColumnsCheck::class)->run()->status)->toBe(CheckStatus::Pass);
    $migration->down();

    try {
        $result = app(BeaconGaugeColumnsCheck::class)->run();
        expect($result->status)->toBe(CheckStatus::Warn)
            ->and($result->message)->toContain('scarlett:views:backfill-gauges')
            ->not->toContain('--dry-run');
    } finally {
        $migration->up();
    }
});

it('keeps the explicit null through a fresh queued job', function (): void {
    $payload = Beacons::payload('viewEnd', 10, [
        'exitType' => 'liveEnded', 'completionRate' => null, 'rebufferRatio' => 44.44, 'isLive' => true,
        'playerVersion' => '1.22.0',
    ]);
    /** @var ProcessBeacon $job */
    $job = unserialize(serialize(new ProcessBeacon($payload)));
    ($this->deliverPayload)($job->payload);

    $row = gaugeRow();
    expect($row->completion_ratio)->toBeNull()
        ->and(($this->stamp)($row, 'completion_ratio_at'))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 10))
        ->and((float) $row->rebuffer_fraction)->toBe(0.4444);
});

it('treats gauge nulls as absent on a job queued before the provenance existed', function (): void {
    $identity = [
        'event' => 'viewEnd', 'timestamp' => Beacons::T0 + 10, 'viewId' => Beacons::VIEW,
        'sessionId' => 'session-1', 'viewerId' => 'viewer-1', 'videoId' => 'video-1',
    ];
    // No isLive: the only question here is whether a dropped gauge null is
    // invented back into a presence claim by an old serialized job.
    $context = ['playerVersion' => '1.22.0', 'playerName' => 'scarlett-player'];
    $fields = ['exitType' => 'liveEnded', 'rebufferRatio' => 44.44];
    $state = array_replace($identity, [
        'context' => $context, 'fields' => $fields, 'custom' => [], 'ip' => null, 'serialVersion' => 1,
    ]);
    $serialized = str_replace('O:8:"stdClass"', 'O:'.strlen(BeaconPayload::class).':"'.BeaconPayload::class.'"', serialize((object) $state));
    /** @var BeaconPayload $restored */
    $restored = unserialize($serialized);
    ($this->deliverPayload)($restored);

    $row = gaugeRow();
    // No explicit-null claim: completion is simply absent, no processed stamp,
    // while the carried rebuffer gauge still normalizes.
    expect($row->completion_ratio)->toBeNull()
        ->and($row->completion_ratio_at)->toBeNull()
        ->and((float) $row->rebuffer_fraction)->toBe(0.4444);
});

it('replays the derived 1.22.0 wire fixtures into the canonical columns', function (): void {
    foreach (glob(dirname(__DIR__, 2).'/Fixtures/wire/1.22.0-derived/*.json') ?: [] as $path) {
        $json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $request = $json['request'];
        $server = [];
        foreach ($request['headers'] as $name => $value) {
            $server[$name === 'content-type' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $name))] = (string) $value;
        }
        config()->set('scarlett-player.beacons.key', 'wire-capture-key');
        $query = (array) $request['query'];
        $this->call('POST', $request['path'].($query === [] ? '' : '?'.http_build_query($query)), [], [], [], $server, (string) json_encode($request['body']))
            ->assertNoContent();
    }

    // No marker: the registry proves scarlett-player 1.22.0 percent.
    $unload = DB::table('scarlett_views')->where('view_id', 'derived-1.22.0-vod-unload')->sole();
    expect((float) $unload->completion_ratio)->toBe(0.375)
        ->and((float) $unload->rebuffer_fraction)->toBe(0.0)
        ->and((float) $unload->completion_rate)->toBe(37.5);

    // Explicit null completion on the live view; rebuffer still normalizes.
    $live = DB::table('scarlett_views')->where('view_id', 'derived-1.22.0-live-reconnect')->sole();
    expect($live->completion_ratio)->toBeNull()
        ->and($live->completion_ratio_at)->not->toBeNull()
        ->and((float) $live->rebuffer_fraction)->toEqualWithDelta(44.44444444444444 / 100, 1e-9);
});
