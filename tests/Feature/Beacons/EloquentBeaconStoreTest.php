<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Hei\ScarlettPlayer\Contracts\BeaconStore;
use Hei\ScarlettPlayer\Contracts\ResolvesMedia;
use Hei\ScarlettPlayer\Data\BeaconPayload;
use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Events\PlaybackErrorReported;
use Hei\ScarlettPlayer\Events\ViewEnded;
use Hei\ScarlettPlayer\Events\ViewStarted;
use Hei\ScarlettPlayer\Jobs\ProcessBeacon;
use Hei\ScarlettPlayer\Stores\EloquentBeaconStore;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\Beacons;
use Hei\ScarlettPlayer\Tests\Fixtures\Models\Video;
use Hei\ScarlettPlayer\Tests\Fixtures\Provider\ArrayResolver;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

/*
 * The four merge classes, idempotency and transition events of the default store
 * against the real migrations. Everything
 * goes through ProcessBeacon::handle(), the path a queue worker runs, so the named
 * duplicate-delivery and out-of-order tests exercise the three-statement merge
 * exactly as production does. The engine is whatever DB_CONNECTION the run uses:
 * testbench's in-memory SQLite by default; see the README for MySQL and Postgres.
 */

/**
 * A stored datetime in one format whatever the engine returns: Postgres drops a
 * zero millisecond part, SQLite and MySQL keep it.
 */
function stamp(mixed $value): ?string
{
    return $value === null ? null : CarbonImmutable::parse((string) $value, 'UTC')->format('Y-m-d H:i:s.v');
}

beforeEach(function (): void {
    $this->usesMigrations();
    Event::fake([ViewStarted::class, ViewEnded::class, PlaybackErrorReported::class]);

    $this->deliver = function (BeaconPayload ...$payloads): void {
        foreach ($payloads as $payload) {
            app()->call([new ProcessBeacon($payload), 'handle']);
        }
    };

    $this->view = fn (string $viewId = Beacons::VIEW): object => DB::table('scarlett_views')->where('view_id', $viewId)->sole();
});

it('binds the Eloquent store by default', function (): void {
    expect(app(BeaconStore::class))->toBeInstanceOf(EloquentBeaconStore::class);
});

it('builds one view row from the first beacon with its identity and environment', function (): void {
    ($this->deliver)(Beacons::payload('viewStart'));

    $view = ($this->view)();

    expect($view->session_id)->toBe('session-1')
        ->and($view->viewer_id)->toBe('viewer-1')
        ->and($view->video_id)->toBe('video-1')
        ->and($view->video_title)->toBe('A video')
        ->and((bool) $view->is_live)->toBeFalse()
        ->and($view->player_version)->toBe('1.17.0')
        ->and($view->browser)->toBe('Chrome')
        ->and(stamp($view->started_at))->toBe(EloquentBeaconStore::clientTime(Beacons::T0))
        ->and(stamp($view->last_event_at))->toBe(EloquentBeaconStore::clientTime(Beacons::T0))
        ->and(stamp($view->ended_at))->toBeNull();

    Event::assertDispatchedTimes(ViewStarted::class, 1);
});

it('does not let a heartbeat processed after viewEnd resurrect the view or lose its qoeScore', function (): void {
    ($this->deliver)(
        Beacons::payload('viewStart'),
        Beacons::heartbeat(10_000, 10_000, 80.0),
        Beacons::endedViewEnd(20_000, 20_000, 90.0, 100.0),
        Beacons::heartbeat(15_000, 15_000, 70.0), // older, processed last
    );

    $view = ($this->view)();

    expect(stamp($view->ended_at))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 20_000))
        ->and($view->exit_type)->toBe('completed')
        ->and((float) $view->qoe_score)->toBe(90.0)
        ->and((int) $view->watch_ms)->toBe(20_000);

    // A NEWER heartbeat after viewEnd still applies its latest and monotonic fields.
    ($this->deliver)(Beacons::heartbeat(25_000, 25_000, 85.0));

    $view = ($this->view)();

    expect(stamp($view->ended_at))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 20_000))
        ->and((float) $view->qoe_score)->toBe(85.0)
        ->and((int) $view->watch_ms)->toBe(25_000);

    Event::assertDispatchedTimes(ViewEnded::class, 1);
});

