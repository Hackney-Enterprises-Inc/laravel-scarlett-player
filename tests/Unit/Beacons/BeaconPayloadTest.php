<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Data\BeaconPayload;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\Beacons;

/*
 * BeaconPayload splits a body into identity, known context, known event fields and
 * custom dimensions.
 */

it('splits identity, context, event fields and custom dimensions', function (): void {
    $payload = BeaconPayload::fromArray(Beacons::body('heartbeat', 0, [
        'watchTime' => 10_000,
        'qoeScore' => 92.5,
        'plan' => 'ppv',
        'tenant' => 7,
    ]), '203.0.113.0');

    expect($payload->event)->toBe('heartbeat')
        ->and($payload->timestamp)->toBe(Beacons::T0)
        ->and($payload->viewId)->toBe(Beacons::VIEW)
        ->and($payload->context['playerVersion'])->toBe('1.17.0')
        ->and($payload->context['isLive'])->toBeFalse()
        ->and($payload->fields)->toBe(['watchTime' => 10_000, 'qoeScore' => 92.5])
        ->and($payload->custom)->toBe(['plan' => 'ppv', 'tenant' => 7])
        ->and($payload->ip)->toBe('203.0.113.0');
});

it('treats a known key sent as null as absent', function (): void {
    $payload = BeaconPayload::fromArray(Beacons::body('viewEnd', 0, ['startupTime' => null, 'videoTitle' => null]));

    expect($payload->has('startupTime'))->toBeFalse()
        ->and($payload->get('startupTime'))->toBeNull()
        ->and($payload->has('videoTitle'))->toBeFalse()
        ->and($payload->toArray())->not->toHaveKey('startupTime');
});

it('drops a custom dimension sent as null: present-but-null is absent for custom keys too', function (): void {
    $payload = BeaconPayload::fromArray(Beacons::body('heartbeat', 0, ['campaign' => null, 'plan' => 'ppv']));

    expect($payload->custom)->toBe(['plan' => 'ppv']);
});

it('moves a known name carrying another type into custom, where a host dimension of that name belongs', function (): void {
    // The player spreads customDimensions before the event data, so on a pause (which
    // sends no duration) a host dimension `duration: 'long'` arrives under a known name.
    $payload = BeaconPayload::fromArray(Beacons::body('pause', 0, ['currentTime' => 3.5, 'duration' => 'long', 'fatal' => 'no']));

    expect($payload->fields)->toBe(['currentTime' => 3.5])
        ->and($payload->custom)->toBe(['duration' => 'long', 'fatal' => 'no']);
});

it('round-trips to the stored array with custom dimensions spread at the top level', function (): void {
    $body = Beacons::body('pause', 5, ['currentTime' => 12.5, 'plan' => 'free']);

    expect(BeaconPayload::fromArray($body)->toArray())->toEqualCanonicalizing($body);
});

it('replaces custom dimensions and the address without touching anything else', function (): void {
    $payload = BeaconPayload::fromArray(Beacons::body('heartbeat', 0, ['email' => 'viewer@example.com']), '203.0.113.9');

    $redacted = $payload->withCustom([])->withIp(null);

    expect($redacted->custom)->toBe([])
        ->and($redacted->ip)->toBeNull()
        ->and($redacted->viewId)->toBe($payload->viewId)
        ->and($redacted->context)->toBe($payload->context)
        ->and($payload->custom)->toBe(['email' => 'viewer@example.com']);
});

it('knows every key the 1.16.x analytics plugin sends', function (): void {
    // index.ts sendBeacon()/sendUnloadBeacon() base keys, then every data object
    // the plugin passes (heartbeat, videoStart, rebufferEnd, pause, rebufferStart,
    // seeking, error, qualityChange, both viewEnd variants, the latency summary).
    $shipped = [
        'videoTitle', 'isLive', 'playerVersion', 'playerName', 'browser', 'os', 'deviceType',
        'screenSize', 'playerSize', 'connectionType', 'watchTime', 'playTime', 'currentTime',
        'duration', 'rebufferCount', 'rebufferDuration', 'avgBitrate', 'qoeScore', 'startupTime',
        'totalRebufferTime', 'seekCount', 'seekTo', 'errorType', 'errorMessage', 'errorCode',
        'fatal', 'bitrate', 'width', 'height', 'auto', 'rebufferRatio', 'maxBitrate',
        'qualityChanges', 'pauseCount', 'pauseDuration', 'errorCount', 'exitType',
        'completionRate', 'liveLatencySamples', 'liveLatencyMean', 'liveLatencyP95',
        'liveLatencyMax', 'lowLatency',
    ];

    expect(array_keys([...BeaconPayload::CONTEXT, ...BeaconPayload::FIELDS]))->toEqualCanonicalizing($shipped);
});
