<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Contracts\BeaconStore;
use Hei\ScarlettPlayer\Doctor\CheckRegistry;
use Hei\ScarlettPlayer\Doctor\Checks\BeaconReconnectColumnsCheck;
use Hei\ScarlettPlayer\Doctor\CheckStatus;
use Hei\ScarlettPlayer\Events\PlaybackErrorReported;
use Hei\ScarlettPlayer\Events\ViewEnded;
use Hei\ScarlettPlayer\Jobs\ProcessBeacon;
use Hei\ScarlettPlayer\Stores\EloquentBeaconStore;
use Hei\ScarlettPlayer\Stores\NullBeaconStore;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\Beacons;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

/*
 * Player 1.22.0 ingest: the derived wire set under tests/Fixtures/wire/1.22.0-derived/
 * (recapture owed) and the merge rules for the fields it adds.
 */

const PLAYER_122_KEY = 'wire-capture-key';

/**
 * @return array<string, array<string, mixed>>
 */
function player122Fixtures(): array
{
    $fixtures = [];

    foreach (glob(dirname(__DIR__, 2).'/Fixtures/wire/1.22.0-derived/*.json') ?: [] as $path) {
        $fixtures[basename($path)] = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    ksort($fixtures);

    return $fixtures;
}

beforeEach(function (): void {
    $this->usesMigrations();
    config()->set('queue.default', 'sync');
    config()->set('scarlett-player.beacons.key', PLAYER_122_KEY);
    $this->deliver = function (string $event, int $time, array $fields = [], string $view = Beacons::VIEW): void {
        app()->call([new ProcessBeacon(Beacons::payload($event, $time, $fields, $view)), 'handle']);
    };
    $this->replay = function (array $request) {
        $server = [];
        foreach ($request['headers'] as $name => $value) {
            $server[$name === 'content-type' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $name))] = (string) $value;
        }
        $query = (array) $request['query'];

        return $this->call('POST', $request['path'].($query === [] ? '' : '?'.http_build_query($query)), [], [], [], $server, (string) json_encode($request['body']));
    };
    $this->reconnectsMigration = require __DIR__.'/../../../database/migrations/0001_01_01_000006_add_reconnects_to_scarlett_tables.php';
});