it('leaves qoe_score intact when the unload viewEnd, which has none, is processed after a heartbeat', function (): void {
    ($this->deliver)(
        Beacons::heartbeat(10_000, 10_000, 77.5),
        Beacons::unloadViewEnd(12_000, 12_000),
    );

    $view = ($this->view)();

    expect((float) $view->qoe_score)->toBe(77.5)
        ->and($view->exit_type)->toBe('abandoned')
        ->and(stamp($view->ended_at))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 12_000))
        ->and((int) $view->avg_bitrate)->toBe(2_400_000);
});

it('keeps qoe_score in either order of heartbeat and unload viewEnd', function (): void {
    ($this->deliver)(
        Beacons::unloadViewEnd(12_000, 12_000),
        Beacons::heartbeat(10_000, 10_000, 77.5),
    );

    expect((float) ($this->view)()->qoe_score)->toBe(77.5);
});

it('lets an ended viewEnd after the unload viewEnd fill completion_rate without moving ended_at', function (int $endedOffset): void {
    ($this->deliver)(
        Beacons::unloadViewEnd(30_000, 30_000),
        Beacons::endedViewEnd($endedOffset, 30_000, 88.0, 97.5),
    );

    $view = ($this->view)();

    expect(stamp($view->ended_at))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 30_000))
        ->and($view->exit_type)->toBe('abandoned')
        ->and((float) $view->completion_rate)->toBe(97.5)
        ->and((float) $view->qoe_score)->toBe(88.0)
        ->and((int) $view->pause_count)->toBe(1)
        ->and((int) $view->quality_changes)->toBe(2);

    Event::assertDispatchedTimes(ViewEnded::class, 1);
})->with(['ended stamped later' => 31_000, 'ended stamped earlier' => 29_000]);

it('never decreases a monotonic field when an older heartbeat is processed last', function (): void {
    ($this->deliver)(
        Beacons::heartbeat(30_000, 30_000, 90.0, rebufferCount: 3),
        Beacons::heartbeat(10_000, 10_000, 60.0, rebufferCount: 1),
    );

    $view = ($this->view)();

    expect((int) $view->watch_ms)->toBe(30_000)
        ->and((int) $view->play_ms)->toBe(30_000)
        ->and((int) $view->rebuffer_count)->toBe(3)
        ->and((int) $view->rebuffer_ms)->toBe(300)
        ->and((float) $view->qoe_score)->toBe(90.0)
        ->and((float) $view->current_position)->toBe(30.0)
        ->and(stamp($view->metrics_at))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 30_000))
        ->and(stamp($view->last_event_at))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 30_000));
});

it('has the effect of once when the queue delivers the same beacon twice', function (): void {
    $error = Beacons::payload('error', 5_000, [
        'errorType' => 'NetworkError', 'errorMessage' => 'fragment failed', 'errorCode' => 2, 'fatal' => false,
    ]);
    $heartbeat = Beacons::heartbeat(10_000, 10_000, 80.0, rebufferCount: 2);

    ($this->deliver)(Beacons::payload('viewStart'), $error, $error, $heartbeat, $heartbeat);

    $before = (array) ($this->view)();
    ($this->deliver)($heartbeat);
    $after = (array) ($this->view)();

    unset($before['updated_at'], $after['updated_at']);

    expect(DB::table('scarlett_views')->count())->toBe(1)
        ->and(DB::table('scarlett_beacon_events')->where('event', 'error')->count())->toBe(1)
        ->and(DB::table('scarlett_beacon_events')->where('event', 'heartbeat')->count())->toBe(1)
        ->and(DB::table('scarlett_view_errors')->count())->toBe(1)
        ->and($after)->toBe($before);

    Event::assertDispatchedTimes(ViewStarted::class, 1);
    Event::assertDispatchedTimes(PlaybackErrorReported::class, 1);
});

