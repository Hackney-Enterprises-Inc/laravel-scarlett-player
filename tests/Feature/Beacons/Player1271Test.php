<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Events\PlaybackErrorReported;
use Hei\ScarlettPlayer\Events\ViewEnded;
use Hei\ScarlettPlayer\Jobs\ProcessBeacon;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\Beacons;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * Player 1.27.1 (TSP-571): on native HLS the first failed reload of an outage is sent
 * as a NON-fatal error that is also `reconnecting: true` with `attempts`. Before
 * 1.27.1 `reconnecting` only rode on a `fatal: true` error. The body is derived from
 * the player source (hls event-map.ts, analytics onError), not captured: the capture
 * harness runs Chromium on MSE and never takes the native reload path (recapture owed).
 */

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nativeReloadError(array $overrides = []): array
{
    return array_replace([
        'errorType' => 'Error',
        'errorMessage' => 'Media source not supported',
        'errorCode' => 4,
        'fatal' => false,
        'errorCategory' => 'source',
        'errorSeverity' => 'warning',
        'mediaErrorCode' => 4,
        'networkState' => 3,
        'readyState' => 0,
        'reconnecting' => true,
        'attempts' => 1,
        'online' => true,
        'sourceHost' => 'cdn.example.test',
    ], $overrides);
}

beforeEach(function (): void {
    $this->usesMigrations();
    $this->deliver = function (string $event, int $offset = 0, array $extra = []): void {
        app()->call([new ProcessBeacon(Beacons::payload($event, $offset, $extra)), 'handle']);
    };
});

it('keeps the view open for a non-fatal reconnecting native reload error and stores its columns', function (): void {
    Event::fake([ViewEnded::class, PlaybackErrorReported::class]);

    ($this->deliver)('viewStart');
    ($this->deliver)('error', 10, nativeReloadError());

    $view = DB::table('scarlett_views')->sole();
    expect($view->ended_at)->toBeNull()
        ->and($view->exit_type)->toBeNull()
        ->and($view->fatal_error_category)->toBeNull();

    $error = DB::table('scarlett_view_errors')->sole();
    expect((bool) $error->fatal)->toBeFalse()
        ->and((bool) $error->reconnecting)->toBeTrue()
        ->and((int) $error->attempts)->toBe(1)
        ->and((int) $error->media_error_code)->toBe(4)
        ->and((int) $error->network_state)->toBe(3)
        ->and((int) $error->ready_state)->toBe(0)
        ->and($error->severity)->toBe('warning')
        ->and($error->category)->toBe('source');

    Event::assertNotDispatched(ViewEnded::class);
    Event::assertDispatchedTimes(PlaybackErrorReported::class, 1);
    Event::assertDispatched(PlaybackErrorReported::class, fn (PlaybackErrorReported $e): bool => ! $e->isFatal() && $e->isReconnecting());
});

it('decides by severity and reconnecting, never by the wire fatal flag', function (array $fields, bool $fatal): void {
    Event::fake([PlaybackErrorReported::class]);

    ($this->deliver)('error', 0, nativeReloadError($fields));

    expect((bool) DB::table('scarlett_view_errors')->sole()->fatal)->toBe($fatal);
    Event::assertDispatched(PlaybackErrorReported::class, fn (PlaybackErrorReported $e): bool => $e->isFatal() === $fatal);
})->with([
    '1.27.1 non-fatal reconnecting' => [[], false],
    '1.22+ fatal reconnecting' => [['fatal' => true], false],
    'fatal, not reconnecting, severity fatal' => [['fatal' => true, 'errorSeverity' => 'fatal', 'reconnecting' => false, 'attempts' => null], true],
]);

it('ends the view for a fatal error that is not reconnecting', function (): void {
    ($this->deliver)('viewStart');
    ($this->deliver)('error', 10, nativeReloadError(['fatal' => true, 'errorSeverity' => 'fatal', 'reconnecting' => false, 'attempts' => null]));
    ($this->deliver)('viewEnd', 20, ['exitType' => 'error']);

    expect(DB::table('scarlett_views')->sole()->exit_type)->toBe('error')
        ->and((bool) DB::table('scarlett_view_errors')->sole()->fatal)->toBeTrue();
});
