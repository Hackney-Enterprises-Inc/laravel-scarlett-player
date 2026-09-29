<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Data\BeaconPayload;
use Hei\ScarlettPlayer\Events\PlaybackErrorReported;
use Hei\ScarlettPlayer\Events\ViewEnded;
use Hei\ScarlettPlayer\Events\ViewStarted;
use Hei\ScarlettPlayer\Jobs\ProcessBeacon;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\Beacons;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * beacons.raw_events_except: the named events skip scarlett_beacon_events only. The view
 * merge, the error rows and the transition events are untouched. Runs on whatever
 * DB_CONNECTION the run uses, like EloquentBeaconStoreTest.
 */

beforeEach(function (): void {
    $this->usesMigrations();
    Event::fake([ViewStarted::class, ViewEnded::class, PlaybackErrorReported::class]);

    $this->deliver = function (BeaconPayload ...$payloads): void {
        foreach ($payloads as $payload) {
            app()->call([new ProcessBeacon($payload), 'handle']);
        }
    };

    $this->rawEvents = fn (): array => DB::table('scarlett_beacon_events')->orderBy('id')->pluck('event')->all();
});

it('writes no raw row for an excluded heartbeat while the view still merges its metrics', function (): void {
    config()->set('scarlett-player.beacons.raw_events_except', ['heartbeat']);

    ($this->deliver)(
        Beacons::payload('viewStart'),
        Beacons::heartbeat(10_000, 10_000, 80.0),
        Beacons::heartbeat(20_000, 20_000, 75.0, 2),
    );

    $view = DB::table('scarlett_views')->where('view_id', Beacons::VIEW)->sole();

    expect(($this->rawEvents)())->toBe(['viewStart'])
        ->and((int) $view->watch_ms)->toBe(20_000)
        ->and((float) $view->qoe_score)->toBe(75.0)
        ->and((int) $view->rebuffer_count)->toBe(2);
});

it('keeps logging the events it does not name in the same session', function (): void {
    config()->set('scarlett-player.beacons.raw_events_except', ['heartbeat']);

    ($this->deliver)(
        Beacons::payload('viewStart'),
        Beacons::heartbeat(10_000, 10_000, 80.0),
        Beacons::payload('pause', 11_000),
        Beacons::unloadViewEnd(12_000, 12_000),
    );

    expect(($this->rawEvents)())->toBe(['viewStart', 'pause', 'viewEnd']);
    Event::assertDispatchedTimes(ViewStarted::class, 1);
    Event::assertDispatchedTimes(ViewEnded::class, 1);
});

it('still records an excluded error in scarlett_view_errors and fires PlaybackErrorReported', function (): void {
    config()->set('scarlett-player.beacons.raw_events_except', ['error']);

    ($this->deliver)(Beacons::payload('error', 1_000, [
        'errorType' => 'MediaError', 'errorMessage' => 'decode failed', 'fatal' => true,
    ]));

    expect(($this->rawEvents)())->toBe([])
        ->and(DB::table('scarlett_view_errors')->sole()->type)->toBe('MediaError')
        ->and(DB::table('scarlett_views')->where('view_id', Beacons::VIEW)->exists())->toBeTrue();

    Event::assertDispatchedTimes(PlaybackErrorReported::class, 1);
    Event::assertDispatchedTimes(ViewStarted::class, 1);
});

it('keeps every event by default', function (): void {
    expect(config('scarlett-player.beacons.raw_events_except'))->toBe([]);

    ($this->deliver)(
        Beacons::payload('viewStart'),
        Beacons::heartbeat(10_000, 10_000, 80.0),
        Beacons::payload('error', 11_000, ['errorType' => 'A', 'errorMessage' => 'a', 'fatal' => false]),
    );

    expect(($this->rawEvents)())->toBe(['viewStart', 'heartbeat', 'error']);
});

it('is irrelevant while store_raw_events is off', function (array $except): void {
    config()->set('scarlett-player.beacons.store_raw_events', false);
    config()->set('scarlett-player.beacons.raw_events_except', $except);

    ($this->deliver)(Beacons::payload('viewStart'), Beacons::heartbeat(10_000, 10_000, 80.0));

    expect(($this->rawEvents)())->toBe([])
        ->and((int) DB::table('scarlett_views')->sole()->watch_ms)->toBe(10_000);
})->with([
    'nothing excluded' => [[]],
    'heartbeat excluded' => [['heartbeat']],
]);

it('ignores entries that are not event names, and a setting that is not a list, rather than failing the beacon', function (mixed $except, array $logged): void {
    config()->set('scarlett-player.beacons.raw_events_except', $except);

    ($this->deliver)(Beacons::payload('viewStart'), Beacons::heartbeat(10_000, 10_000, 80.0));

    expect(($this->rawEvents)())->toBe($logged)
        ->and(DB::table('scarlett_views')->count())->toBe(1);
})->with([
    'non-string entries beside a name' => [[1, null, ['heartbeat'], true, 'heartbeat'], ['viewStart']],
    'only non-string entries' => [[0, false], ['viewStart', 'heartbeat']],
    'a string, not a list' => ['heartbeat', ['viewStart', 'heartbeat']],
    'null' => [null, ['viewStart', 'heartbeat']],
]);

it('matches event names exactly', function (): void {
    config()->set('scarlett-player.beacons.raw_events_except', ['Heartbeat', 'heart']);

    ($this->deliver)(Beacons::heartbeat(10_000, 10_000, 80.0));

    expect(($this->rawEvents)())->toBe(['heartbeat']);
});

it('leaves an expected seq gap for an excluded heartbeat without losing its view metrics', function (): void {
    config()->set('scarlett-player.beacons.raw_events_except', ['heartbeat']);

    ($this->deliver)(
        Beacons::payload('viewStart', 0, ['beaconSeq' => 1]),
        Beacons::payload('heartbeat', 10_000, ['beaconSeq' => 2, 'watchTime' => 10_000, 'qoeScore' => 80]),
        Beacons::payload('viewEnd', 11_000, ['beaconSeq' => 3, 'exitType' => 'abandoned']),
    );

    $view = DB::table('scarlett_views')->sole();
    expect(DB::table('scarlett_beacon_events')->orderBy('occurred_at')->orderBy('seq')->orderBy('id')->pluck('seq')->map(fn ($seq): int => (int) $seq)->all())->toBe([1, 3])
        ->and(($this->rawEvents)())->toBe(['viewStart', 'viewEnd'])
        ->and((int) $view->watch_ms)->toBe(10_000)
        ->and((float) $view->qoe_score)->toBe(80.0)
        ->and($view->custom)->toBeNull();

    Event::assertDispatchedTimes(ViewStarted::class, 1);
    Event::assertDispatchedTimes(ViewEnded::class, 1);
});