it('fires ViewStarted once when two workers race on the first beacon of a view', function (): void {
    // Two stores stand in for two workers: the unique view_id lets exactly one
    // insertOrIgnore through, and only the one that inserted announces the view.
    $first = app()->make(EloquentBeaconStore::class);
    $second = app()->make(EloquentBeaconStore::class);

    $first->record(Beacons::payload('viewStart'));
    $second->record(Beacons::payload('viewStart'));

    expect(DB::table('scarlett_views')->count())->toBe(1);
    Event::assertDispatchedTimes(ViewStarted::class, 1);

    // The gate itself: the second insertOrIgnore of the same view_id affects no row.
    $row = ['view_id' => 'race', 'session_id' => 's', 'viewer_id' => 'v', 'video_id' => 'x'];

    expect(DB::table('scarlett_views')->insertOrIgnore($row))->toBe(1)
        ->and(DB::table('scarlett_views')->insertOrIgnore($row))->toBe(0);
});

it('fires ViewStarted and ViewEnded together when a viewEnd is the first beacon stored', function (): void {
    ($this->deliver)(Beacons::unloadViewEnd(5_000, 5_000));

    Event::assertDispatchedTimes(ViewStarted::class, 1);
    Event::assertDispatchedTimes(ViewEnded::class, 1);
    Event::assertDispatched(ViewEnded::class, fn (ViewEnded $event): bool => $event->viewId === Beacons::VIEW);
});

it('sets identity and environment once: a later beacon never changes them', function (): void {
    ($this->deliver)(
        Beacons::payload('viewStart'),
        Beacons::payload('heartbeat', 1_000, ['browser' => 'Firefox', 'videoTitle' => 'Renamed', 'watchTime' => 1]),
        Beacons::payload('viewStart', 2_000),
    );

    $view = ($this->view)();

    expect($view->browser)->toBe('Chrome')
        ->and($view->video_title)->toBe('A video')
        ->and(stamp($view->started_at))->toBe(EloquentBeaconStore::clientTime(Beacons::T0));
});

it('fills a set-once column the first beacon lacked', function (): void {
    ($this->deliver)(
        BeaconPayload::fromArray(array_diff_key(Beacons::body('heartbeat', 1_000), ['videoTitle' => true])),
        Beacons::payload('viewStart', 0),
        Beacons::payload('videoStart', 500, ['startupTime' => 480]),
    );

    $view = ($this->view)();

    expect($view->video_title)->toBe('A video')
        ->and(stamp($view->started_at))->toBe(EloquentBeaconStore::clientTime(Beacons::T0))
        ->and(stamp($view->first_frame_at))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 500))
        ->and((int) $view->startup_ms)->toBe(480);
});

it('takes the rebuffer total from rebufferEnd and never its single-stall duration', function (): void {
    ($this->deliver)(Beacons::payload('rebufferEnd', 1_000, ['duration' => 250, 'totalRebufferTime' => 900]));

    expect((int) ($this->view)()->rebuffer_ms)->toBe(900);
});

it('merges custom dimensions key-wise, the newest beacon winning each key', function (): void {
    ($this->deliver)(
        Beacons::payload('heartbeat', 10_000, ['plan' => 'free', 'region' => 'eu']),
        Beacons::payload('heartbeat', 20_000, ['plan' => 'ppv']),
        Beacons::payload('heartbeat', 15_000, ['plan' => 'trial', 'cohort' => 'b']), // older
    );

    expect(json_decode((string) ($this->view)()->custom, true))
        ->toEqual(['plan' => 'ppv', 'region' => 'eu', 'cohort' => 'b']);
});

