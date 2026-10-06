<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Contracts\ProcessesBeacon;
use Hei\ScarlettPlayer\Data\BeaconPayload;
use Hei\ScarlettPlayer\Events\PlaybackErrorReported;
use Hei\ScarlettPlayer\Jobs\ProcessBeacon;
use Hei\ScarlettPlayer\Stores\EloquentBeaconStore;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\Beacons;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * Jobs queued by an older package version keep their old classification through
 * __unserialize(): names promoted since then sit in $custom. The error row is unique
 * on the event key, which the fresh parse of the same body shares, so whichever
 * delivery arrives first decides the row for good. Both must give the same answer.
 */

/**
 * The same body as a fresh parse and as a payload restored from an older queue: the
 * names in $legacyCustom moved from fields to custom, the authoritative hash kept.
 *
 * @param  array<string, mixed>  $extra
 * @param  list<string>  $legacyCustom
 * @return array{0: BeaconPayload, 1: BeaconPayload}
 */
function queuedLegacyPair(string $event, array $extra, array $legacyCustom): array
{
    $fresh = Beacons::payload($event, 0, $extra);
    $moved = array_intersect_key($fresh->fields, array_flip($legacyCustom));

    $old = new BeaconPayload(
        $fresh->event, $fresh->timestamp, $fresh->viewId, $fresh->sessionId, $fresh->viewerId, $fresh->videoId,
        $fresh->context, array_diff_key($fresh->fields, $moved), array_replace($fresh->custom, $moved),
        bodyHash: $fresh->bodyHash,
    );

    // Written by an older package: no classification marker, restored by this one.
    return [$fresh, restoredWithoutMarker($old)];
}

const QUEUED_ERROR = [
    'errorType' => 'MEDIA_NETWORK_ERROR', 'errorMessage' => 'Fatal network error', 'errorCode' => 'MEDIA_NETWORK_ERROR',
    'fatal' => true, 'errorCategory' => 'network', 'errorSeverity' => 'warning', 'httpStatus' => 503, 'attempts' => 1,
    'reconnecting' => true, 'networkState' => 2, 'readyState' => 1, 'online' => true, 'sourceHost' => 'cdn.example.test',
    'tenant' => 'wire',
];

/** Pre-signals (0.3) queue: severity, the signal details and the 1.22 context were custom. */
const PRE_SIGNALS_CUSTOM = [
    'errorCategory', 'errorSeverity', 'httpStatus', 'attempts',
    'reconnecting', 'networkState', 'readyState', 'online', 'sourceHost',
];

/** 0.4-era queue: severity known, only the 1.22 names custom. */
const SIGNALS_ERA_CUSTOM = ['reconnecting', 'networkState', 'readyState', 'online', 'sourceHost'];

beforeEach(function (): void {
    $this->usesMigrations();
    config()->set('queue.default', 'sync');
});

it('stores a queued legacy reconnecting error as the fresh parse would, in either delivery order', function (array $legacyCustom, bool $oldFirst): void {
    Event::fake([PlaybackErrorReported::class]);
    [$fresh, $old] = queuedLegacyPair('error', QUEUED_ERROR, $legacyCustom);

    expect($old->custom)->toHaveKeys($legacyCustom)
        ->and($old->bodyHash)->toBe($fresh->bodyHash)
        ->and(EloquentBeaconStore::eventKey($old))->toBe(EloquentBeaconStore::eventKey($fresh));

    foreach ($oldFirst ? [$old, $fresh] : [$fresh, $old] as $payload) {
        app()->call([unserialize(serialize(new ProcessBeacon($payload))), 'handle']);
    }

    $row = DB::table('scarlett_view_errors')->sole();
    expect((bool) $row->fatal)->toBeFalse()
        ->and($row->severity)->toBe('warning')
        ->and($row->category)->toBe('network')
        ->and((int) $row->http_status)->toBe(503)
        ->and((bool) $row->reconnecting)->toBeTrue()
        ->and((int) $row->network_state)->toBe(2)
        ->and((int) $row->ready_state)->toBe(1)
        ->and((bool) $row->online)->toBeTrue()
        ->and($row->source_host)->toBe('cdn.example.test')
        ->and(DB::table('scarlett_views')->sole()->ended_at)->toBeNull();

    Event::assertDispatchedTimes(PlaybackErrorReported::class, 1);
    Event::assertDispatched(PlaybackErrorReported::class, fn (PlaybackErrorReported $event): bool => ! $event->isFatal() && $event->isReconnecting());
})->with([
    'pre-signals queue' => [PRE_SIGNALS_CUSTOM],
    '0.4-era queue' => [SIGNALS_ERA_CUSTOM],
])->with(['old first' => true, 'fresh first' => false]);