it('labels every derived 1.22.0 fixture as recapture owed', function (): void {
    $provenance = (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/wire/1.22.0-derived/PROVENANCE.md');
    $fixtures = player122Fixtures();

    // The capture harness writes tests/Fixtures/wire/<version>/ and refuses an existing
    // directory, so the derived set must never occupy the captured name.
    expect(is_dir(dirname(__DIR__, 2).'/Fixtures/wire/1.22.0'))->toBeFalse();

    expect($fixtures)->toHaveCount(8);

    foreach ($fixtures as $file => $json) {
        expect($provenance)->toMatch('/^\| `'.preg_quote($file, '/').'` \|.*derived \(recapture owed\).*\|$/m')
            ->and($json['fixture']['status'])->toBe('derived (recapture owed)')
            ->and($json['request']['body']['playerVersion'])->toBe('1.22.0');
    }
});

it('replays the derived 1.22.0 beacons into the views, errors and raw log, in either order', function (bool $reverse): void {
    Event::fake([ViewEnded::class, PlaybackErrorReported::class]);
    $fixtures = player122Fixtures();

    foreach ($reverse ? array_reverse($fixtures) : $fixtures as $json) {
        ($this->replay)($json['request'])->assertNoContent();
        ($this->replay)($json['request'])->assertNoContent();
    }

    $live = DB::table('scarlett_views')->where('view_id', 'derived-1.22.0-live-reconnect')->sole();
    expect($live->exit_type)->toBe('liveEnded')
        ->and($live->ended_at)->not->toBeNull()
        ->and($live->completion_rate)->toBeNull()
        ->and($live->avg_bitrate)->toBeNull()
        ->and($live->max_bitrate)->toBeNull()
        ->and((int) $live->reconnect_count)->toBe(1)
        ->and((int) $live->reconnect_ms)->toBe(40000)
        ->and((int) $live->element_seek_count)->toBe(3)
        ->and((int) $live->seek_count)->toBe(1)
        ->and((int) $live->dvr_ms)->toBe(9000)
        ->and((int) $live->warning_count)->toBe(1)
        ->and((int) $live->pause_count)->toBe(1)
        ->and((int) $live->pause_ms)->toBe(1500)
        ->and($live->media_duration)->toBeNull()
        ->and($live->fatal_error_category)->toBeNull()
        ->and($live->browser)->toBe('Instagram')
        ->and(json_decode((string) $live->custom, true))->toBe(['tenant' => 'wire', 'planTier' => 'free']);

    $error = DB::table('scarlett_view_errors')->sole();
    expect((bool) $error->fatal)->toBeFalse()
        ->and($error->severity)->toBe('warning')
        ->and($error->category)->toBe('network')
        ->and((bool) $error->reconnecting)->toBeTrue()
        ->and((int) $error->network_state)->toBe(2)
        ->and((int) $error->ready_state)->toBe(1)
        ->and((bool) $error->online)->toBeTrue()
        ->and($error->source_host)->toBe('cdn.example.test');

    $unload = DB::table('scarlett_views')->where('view_id', 'derived-1.22.0-vod-unload')->sole();
    expect($unload->exit_type)->toBe('abandoned')
        ->and((float) $unload->qoe_score)->toBe(92.5)
        ->and((int) $unload->qoe_version)->toBe(2)
        ->and((float) $unload->completion_rate)->toBe(37.5)
        ->and((float) $unload->rebuffer_ratio)->toBe(0.0)
        ->and((int) $unload->avg_bitrate)->toBe(2400000)
        ->and((int) $unload->max_bitrate)->toBe(4800000)
        ->and((int) $unload->quality_changes)->toBe(2)
        ->and((int) $unload->seek_count)->toBe(2)
        ->and((int) $unload->element_seek_count)->toBe(2)
        ->and((int) $unload->reconnect_count)->toBe(0)
        ->and((int) $unload->pause_ms)->toBe(5000)
        ->and((float) $unload->media_duration)->toBe(600.25)
        ->and($unload->dvr_ms)->toBeNull();

    expect(DB::table('scarlett_beacon_events')->count())->toBe(8)
        ->and(DB::table('scarlett_beacon_events')->where('event', 'reconnecting')->count())->toBe(2)
        ->and(DB::table('scarlett_beacon_events')->where('event', 'recovered')->count())->toBe(1);

    $recovered = json_decode((string) DB::table('scarlett_beacon_events')->where('event', 'recovered')->value('payload'), true);
    expect($recovered)->toMatchArray(['duration' => 40000, 'reconnectCount' => 1, 'attempt' => 7, 'elapsedMs' => 40000]);

    Event::assertDispatchedTimes(ViewEnded::class, 2);
    Event::assertDispatchedTimes(PlaybackErrorReported::class, 1);
    Event::assertDispatched(PlaybackErrorReported::class, fn (PlaybackErrorReported $event): bool => ! $event->isFatal() && $event->isReconnecting());
})->with(['in order' => false, 'reversed' => true]);

describe('pause time and media duration', function (): void {
    it('merges pause_ms monotonically across a late heartbeat, two viewEnds and duplicates', function (bool $reverse): void {
        $events = [
            ['heartbeat', 10, ['pauseDuration' => 1000]],
            ['heartbeat', 20, ['pauseDuration' => 2500.4]],
            ['viewEnd', 30, ['pauseDuration' => 4000, 'exitType' => 'abandoned']],
            ['viewEnd', 31, ['pauseDuration' => 4000, 'exitType' => 'completed']],
        ];

        foreach ($reverse ? array_reverse($events) : $events as $event) {
            ($this->deliver)(...$event);
            ($this->deliver)(...$event);
        }

        expect((int) DB::table('scarlett_views')->sole()->pause_ms)->toBe(4000);
    })->with(['in order' => false, 'reversed' => true]);

    it('takes media duration from heartbeats only, newest wins, in either order', function (bool $reverse): void {
        $events = [
            ['heartbeat', 10, ['duration' => 300.5]],
            ['heartbeat', 20, ['duration' => 600.25]],
            ['rebufferEnd', 30, ['duration' => 1200, 'totalRebufferTime' => 1200]],
            ['recovered', 40, ['duration' => 42000, 'reconnectCount' => 1]],
            ['viewEnd', 50, ['duration' => 9999, 'exitType' => 'completed']],
            ['custom:chapter', 60, ['duration' => 77]],
        ];

        foreach ($reverse ? array_reverse($events) : $events as $event) {
            ($this->deliver)(...$event);
            ($this->deliver)(...$event);
        }

        $view = DB::table('scarlett_views')->sole();
        expect((float) $view->media_duration)->toBe(600.25)
            ->and(Carbon::parse($view->media_duration_at)->format('Y-m-d H:i:s.v'))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 20));
    })->with(['in order' => false, 'reversed' => true]);

    it('never lets a zero, negative, null or live heartbeat duration overwrite or set one', function (mixed $duration, array $extra): void {
        ($this->deliver)('heartbeat', 10, ['duration' => 600.25]);
        ($this->deliver)('heartbeat', 20, ['duration' => $duration, ...$extra]);
        ($this->deliver)('heartbeat', 0, ['duration' => $duration, ...$extra], 'never-known');

        expect((float) DB::table('scarlett_views')->where('view_id', Beacons::VIEW)->value('media_duration'))->toBe(600.25)
            ->and(DB::table('scarlett_views')->where('view_id', 'never-known')->value('media_duration'))->toBeNull()
            ->and(Carbon::parse(DB::table('scarlett_views')->where('view_id', Beacons::VIEW)->value('media_duration_at'))->format('Y-m-d H:i:s.v'))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 10));
    })->with([
        'zero after load()' => [0, []],
        'negative' => [-1, []],
        'null (Infinity or NaN in JSON)' => [null, []],
        'string' => ['600', []],
        'live sliding window' => [7200.0, ['isLive' => true]],
    ]);

    it('ignores a non-finite duration that reaches the store directly', function (): void {
        // JSON cannot carry Infinity or NaN (the wire sends null); the raw log's
        // encoding of a non-finite value is a known limitation outside this test.
        config()->set('scarlett-player.beacons.store_raw_events', false);
        $store = app(BeaconStore::class);
        $store->record(Beacons::payload('heartbeat', 10, ['duration' => 600.25]));
        $store->record(Beacons::payload('heartbeat', 20, ['duration' => INF]));
        $store->record(Beacons::payload('heartbeat', 30, ['duration' => NAN]));

        expect((float) DB::table('scarlett_views')->sole()->media_duration)->toBe(600.25);
    });

    it('accepts both over HTTP and keeps them out of custom', function (): void {
        $this->postJson('/api/scarlett/beacons', Beacons::body('heartbeat', 0, ['duration' => 120.5, 'pauseDuration' => 900]), ['X-API-Key' => PLAYER_122_KEY])->assertNoContent();

        $view = DB::table('scarlett_views')->sole();
        expect((float) $view->media_duration)->toBe(120.5)
            ->and((int) $view->pause_ms)->toBe(900)
            ->and($view->custom)->toBeNull();
    });

    it('assigns media_duration before its stamp and metrics_at in the merge UPDATE (MySQL reads updated values)', function (): void {
        ($this->deliver)('heartbeat', 10, ['duration' => 300.0]);

        $sql = null;
        DB::listen(function ($query) use (&$sql): void {
            if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'media_duration')) {
                $sql = $query->sql;
            }
        });

        ($this->deliver)('heartbeat', 5, ['duration' => 100.0, 'pauseDuration' => 10]);

        $wrap = fn (string $column): string => DB::connection()->getQueryGrammar()->wrap($column).' = ';
        expect($sql)->not->toBeNull()
            ->and(strpos($sql, $wrap('media_duration')))->toBeLessThan(strpos($sql, $wrap('media_duration_at')))
            ->and(strpos($sql, $wrap('media_duration_at')))->toBeLessThan(strpos($sql, $wrap('metrics_at')))
            ->and((float) DB::table('scarlett_views')->sole()->media_duration)->toBe(300.0);
    });
});