it('stores the live latency summary as latest-by-timestamp', function (): void {
    $live = fn (int $offset, float $mean): BeaconPayload => Beacons::payload('heartbeat', $offset, [
        'isLive' => true, 'watchTime' => $offset, 'liveLatencySamples' => $offset / 1000,
        'liveLatencyMean' => $mean, 'liveLatencyP95' => $mean + 1, 'liveLatencyMax' => $mean + 2, 'lowLatency' => true,
    ]);

    ($this->deliver)($live(20_000, 4.5), $live(10_000, 9.0));

    $view = ($this->view)();

    expect((float) $view->live_latency_mean)->toBe(4.5)
        ->and((int) $view->live_latency_samples)->toBe(20)
        ->and((bool) $view->low_latency)->toBeTrue();
});

it('records each error beacon once in scarlett_view_errors with its video', function (): void {
    ($this->deliver)(Beacons::payload('error', 1_000, [
        'errorType' => 'MediaError', 'errorMessage' => 'decode failed', 'errorCode' => 'E_DECODE', 'fatal' => true,
    ]));

    $error = DB::table('scarlett_view_errors')->sole();

    expect($error->view_id)->toBe(Beacons::VIEW)
        ->and($error->video_id)->toBe('video-1')
        ->and($error->type)->toBe('MediaError')
        ->and($error->message)->toBe('decode failed')
        ->and($error->code)->toBe('E_DECODE')
        ->and((bool) $error->fatal)->toBeTrue()
        ->and(stamp($error->occurred_at))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 1_000))
        ->and($error->event_key)->toBe(EloquentBeaconStore::eventKey(Beacons::payload('error', 1_000, [
            'errorType' => 'MediaError', 'errorMessage' => 'decode failed', 'errorCode' => 'E_DECODE', 'fatal' => true,
        ])));

    Event::assertDispatched(PlaybackErrorReported::class, fn (PlaybackErrorReported $event): bool => $event->payload->get('errorType') === 'MediaError');
});

it('never increments error_count from the event stream, only from the player total', function (): void {
    ($this->deliver)(
        Beacons::payload('error', 1_000, ['errorType' => 'A', 'errorMessage' => 'a', 'fatal' => false]),
        Beacons::payload('error', 2_000, ['errorType' => 'B', 'errorMessage' => 'b', 'fatal' => false]),
    );

    expect(($this->view)()->error_count)->toBeNull();

    ($this->deliver)(Beacons::endedViewEnd(3_000, 3_000, 50.0, 10.0));

    expect((int) ($this->view)()->error_count)->toBe(0);
});

it('keeps raw events as the stored payload, and none when store_raw_events is off', function (): void {
    ($this->deliver)(Beacons::payload('viewStart', 0, ['plan' => 'ppv']));

    $event = DB::table('scarlett_beacon_events')->sole();

    expect($event->event)->toBe('viewStart')
        ->and(stamp($event->occurred_at))->toBe(EloquentBeaconStore::clientTime(Beacons::T0))
        ->and(json_decode((string) $event->payload, true))->toEqual(Beacons::body('viewStart', 0, ['plan' => 'ppv']));

    config()->set('scarlett-player.beacons.store_raw_events', false);
    ($this->deliver)(Beacons::payload('heartbeat', 1_000, ['watchTime' => 1]));

    expect(DB::table('scarlett_beacon_events')->count())->toBe(1);
});

it('clamps negative and fractional client numbers into the unsigned integer columns', function (): void {
    ($this->deliver)(Beacons::payload('heartbeat', 0, ['watchTime' => -5, 'playTime' => 1234.6]));

    $view = ($this->view)();

    expect((int) $view->watch_ms)->toBe(0)->and((int) $view->play_ms)->toBe(1235);
});

it('stores its millisecond timestamps as UTC with milliseconds', function (): void {
    expect(EloquentBeaconStore::clientTime(0))->toBe('1970-01-01 00:00:00.000')
        ->and(EloquentBeaconStore::clientTime(1_790_000_000_007))->toBe('2026-09-21 14:13:20.007');
});