it('keeps a queued legacy terminal error fatal', function (): void {
    [, $old] = queuedLegacyPair('error', ['fatal' => true, 'errorSeverity' => 'fatal', 'reconnecting' => false], ['errorSeverity', 'reconnecting']);

    expect($old->isFatalError())->toBeTrue()->and($old->isReconnectingError())->toBeFalse();
});

it('merges the new view counters from a queued legacy viewEnd, in either delivery order', function (bool $oldFirst): void {
    $counters = ['elementSeekCount' => 4, 'reconnectCount' => 2, 'reconnectDuration' => 5000, 'dvrTime' => 700, 'warningCount' => 1];
    [, $old] = queuedLegacyPair('viewEnd', [...$counters, 'isLive' => true, 'exitType' => 'liveEnded'], array_keys($counters));
    $heartbeat = Beacons::payload('heartbeat', -10, ['elementSeekCount' => 1, 'reconnectCount' => 1, 'reconnectDuration' => 1000, 'dvrTime' => 100, 'isLive' => true]);

    foreach ($oldFirst ? [$old, $heartbeat] : [$heartbeat, $old] as $payload) {
        app()->call([unserialize(serialize(new ProcessBeacon($payload))), 'handle']);
    }

    $view = DB::table('scarlett_views')->sole();
    expect((int) $view->element_seek_count)->toBe(4)
        ->and((int) $view->reconnect_count)->toBe(2)
        ->and((int) $view->reconnect_ms)->toBe(5000)
        ->and((int) $view->dvr_ms)->toBe(700)
        ->and((int) $view->warning_count)->toBe(1);
})->with(['old first' => true, 'fresh first' => false]);

it('reads a promoted name from legacy custom only when it carries the known type', function (): void {
    $payload = restoredWithoutMarker(new BeaconPayload('error', Beacons::T0, 'v', 's', 'w', 'm', [], ['fatal' => true], [
        'errorSeverity' => 'warning', 'reconnecting' => 'yes', 'networkState' => '2', 'online' => true, 'tenant' => 'a',
    ]));

    expect($payload->knownValue('errorSeverity'))->toBe('warning')
        ->and($payload->knownValue('reconnecting'))->toBeNull()
        ->and($payload->knownValue('networkState'))->toBeNull()
        ->and($payload->knownValue('online'))->toBeTrue()
        ->and($payload->knownValue('tenant'))->toBeNull()
        ->and($payload->knownValue('fatal'))->toBeTrue()
        ->and($payload->isFatalError())->toBeFalse()
        ->and($payload->isReconnectingError())->toBeFalse()
        // The browser's legacy value survives server ownership, as a fresh known field does.
        ->and($payload->withServer(['errorSeverity' => 'fatal'])->knownValue('errorSeverity'))->toBe('warning');
});

/** A pipeline step running the closure the test sets; declared here, unique global name. */
final class Player122RoundTwoStep implements ProcessesBeacon
{
    /** @var (Closure(BeaconPayload): BeaconPayload)|null */
    public static ?Closure $transform = null;

    public function handle(BeaconPayload $payload, Closure $next): ?BeaconPayload
    {
        return $next((self::$transform)($payload));
    }
}