describe('error severity', function (): void {
    it('never fails the view for an error marked reconnecting', function (): void {
        Event::fake([ViewEnded::class, PlaybackErrorReported::class]);

        ($this->deliver)('heartbeat', 0, ['watchTime' => 1000]);
        ($this->deliver)('error', 10, ['fatal' => true, 'errorSeverity' => 'warning', 'reconnecting' => true, 'errorCategory' => 'network']);
        ($this->deliver)('reconnecting', 11, ['reconnectCount' => 1, 'attempt' => 1, 'delayMs' => 1000, 'elapsedMs' => 0]);
        ($this->deliver)('recovered', 20, ['duration' => 9000, 'reconnectCount' => 1, 'attempt' => 2, 'elapsedMs' => 9000]);

        $view = DB::table('scarlett_views')->sole();
        expect($view->ended_at)->toBeNull()
            ->and($view->exit_type)->toBeNull()
            ->and($view->fatal_error_category)->toBeNull()
            ->and((int) $view->reconnect_count)->toBe(1)
            ->and(json_decode((string) $view->custom, true))->toBeNull()
            ->and((bool) DB::table('scarlett_view_errors')->sole()->fatal)->toBeFalse();

        Event::assertNotDispatched(ViewEnded::class);
        Event::assertDispatchedTimes(PlaybackErrorReported::class, 1);
    });

    it('keys the stored fatal flag on severity, falling back to fatal for players without one', function (array $fields, bool $fatal): void {
        Event::fake([PlaybackErrorReported::class]);

        ($this->deliver)('error', 0, $fields);

        expect((bool) DB::table('scarlett_view_errors')->sole()->fatal)->toBe($fatal);
        Event::assertDispatched(PlaybackErrorReported::class, fn (PlaybackErrorReported $event): bool => $event->isFatal() === $fatal);
    })->with([
        'terminal fatal' => [['fatal' => true, 'errorSeverity' => 'fatal'], true],
        'reconnecting fatal' => [['fatal' => true, 'errorSeverity' => 'warning', 'reconnecting' => true], false],
        'warning' => [['fatal' => false, 'errorSeverity' => 'warning'], false],
        'legacy fatal' => [['fatal' => true], true],
        'legacy fatal marked reconnecting' => [['fatal' => true, 'reconnecting' => true], false],
        'legacy non-fatal' => [['fatal' => false], false],
        'reconnecting false' => [['fatal' => true, 'errorSeverity' => 'fatal', 'reconnecting' => false], true],
    ]);

    it('stores element error categories and context, absent context staying null', function (): void {
        ($this->deliver)('error', 0, ['fatal' => true, 'errorSeverity' => 'fatal', 'errorCategory' => 'source', 'mediaErrorCode' => 4, 'online' => false]);
        ($this->deliver)('error', 1, ['fatal' => false, 'errorSeverity' => 'warning', 'errorCategory' => 'media', 'mediaErrorCode' => 3, 'readyState' => 2.6, 'networkState' => -1]);

        [$source, $media] = DB::table('scarlett_view_errors')->orderBy('occurred_at')->get()->all();
        expect($source->category)->toBe('source')
            ->and((bool) $source->online)->toBeFalse()
            ->and($source->source_host)->toBeNull()
            ->and($source->reconnecting)->toBeNull()
            ->and($media->category)->toBe('media')
            ->and((int) $media->ready_state)->toBe(3)
            ->and((int) $media->network_state)->toBe(0)
            ->and($media->online)->toBeNull();
    });
});