it('links the view to the host model its videoId resolves to, once, when the view is first stored', function (): void {
    $video = (new Video)->forceFill(['id' => 42]);
    $source = ArrayResolver::source('video-1');
    $resolver = (new ArrayResolver)->add(new MediaSource(
        id: $source->id, playbackUrl: $source->playbackUrl, isLive: false, isProtected: false, duration: 60.0, model: $video,
    ));
    app()->instance(ResolvesMedia::class, $resolver);

    ($this->deliver)(Beacons::payload('viewStart'), Beacons::heartbeat(1_000, 1_000, 90.0));

    $view = ($this->view)();

    expect($view->viewable_type)->toBe($video->getMorphClass())
        ->and($view->viewable_id)->toBe('42')
        ->and($resolver->calls)->toBe(['video-1']);
});

it('keeps the view unlinked when the videoId resolves to nothing or the resolver throws', function (): void {
    app()->instance(ResolvesMedia::class, new ArrayResolver);
    ($this->deliver)(Beacons::payload('viewStart'));

    // The default resolver with no media mapping throws IncompleteMediaMappingException.
    app()->forgetInstance(ResolvesMedia::class);
    ($this->deliver)(Beacons::payload('viewStart', 0, [], 'view-2'));

    expect(($this->view)()->viewable_type)->toBeNull()
        ->and(($this->view)('view-2')->viewable_type)->toBeNull();
});

it('stamps each latest column on its own: a pause does not block an older heartbeat from the columns it never wrote', function (): void {
    ($this->deliver)(
        Beacons::payload('heartbeat', 100, ['watchTime' => 100, 'currentTime' => 0.1, 'qoeScore' => 80.0, 'avgBitrate' => 1_000_000]),
        Beacons::payload('pause', 110, ['currentTime' => 0.11]),
        Beacons::payload('heartbeat', 105, ['watchTime' => 105, 'currentTime' => 0.105, 'qoeScore' => 85.0, 'avgBitrate' => 2_000_000]),
    );

    $view = ($this->view)();

    expect((float) $view->qoe_score)->toBe(85.0)
        ->and((int) $view->avg_bitrate)->toBe(2_000_000)
        ->and((float) $view->current_position)->toBe(0.11)
        ->and(stamp($view->qoe_score_at))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 105))
        ->and(stamp($view->current_position_at))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 110))
        ->and(stamp($view->metrics_at))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 110));
});

it('lets an older ended viewEnd write the final qoeScore after a newer unload viewEnd that carries none', function (): void {
    ($this->deliver)(
        Beacons::heartbeat(590_000, 590_000, 88.0),
        Beacons::unloadViewEnd(610_000, 610_000),
        Beacons::endedViewEnd(600_000, 600_000, 91.0, 100.0),
    );

    $view = ($this->view)();

    expect((float) $view->qoe_score)->toBe(91.0)
        ->and((int) $view->avg_bitrate)->toBe(2_400_000)
        ->and((float) $view->completion_rate)->toBe(100.0);
});

it('never deletes a stored custom dimension when a later beacon sends it as null, and replaces a nested value whole on every engine', function (): void {
    ($this->deliver)(
        Beacons::payload('heartbeat', 10_000, ['plan' => 'ppv', 'nested' => ['a' => 1]]),
        Beacons::payload('heartbeat', 20_000, ['plan' => null, 'nested' => ['b' => 2]]),
    );

    expect(json_decode((string) ($this->view)()->custom, true))
        ->toEqual(['plan' => 'ppv', 'nested' => ['b' => 2]]);
});