/**
 * The serialized form of $payload as an older package (0.4 shape, no marker and no
 * legacy map) would have written it, restored by this one.
 */
function restoredWithoutMarker(BeaconPayload $payload): BeaconPayload
{
    $data = $payload->__serialize();
    unset($data['serialVersion'], $data['legacyKnown']);
    $restored = (new ReflectionClass(BeaconPayload::class))->newInstanceWithoutConstructor();
    $restored->__unserialize($data);

    return $restored;
}

describe('provenance of legacy known values (round 2)', function (): void {
    afterEach(function (): void {
        Player122RoundTwoStep::$transform = null;
    });

    it('keeps names a pipeline adds with withCustom() as custom dimensions on a fresh error', function (): void {
        Event::fake([PlaybackErrorReported::class]);
        config()->set('scarlett-player.beacons.pipeline', [Player122RoundTwoStep::class]);
        Player122RoundTwoStep::$transform = fn (BeaconPayload $p): BeaconPayload => $p->withCustom([...$p->custom, 'reconnecting' => true]);

        app()->call([new ProcessBeacon(Beacons::payload('error', 0, ['fatal' => true, 'errorSeverity' => 'fatal'])), 'handle']);

        $row = DB::table('scarlett_view_errors')->sole();
        expect((bool) $row->fatal)->toBeTrue()->and($row->reconnecting)->toBeNull();
        Event::assertDispatched(PlaybackErrorReported::class, fn (PlaybackErrorReported $event): bool => $event->isFatal() && ! $event->isReconnecting());
    });

    it('keeps counters and duration a pipeline adds with withCustom() out of the view columns', function (): void {
        config()->set('scarlett-player.beacons.pipeline', [Player122RoundTwoStep::class]);
        Player122RoundTwoStep::$transform = fn (BeaconPayload $p): BeaconPayload => $p->withCustom(['reconnectCount' => 99, 'pauseDuration' => 9000, 'duration' => 777, 'warningCount' => 5]);

        app()->call([new ProcessBeacon(Beacons::payload('heartbeat', 0)), 'handle']);

        $view = DB::table('scarlett_views')->sole();
        // MySQL's JSON column reorders object keys; the map, not its order, is the contract.
        $custom = json_decode((string) $view->custom, true);
        ksort($custom);
        expect($view->reconnect_count)->toBeNull()
            ->and($view->pause_ms)->toBeNull()
            ->and($view->media_duration)->toBeNull()
            ->and($view->warning_count)->toBeNull()
            ->and($custom)->toBe(['duration' => 777, 'pauseDuration' => 9000, 'reconnectCount' => 99, 'warningCount' => 5]);
    });

    it('does not promote known names put in custom before a current-version job was queued', function (): void {
        $payload = Beacons::payload('error', 0, ['fatal' => true, 'errorSeverity' => 'fatal'])
            ->withCustom(['reconnecting' => true, 'errorSeverity' => 'warning', 'reconnectCount' => 3]);
        $job = unserialize(serialize(new ProcessBeacon($payload)));

        expect($job->payload->knownValue('reconnecting'))->toBeNull()
            ->and($job->payload->knownValue('reconnectCount'))->toBeNull()
            ->and($job->payload->isFatalError())->toBeTrue();

        app()->call([$job, 'handle']);

        expect((bool) DB::table('scarlett_view_errors')->sole()->fatal)->toBeTrue()
            ->and(DB::table('scarlett_views')->sole()->reconnect_count)->toBeNull();
    });

    it('agrees for legacy and fresh errors under the same server ownership, in either delivery order', function (array $legacyCustom, bool $oldFirst): void {
        Event::fake([PlaybackErrorReported::class]);
        config()->set('scarlett-player.beacons.pipeline', [Player122RoundTwoStep::class]);
        Player122RoundTwoStep::$transform = fn (BeaconPayload $p): BeaconPayload => $p->withServer(['errorSeverity' => null, 'reconnecting' => null]);
        [$fresh, $old] = queuedLegacyPair('error', QUEUED_ERROR, $legacyCustom);

        expect($old->withServer(['errorSeverity' => null, 'reconnecting' => null])->isFatalError())->toBeFalse()
            ->and($fresh->withServer(['errorSeverity' => null, 'reconnecting' => null])->isFatalError())->toBeFalse();

        foreach ($oldFirst ? [$old, $fresh] : [$fresh, $old] as $payload) {
            app()->call([unserialize(serialize(new ProcessBeacon($payload))), 'handle']);
        }

        $row = DB::table('scarlett_view_errors')->sole();
        expect((bool) $row->fatal)->toBeFalse()
            ->and((bool) $row->reconnecting)->toBeTrue()
            ->and($row->severity)->toBe('warning')
            ->and($row->source_host)->toBe('cdn.example.test');
        Event::assertDispatched(PlaybackErrorReported::class, fn (PlaybackErrorReported $event): bool => ! $event->isFatal() && $event->isReconnecting());
    })->with([
        'pre-signals queue' => [PRE_SIGNALS_CUSTOM],
        '0.4-era queue' => [SIGNALS_ERA_CUSTOM],
    ])->with(['old first' => true, 'fresh first' => false]);

    it('captures legacy values only from promoted names, and keeps them through re-serialization and with*()', function (): void {
        $legacy = restoredWithoutMarker(new BeaconPayload('error', Beacons::T0, 'v', 's', 'w', 'm', [], ['fatal' => true], [
            'reconnecting' => true, 'networkState' => '2', 'duration' => 12.5, 'tenant' => 'a',
        ]));

        expect($legacy->knownValue('reconnecting'))->toBeTrue()
            ->and($legacy->knownValue('networkState'))->toBeNull()
            ->and($legacy->knownValue('duration'))->toBeNull()
            ->and($legacy->custom)->toBe(['reconnecting' => true, 'networkState' => '2', 'duration' => 12.5, 'tenant' => 'a'])
            ->and($legacy->withCustom([])->withIp(null)->withServer(['reconnecting' => null])->knownValue('reconnecting'))->toBeTrue()
            ->and(unserialize(serialize($legacy))->knownValue('reconnecting'))->toBeTrue()
            ->and(unserialize(serialize($legacy))->bodyHash)->toBe($legacy->bodyHash);
    });

    it('serializes a payload an older package version can still restore', function (): void {
        $payload = restoredWithoutMarker(Beacons::payload('error', 0, ['fatal' => true, 'tenant' => 'a']));
        $data = $payload->__serialize();

        expect($data)->toHaveKeys(['serialVersion', 'event', 'custom', 'bodyHash']);

        $older = Player122OlderPayloadRestore::restore($data);
        expect($older)->toMatchArray(['event' => 'error', 'custom' => ['tenant' => 'a'], 'bodyHash' => $payload->bodyHash]);
    });
});

/**
 * The 0.4.0 BeaconPayload::__unserialize() read, verbatim in what it takes from the
 * array: named keys only, so the marker and legacy map are ignored on rollback.
 */
final class Player122OlderPayloadRestore
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function restore(array $data): array
    {
        $server = (array) ($data['server'] ?? []);

        return [
            'event' => (string) $data['event'],
            'timestamp' => (int) $data['timestamp'],
            'viewId' => (string) $data['viewId'],
            'sessionId' => (string) $data['sessionId'],
            'viewerId' => (string) $data['viewerId'],
            'videoId' => (string) $data['videoId'],
            'context' => (array) ($data['context'] ?? []),
            'fields' => (array) ($data['fields'] ?? []),
            'custom' => (array) ($data['custom'] ?? []),
            'ip' => isset($data['ip']) ? (string) $data['ip'] : null,
            'server' => $server,
            'owned' => array_values(array_map('strval', (array) ($data['owned'] ?? array_keys($server)))),
            'bodyHash' => isset($data['bodyHash']) ? (string) $data['bodyHash'] : null,
        ];
    }
}
