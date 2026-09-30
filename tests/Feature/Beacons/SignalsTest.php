<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Events\PlaybackErrorReported;
use Hei\ScarlettPlayer\Jobs\ProcessBeacon;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\Beacons;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->usesMigrations();
    config()->set('queue.default', 'sync');
    $this->deliverSignal = function (string $event, int $time, array $fields, string $view = Beacons::VIEW): void {
        app()->call([new ProcessBeacon(Beacons::payload($event, $time, $fields, $view)), 'handle']);
    };
});

it('clears an access-denied score and keeps score and version together in either delivery order', function (bool $reverse): void {
    $events = [
        ['heartbeat', 10, ['qoeScore' => 95]],
        ['heartbeat', 20, ['qoeScore' => 85, 'qoeVersion' => 2]],
        ['viewEnd', 30, ['qoeScore' => null, 'qoeVersion' => 2, 'fatalErrorCategory' => 'access']],
    ];

    foreach ($reverse ? array_reverse($events) : $events as $event) {
        ($this->deliverSignal)(...$event);
    }

    ($this->deliverSignal)('viewEnd', 40, ['watchTime' => 500]);
    $view = DB::table('scarlett_views')->sole();
    expect($view->qoe_score)->toBeNull()
        ->and((int) $view->qoe_version)->toBe(2)
        ->and($view->fatal_error_category)->toBe('access');
    $raw = DB::table('scarlett_beacon_events')->get()->map(fn ($row) => json_decode($row->payload, true))->first(fn ($body) => isset($body['fatalErrorCategory']));
    expect($raw)->toHaveKey('qoeScore')->and($raw['qoeScore'])->toBeNull();
})->with([false, true]);

it('groups averages by score version, excluding null while retaining a real zero', function (): void {
    ($this->deliverSignal)('heartbeat', 0, ['qoeScore' => 90], 'legacy');
    ($this->deliverSignal)('heartbeat', 0, ['qoeScore' => 80, 'qoeVersion' => 2], 'modern');
    ($this->deliverSignal)('viewEnd', 0, ['qoeScore' => 0, 'qoeVersion' => 2], 'failed');
    ($this->deliverSignal)('viewEnd', 0, ['qoeScore' => null, 'qoeVersion' => 2], 'access');
    $averages = DB::table('scarlett_views')->selectRaw('qoe_version, AVG(qoe_score) AS average')->groupBy('qoe_version')->pluck('average', 'qoe_version');
    expect((float) $averages[1])->toBe(90.0)->and((float) $averages[2])->toBe(40.0);
});

it('does not change the score version when a beacon carries no score', function (): void {
    ($this->deliverSignal)('heartbeat', 20, ['qoeScore' => 72, 'qoeVersion' => 2]);
    ($this->deliverSignal)('heartbeat', 30, ['qoeVersion' => 1]);
    ($this->deliverSignal)('heartbeat', 10, ['qoeScore' => 99]);
    $view = DB::table('scarlett_views')->sole();
    expect((float) $view->qoe_score)->toBe(72.0)->and((int) $view->qoe_version)->toBe(2);
});

it('persists structured errors once even with raw events disabled', function (): void {
    config()->set('scarlett-player.beacons.store_raw_events', false);
    Event::fake([PlaybackErrorReported::class]);
    $fields = [
        'errorCategory' => 'network', 'errorSeverity' => 'warning', 'httpStatus' => 503,
        'mediaErrorCode' => 2, 'attempts' => 3, 'retriesExhausted' => true,
        'reconnectExhausted' => false, 'timedOut' => true, 'fatal' => false,
    ];
    ($this->deliverSignal)('error', 10, $fields);
    ($this->deliverSignal)('error', 10, $fields);
    $error = DB::table('scarlett_view_errors')->sole();
    expect($error->category)->toBe('network')->and($error->severity)->toBe('warning')
        ->and((int) $error->http_status)->toBe(503)->and((int) $error->media_error_code)->toBe(2)
        ->and((int) $error->attempts)->toBe(3)->and((bool) $error->retries_exhausted)->toBeTrue()
        ->and((bool) $error->reconnect_exhausted)->toBeFalse()->and((bool) $error->timed_out)->toBeTrue()
        ->and(DB::table('scarlett_beacon_events')->count())->toBe(0);
    Event::assertDispatchedTimes(PlaybackErrorReported::class, 1);
});

