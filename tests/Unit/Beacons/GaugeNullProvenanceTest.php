<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Data\BeaconPayload;
use Hei\ScarlettPlayer\Jobs\ProcessBeacon;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\Beacons;

/*
 * Narrow explicit-null provenance for the two gauges. The wire carries
 * completionRate: null on live views, but fromArray() drops nulls, so an
 * explicit null would be indistinguishable from an omitted gauge. The DTO
 * remembers which gauges arrived as an explicit null, without changing
 * get()/has()/raw classification, the legacy hash basis or the stored arrays.
 * Restored jobs queued before this provenance existed keep treating gauge
 * nulls as absent.
 */

it('remembers the gauges received as an explicit null', function (): void {
    $payload = BeaconPayload::fromArray(Beacons::body('viewEnd', 0, [
        'completionRate' => null,
        'rebufferRatio' => 44.44,
        'exitType' => 'liveEnded',
    ]));

    expect($payload->explicitlyNullGauge('completionRate'))->toBeTrue()
        ->and($payload->explicitlyNullGauge('rebufferRatio'))->toBeFalse()
        ->and($payload->explicitlyNullGauge('qoeScore'))->toBeFalse()
        // The legacy classification is unchanged: nulls are still absent.
        ->and($payload->has('completionRate'))->toBeFalse()
        ->and($payload->get('completionRate'))->toBeNull()
        ->and($payload->has('rebufferRatio'))->toBeTrue()
        ->and($payload->get('rebufferRatio'))->toBe(44.44)
        ->and($payload->fields)->not->toHaveKey('completionRate')
        ->and($payload->custom)->not->toHaveKey('completionRate');
});

it('remembers both gauges when both arrive as explicit nulls', function (): void {
    $payload = BeaconPayload::fromArray(Beacons::body('viewEnd', 0, [
        'completionRate' => null,
        'rebufferRatio' => null,
    ]));

    expect($payload->explicitlyNullGauge('completionRate'))->toBeTrue()
        ->and($payload->explicitlyNullGauge('rebufferRatio'))->toBeTrue();
});

it('treats omitted gauges as absent, with no provenance claim', function (): void {
    $payload = BeaconPayload::fromArray(Beacons::body('heartbeat', 0));

    expect($payload->explicitlyNullGauge('completionRate'))->toBeFalse()
        ->and($payload->explicitlyNullGauge('rebufferRatio'))->toBeFalse();
});

it('does not move the legacy hash basis for explicit gauge nulls', function (): void {
    $withNulls = BeaconPayload::fromArray(Beacons::body('viewEnd', 0, [
        'completionRate' => null,
        'rebufferRatio' => null,
        'exitType' => 'liveEnded',
    ]));
    $without = BeaconPayload::fromArray(Beacons::body('viewEnd', 0, [
        'exitType' => 'liveEnded',
    ]));

    expect($withNulls->bodyHash)->toBe($without->bodyHash);
});

it('keeps the explicit null out of the stored and raw-log arrays', function (): void {
    $payload = BeaconPayload::fromArray(Beacons::body('viewEnd', 0, [
        'completionRate' => null,
        'exitType' => 'liveEnded',
    ]));

    expect($payload->toArray())->not->toHaveKey('completionRate')
        ->and($payload->browserArray())->not->toHaveKey('completionRate');
});

it('carries the provenance through every with*() transformation', function (): void {
    $payload = BeaconPayload::fromArray(Beacons::body('viewEnd', 0, [
        'completionRate' => null,
        'tenant' => 'wire',
    ]));

    foreach ([
        $payload->withCustom(['plan' => 'free']),
        $payload->withServer(['tenant' => 7]),
        $payload->withIp('203.0.113.9'),
        $payload->withCustom([])->withServer(['tenant' => null])->withIp(null),
    ] as $moved) {
        expect($moved->explicitlyNullGauge('completionRate'))->toBeTrue()
            ->and($moved->bodyHash)->toBe($payload->bodyHash);
    }
});

it('survives queue serialization in both generations', function (): void {
    $payload = BeaconPayload::fromArray(Beacons::body('viewEnd', 0, [
        'completionRate' => null,
        'rebufferRatio' => 44.44,
    ]));

    $job = unserialize(serialize(new ProcessBeacon($payload)));

    expect($job->payload->explicitlyNullGauge('completionRate'))->toBeTrue()
        ->and($job->payload->explicitlyNullGauge('rebufferRatio'))->toBeFalse()
        ->and($job->payload->bodyHash)->toBe($payload->bodyHash);

    $plain = unserialize(serialize($payload));
    expect($plain->explicitlyNullGauge('completionRate'))->toBeTrue();
});

it('treats gauge nulls as absent when restoring a job queued before the provenance existed', function (bool $serialVersion): void {
    $identity = [
        'event' => 'viewEnd', 'timestamp' => Beacons::T0, 'viewId' => Beacons::VIEW,
        'sessionId' => 'session-1', 'viewerId' => 'viewer-1', 'videoId' => 'video-1',
    ];
    $fields = ['exitType' => 'liveEnded'];
    $state = array_replace($identity, ['context' => [], 'fields' => $fields, 'custom' => [], 'ip' => null]);
    if ($serialVersion) {
        // A serialVersion=1 job written before gaugeNulls existed: the key is absent.
        $state['serialVersion'] = 1;
    }
    $serialized = str_replace('O:8:"stdClass"', 'O:'.strlen(BeaconPayload::class).':"'.BeaconPayload::class.'"', serialize((object) $state));

    /** @var BeaconPayload $restored */
    $restored = unserialize($serialized);

    expect($restored->explicitlyNullGauge('completionRate'))->toBeFalse()
        ->and($restored->explicitlyNullGauge('rebufferRatio'))->toBeFalse();
})->with(['pre-marker job' => false, 'serialVersion=1 job without the key' => true]);

it('restores only the two gauge keys from serialized provenance', function (): void {
    $payload = BeaconPayload::fromArray(Beacons::body('viewEnd', 0, ['completionRate' => null]));
    $serialized = serialize($payload);
    // A tampered or future serial cannot smuggle another name into the claim.
    $tampered = str_replace(serialize(['completionRate']), serialize(['completionRate', 'watchTime']), $serialized);

    /** @var BeaconPayload $restored */
    $restored = unserialize($tampered);

    expect($restored->explicitlyNullGauge('completionRate'))->toBeTrue()
        ->and($restored->explicitlyNullGauge('watchTime'))->toBeFalse();
});