describe('view counters and nullable measurements', function (): void {
    it('merges the new counters monotonically across a late heartbeat, two viewEnds and duplicates', function (bool $reverse): void {
        $counters = fn (int $n): array => ['elementSeekCount' => $n, 'reconnectCount' => $n, 'reconnectDuration' => $n * 1000, 'dvrTime' => $n * 500, 'isLive' => true];
        $events = [
            ['heartbeat', 10, $counters(1)],
            ['viewEnd', 30, [...$counters(3), 'exitType' => 'liveEnded', 'completionRate' => null]],
            ['viewEnd', 31, [...$counters(3), 'exitType' => 'abandoned', 'completionRate' => null]],
            ['heartbeat', 20, $counters(2)],
        ];

        foreach ($reverse ? array_reverse($events) : $events as $event) {
            ($this->deliver)(...$event);
            ($this->deliver)(...$event);
        }

        $view = DB::table('scarlett_views')->sole();
        expect((int) $view->element_seek_count)->toBe(3)
            ->and((int) $view->reconnect_count)->toBe(3)
            ->and((int) $view->reconnect_ms)->toBe(3000)
            ->and((int) $view->dvr_ms)->toBe(1500)
            ->and($view->exit_type)->toBe($reverse ? 'abandoned' : 'liveEnded')
            ->and($view->completion_rate)->toBeNull();
    })->with(['in order' => false, 'reversed' => true]);

    it('never lets a null bitrate or completion rate overwrite a known value, in either order', function (bool $reverse): void {
        $events = [
            ['heartbeat', 10, ['avgBitrate' => 2_500_000, 'maxBitrate' => 5_000_000]],
            ['viewEnd', 20, ['avgBitrate' => null, 'maxBitrate' => null, 'completionRate' => null]],
            ['viewEnd', 5, ['completionRate' => 40.0]],
        ];

        foreach ($reverse ? array_reverse($events) : $events as $event) {
            ($this->deliver)(...$event);
        }

        $view = DB::table('scarlett_views')->sole();
        expect((int) $view->avg_bitrate)->toBe(2_500_000)
            ->and((int) $view->max_bitrate)->toBe(5_000_000)
            ->and((float) $view->completion_rate)->toBe(40.0)
            ->and(json_decode((string) $view->custom, true))->toBeNull();
    })->with(['in order' => false, 'reversed' => true]);

    it('leaves bitrate null while the player has none, and keeps an existing zero', function (): void {
        ($this->deliver)('heartbeat', 0, ['avgBitrate' => null, 'maxBitrate' => null], 'unknown-bitrate');
        ($this->deliver)('heartbeat', 0, ['avgBitrate' => 0, 'maxBitrate' => 0], 'historical-zero');
        ($this->deliver)('heartbeat', 10, ['avgBitrate' => null, 'maxBitrate' => null], 'historical-zero');

        $unknown = DB::table('scarlett_views')->where('view_id', 'unknown-bitrate')->sole();
        $zero = DB::table('scarlett_views')->where('view_id', 'historical-zero')->sole();
        expect($unknown->avg_bitrate)->toBeNull()->and($unknown->max_bitrate)->toBeNull()
            ->and((int) $zero->avg_bitrate)->toBe(0)->and((int) $zero->max_bitrate)->toBe(0);
    });

    it('treats reconnecting and recovered as neither errors nor view ends', function (): void {
        Event::fake([ViewEnded::class, PlaybackErrorReported::class]);

        ($this->deliver)('reconnecting', 0, ['reconnectCount' => 1, 'attempt' => 6, 'delayMs' => 8000, 'elapsedMs' => 30000, 'longOutage' => true]);
        ($this->deliver)('recovered', 10, ['duration' => 40000, 'reconnectCount' => 1, 'attempt' => 7, 'elapsedMs' => 40000]);

        $view = DB::table('scarlett_views')->sole();
        expect($view->ended_at)->toBeNull()
            ->and((int) $view->reconnect_count)->toBe(1)
            ->and(json_decode((string) $view->custom, true))->toBeNull()
            ->and(DB::table('scarlett_view_errors')->count())->toBe(0)
            ->and(DB::table('scarlett_beacon_events')->pluck('event')->sort()->values()->all())->toBe(['reconnecting', 'recovered']);

        Event::assertNotDispatched(ViewEnded::class);
        Event::assertNotDispatched(PlaybackErrorReported::class);
    });
});