it('keeps interval metrics as latest samples and warning counts as running totals', function (bool $raw): void {
    config()->set('scarlett-player.beacons.store_raw_events', $raw);
    $old = ['warningCount' => 5, 'segmentCount' => 9, 'segmentBytes' => 900, 'segmentLoadAvgMs' => 50, 'segmentLoadMaxMs' => 80, 'segmentErrors' => 2, 'segmentThroughputBps' => 8000, 'decodedFrames' => 100, 'droppedFrames' => 4];
    $new = ['warningCount' => 7, 'segmentCount' => 2, 'segmentBytes' => 200, 'segmentLoadAvgMs' => 20, 'segmentLoadMaxMs' => 30, 'segmentErrors' => 0, 'segmentThroughputBps' => 12000, 'decodedFrames' => 10, 'droppedFrames' => 0];
    foreach ([[$new, 20], [$old, 10], [$new, 20]] as [$fields, $time]) {
        ($this->deliverSignal)('heartbeat', $time, $fields);
    }
    $view = DB::table('scarlett_views')->sole();
    expect((int) $view->warning_count)->toBe(7);
    foreach ($new as $key => $value) {
        $column = Str::snake($key);
        expect((float) $view->{$column})->toBe((float) $value);
    }
    expect(DB::table('scarlett_beacon_events')->count())->toBe($raw ? 2 : 0);
})->with([true, false]);

it('retains page context and the anonymous flag without custom dimensions', function (): void {
    $fields = ['pageUrl' => 'https://example.test/watch', 'referrerOrigin' => 'https://referrer.test', 'pageLoadToInitMs' => 12.5, 'playerInitMs' => 4.25, 'anonymous' => true];
    ($this->deliverSignal)('heartbeat', 20, ['anonymous' => true]);
    ($this->deliverSignal)('viewStart', 10, $fields);
    ($this->deliverSignal)('heartbeat', 30, ['anonymous' => false]);
    $view = DB::table('scarlett_views')->sole();
    expect($view->page_url)->toBe($fields['pageUrl'])->and($view->referrer_origin)->toBe($fields['referrerOrigin'])
        ->and((float) $view->page_load_to_init_ms)->toBe(12.5)->and((float) $view->player_init_ms)->toBe(4.25)
        ->and((bool) $view->anonymous)->toBeTrue()->and($view->custom)->toBeNull();
});

it('accepts nullable scores over HTTP but continues rejecting the optional batch envelope', function (): void {
    $body = Beacons::body('viewEnd', 0, ['qoeScore' => null, 'qoeVersion' => 2]);
    $this->postJson('/api/scarlett/beacons', $body, ['X-API-Key' => self::BEACON_KEY])->assertNoContent();
    $this->postJson('/api/scarlett/beacons', ['batch' => 1, 'sentAt' => Beacons::T0, 'events' => [$body]], ['X-API-Key' => self::BEACON_KEY])->assertUnprocessable();
    expect(DB::table('scarlett_beacon_events')->count())->toBe(1);
});

it('upgrades existing scored rows as v1 and reverses only its added columns', function (): void {
    $migration = require __DIR__.'/../../../database/migrations/0001_01_01_000005_add_signals_to_scarlett_tables.php';
    $migration->down();
    DB::table('scarlett_views')->insert([
        'view_id' => 'old', 'session_id' => 's', 'viewer_id' => 'v', 'video_id' => 'm',
        'qoe_score' => 77,
    ]);
    $migration->up();
    expect((int) DB::table('scarlett_views')->where('view_id', 'old')->value('qoe_version'))->toBe(1);
    ($this->deliverSignal)('viewEnd', 10, ['qoeScore' => null, 'qoeVersion' => 2], 'old');
    expect(DB::table('scarlett_views')->where('view_id', 'old')->value('qoe_score'))->toBeNull();
});

it('keeps access-denied null over a scored heartbeat in the same millisecond', function (bool $reverse): void {
    $events = [
        ['heartbeat', 10, ['qoeScore' => 90]],
        ['viewEnd', 10, ['qoeScore' => null, 'qoeVersion' => 2]],
    ];
    foreach ($reverse ? array_reverse($events) : $events as $event) {
        ($this->deliverSignal)(...$event);
    }
    $view = DB::table('scarlett_views')->sole();
    expect($view->qoe_score)->toBeNull()->and((int) $view->qoe_version)->toBe(2);
})->with([false, true]);