it('merges custom dimensions with a stamp per key: an older beacon still wins the keys a newer one never wrote', function (bool $reversed): void {
    // plan=old@100, campaign=spring@300, then plan=new@200 delivered last. One stamp for
    // the whole map would keep plan=old, because @200 is older than @300.
    $beacons = [
        Beacons::payload('heartbeat', 100, ['plan' => 'old', 'watchTime' => 100]),
        Beacons::payload('heartbeat', 300, ['campaign' => 'spring', 'watchTime' => 300]),
        Beacons::payload('heartbeat', 200, ['plan' => 'new', 'watchTime' => 200]),
    ];

    ($this->deliver)(...($reversed ? array_reverse($beacons) : $beacons));

    $view = ($this->view)();

    expect(json_decode((string) $view->custom, true))->toEqual(['plan' => 'new', 'campaign' => 'spring'])
        ->and(array_map(fn (string $stamp): int => (int) strstr($stamp, ':', true), json_decode((string) $view->custom_stamps, true)))
        ->toEqual(['plan' => Beacons::T0 + 200, 'campaign' => Beacons::T0 + 300])
        ->and(stamp($view->custom_at))->toBe(EloquentBeaconStore::clientTime(Beacons::T0 + 300));
})->with(['delivered in that order' => false, 'reversed' => true]);

it('leaves custom dimensions unchanged on a duplicate delivery', function (): void {
    $late = Beacons::payload('heartbeat', 200, ['plan' => 'new', 'watchTime' => 200]);

    ($this->deliver)(Beacons::payload('heartbeat', 300, ['campaign' => 'spring', 'plan' => 'newest', 'watchTime' => 300]), $late);
    $before = (array) ($this->view)();
    ($this->deliver)($late);
    $after = (array) ($this->view)();

    unset($before['updated_at'], $after['updated_at']);

    expect($after)->toBe($before)
        ->and(json_decode((string) $after['custom'], true))->toEqual(['campaign' => 'spring', 'plan' => 'newest']);
});

it('does not ask the resolver at all for a beacons-only host with no media mapping', function (): void {
    config()->set('scarlett-player.media.model', null);
    $handler = Mockery::mock(ExceptionHandler::class);
    $handler->shouldNotReceive('report');
    app()->instance(ExceptionHandler::class, $handler);

    ($this->deliver)(Beacons::payload('viewStart'));

    expect(($this->view)()->viewable_type)->toBeNull();
});

it('resolves the viewable after the transaction and outside it, so a throwing resolver costs nothing', function (): void {
    $resolver = new class implements ResolvesMedia
    {
        public ?int $level = null;

        public function resolve(string $mediaId): ?MediaSource
        {
            $this->level = DB::transactionLevel();

            throw new RuntimeException('host lookup down');
        }
    };
    app()->instance(ResolvesMedia::class, $resolver);
    app()->instance(ExceptionHandler::class, Mockery::spy(ExceptionHandler::class));

    ($this->deliver)(Beacons::payload('viewStart'), Beacons::heartbeat(1_000, 1_000, 90.0));

    expect($resolver->level)->toBe(0)
        ->and(DB::table('scarlett_views')->count())->toBe(1)
        ->and((float) ($this->view)()->qoe_score)->toBe(90.0);

    app(ExceptionHandler::class)->shouldHaveReceived('report')->once();
});

it('skips the address rather than failing when store_ip is turned on after migrating', function (): void {
    config()->set('scarlett-player.beacons.store_ip', true);

    ($this->deliver)(Beacons::payload('viewStart')->withIp('203.0.113.0'));

    expect(DB::table('scarlett_views')->count())->toBe(1)
        ->and(Schema::hasColumn('scarlett_views', 'ip_address'))->toBeFalse();
});