describe('before the reconnects migration', function (): void {
    it('keeps ingesting 1.22.0 beacons on insert and merge, and the doctor warns', function (bool $raw): void {
        $this->reconnectsMigration->down();
        config()->set('scarlett-player.beacons.store_raw_events', $raw);

        try {
            foreach (player122Fixtures() as $json) {
                ($this->replay)($json['request'])->assertNoContent();
                ($this->replay)($json['request'])->assertNoContent();
            }

            $live = DB::table('scarlett_views')->where('view_id', 'derived-1.22.0-live-reconnect')->sole();
            $error = DB::table('scarlett_view_errors')->sole();
            expect($live->exit_type)->toBe('liveEnded')
                ->and((int) $live->seek_count)->toBe(1)
                ->and(json_decode((string) $live->custom, true))->toBe(['tenant' => 'wire', 'planTier' => 'free'])
                ->and(Schema::hasColumn('scarlett_views', 'pause_ms'))->toBeFalse()
                ->and((bool) $error->fatal)->toBeFalse()
                ->and($error->severity)->toBe('warning');

            $result = app(BeaconReconnectColumnsCheck::class)->run();
            expect($result->status)->toBe(CheckStatus::Warn)
                ->and($result->message)->toContain('scarlett_views.reconnect_count', 'scarlett_views.pause_ms', 'scarlett_views.media_duration_at', 'scarlett_view_errors.source_host', 'scarlett-migrations-reconnects');
        } finally {
            $this->reconnectsMigration->up();
        }
    })->with(['raw on' => true, 'raw off' => false]);

    it('caches column detection per store and picks the columns up on a new instance', function (): void {
        $this->reconnectsMigration->down();

        try {
            $store = app(BeaconStore::class);
            $store->record(Beacons::payload('heartbeat', 0, ['reconnectCount' => 1]));
        } finally {
            $this->reconnectsMigration->up();
        }

        $store->record(Beacons::payload('heartbeat', 10, ['reconnectCount' => 2]));
        expect(DB::table('scarlett_views')->sole()->reconnect_count)->toBeNull();

        app()->forgetInstance(BeaconStore::class);
        app(BeaconStore::class)->record(Beacons::payload('heartbeat', 20, ['reconnectCount' => 3]));
        expect((int) DB::table('scarlett_views')->sole()->reconnect_count)->toBe(3);
    });

    it('registers a doctor check that passes on a complete schema and for other stores', function (): void {
        expect(app(CheckRegistry::class)->classes())->toContain(BeaconReconnectColumnsCheck::class)
            ->and(app(BeaconReconnectColumnsCheck::class)->run()->status)->toBe(CheckStatus::Pass);

        app()->instance(BeaconStore::class, new NullBeaconStore);
        expect(app(BeaconReconnectColumnsCheck::class)->run()->status)->toBe(CheckStatus::Pass);
    });
});

it('skips media duration when its stamp column is missing, and pause_ms on its own', function (): void {
    Schema::table('scarlett_views', fn ($table) => $table->dropColumn('media_duration_at'));

    try {
        $store = app(BeaconStore::class);
        $store->record(Beacons::payload('heartbeat', 0, ['duration' => 600.25, 'pauseDuration' => 300]));
        $store->record(Beacons::payload('heartbeat', 10, ['duration' => 610.0, 'pauseDuration' => 400]));

        $view = DB::table('scarlett_views')->sole();
        expect($view->media_duration)->toBeNull()->and((int) $view->pause_ms)->toBe(400)
            ->and(app(BeaconReconnectColumnsCheck::class)->run()->message)->toContain('scarlett_views.media_duration_at');
    } finally {
        Schema::table('scarlett_views', fn ($table) => $table->dateTime('media_duration_at', 3)->nullable());
    }
});