describe('is_live: true wins (a live viewStart is sent before the playlist is read)', function (): void {
    it('turns a live view true when the viewStart said false and a heartbeat says true', function (bool $reversed): void {
        $beacons = [
            Beacons::payload('viewStart', 0, ['isLive' => false]),
            Beacons::payload('heartbeat', 10_000, ['isLive' => true, 'watchTime' => 10_000]),
        ];

        ($this->deliver)(...($reversed ? array_reverse($beacons) : $beacons));

        expect((bool) ($this->view)()->is_live)->toBeTrue();
    })->with(['in order' => false, 'reversed' => true]);

    it('keeps it true when a later beacon says false (a live stream that became a replay)', function (bool $reversed): void {
        $beacons = [
            Beacons::payload('heartbeat', 10_000, ['isLive' => true, 'watchTime' => 10_000]),
            Beacons::payload('heartbeat', 20_000, ['isLive' => false, 'watchTime' => 20_000]),
        ];

        ($this->deliver)(...($reversed ? array_reverse($beacons) : $beacons));

        expect((bool) ($this->view)()->is_live)->toBeTrue();
    })->with(['in order' => false, 'reversed' => true]);

    it('stays false for a view no beacon ever called live', function (): void {
        ($this->deliver)(
            Beacons::payload('viewStart', 0, ['isLive' => false]),
            Beacons::payload('heartbeat', 10_000, ['isLive' => false, 'watchTime' => 10_000]),
        );

        expect((bool) ($this->view)()->is_live)->toBeFalse();
    });

    it('is unchanged by a duplicate delivery', function (): void {
        $heartbeat = Beacons::payload('heartbeat', 10_000, ['isLive' => true, 'watchTime' => 10_000]);

        ($this->deliver)(Beacons::payload('viewStart', 0, ['isLive' => false]), $heartbeat);
        $before = (array) ($this->view)();
        ($this->deliver)($heartbeat, Beacons::payload('viewStart', 0, ['isLive' => false]));
        $after = (array) ($this->view)();

        unset($before['updated_at'], $after['updated_at']);

        expect($after)->toBe($before)->and((bool) $after['is_live'])->toBeTrue();
    });

    it('treats isLive null (player 1.18 viewStart) as absent: nothing is written until a beacon knows', function (): void {
        ($this->deliver)(Beacons::payload('viewStart', 0, ['isLive' => null]));

        expect(($this->view)()->is_live)->toBeNull();

        ($this->deliver)(Beacons::payload('heartbeat', 10_000, ['isLive' => true, 'watchTime' => 10_000]));

        expect((bool) ($this->view)()->is_live)->toBeTrue();
    });
});

it('keeps numeric custom keys as an object, in the view and in the raw log', function (): void {
    ($this->deliver)(
        Beacons::payload('heartbeat', 100, ['0' => 'zero', '5' => 'five', 'watchTime' => 100]),
        Beacons::payload('heartbeat', 200, ['1' => 'one', 'watchTime' => 200]),
    );

    $view = ($this->view)();

    expect((string) $view->custom)->toStartWith('{')
        ->and(json_decode((string) $view->custom, true))->toEqual(['0' => 'zero', '5' => 'five', '1' => 'one'])
        ->and((string) $view->custom_stamps)->toStartWith('{')
        ->and(json_decode((string) DB::table('scarlett_beacon_events')->orderBy('id')->value('payload'), true))
        ->toMatchArray(['0' => 'zero', '5' => 'five']);
});

it('breaks a same-millisecond tie on one custom key by event key, whatever the delivery order', function (bool $reversed): void {
    $a = Beacons::payload('heartbeat', 100, ['plan' => 'a', 'watchTime' => 100]);
    $b = Beacons::payload('heartbeat', 100, ['plan' => 'b', 'watchTime' => 101]);
    $winner = strcmp(EloquentBeaconStore::eventKey($a), EloquentBeaconStore::eventKey($b)) > 0 ? 'a' : 'b';

    ($this->deliver)(...($reversed ? [$b, $a] : [$a, $b]));

    expect(json_decode((string) ($this->view)()->custom, true)['plan'])->toBe($winner);
})->with(['a first' => false, 'b first' => true]);

it('takes the view lock before any other write on MySQL and Postgres, and writes first on SQLite', function (): void {
    ($this->deliver)(Beacons::payload('viewStart'));

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = strtolower($query->sql);
    });

    ($this->deliver)(Beacons::heartbeat(1_000, 1_000, 90.0));

    $first = $statements[0] ?? '';

    if (DB::connection()->getDriverName() === 'sqlite') {
        expect($first)->toStartWith('insert');
    } else {
        expect($first)->toContain('scarlett_views')->toContain('for update')
            ->and(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'insert') && str_contains($sql, 'scarlett_views')))->toBe([]);
    }
});
