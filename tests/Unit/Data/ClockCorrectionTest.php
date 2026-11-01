<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Data\ClockCorrection;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\Beacons;

/*
 * The offset maths of clock-skew correction (0.9.0): receipt time minus the
 * player's sentAt, applied to the beacon's own timestamp. A pure function of one
 * beacon, so delivery order cannot change it.
 */

const CLOCK_CAP = 86_400_000;

it('shifts a fast client clock back by its offset', function (): void {
    $sent = Beacons::T0 + 120_000; // client clock runs 120 s ahead
    $payload = Beacons::payload('heartbeat', 0, ['sentAt' => $sent])->withReceivedAt(Beacons::T0 + 40);

    $clock = ClockCorrection::for($payload, CLOCK_CAP);

    expect($clock->source)->toBe('sentAt')
        ->and($clock->offsetMs)->toBe(-119_960)
        ->and($clock->correct($payload->timestamp))->toBe(Beacons::T0 - 119_960);
});

it('shifts a slow client clock forward', function (): void {
    $payload = Beacons::payload('heartbeat', 0, ['sentAt' => Beacons::T0 - 5_000])->withReceivedAt(Beacons::T0 + 50);

    expect(ClockCorrection::for($payload, CLOCK_CAP)->offsetMs)->toBe(5_050);
});

it('keeps only the network time when the clocks agree', function (): void {
    $payload = Beacons::payload('heartbeat', 0, ['sentAt' => Beacons::T0])->withReceivedAt(Beacons::T0 + 80);

    expect(ClockCorrection::for($payload, CLOCK_CAP)->offsetMs)->toBe(80);
});

it('leaves a beacon uncorrected without sentAt', function (): void {
    $payload = Beacons::payload('heartbeat')->withReceivedAt(Beacons::T0 + 80);
    $clock = ClockCorrection::for($payload, CLOCK_CAP);

    expect($clock->source)->toBe('none')
        ->and($clock->offsetMs)->toBeNull()
        ->and($clock->correct($payload->timestamp))->toBe($payload->timestamp);
});

it('leaves a beacon uncorrected when no receipt time was captured (a job queued by 0.8.0)', function (): void {
    $payload = Beacons::payload('heartbeat', 0, ['sentAt' => Beacons::T0 + 120_000]);

    expect($payload->receivedAtMs())->toBeNull()
        ->and(ClockCorrection::for($payload, CLOCK_CAP)->source)->toBe('none');
});

it('ignores a sentAt of the wrong type, a negative one and a non-finite one', function (mixed $sentAt): void {
    $payload = Beacons::payload('heartbeat', 0, ['sentAt' => $sentAt])->withReceivedAt(Beacons::T0);

    expect(ClockCorrection::for($payload, CLOCK_CAP)->source)->toBe('none');
})->with(['string' => '123', 'negative' => -5, 'zero' => 0, 'infinite' => INF, 'nan' => NAN]);

it('rejects an offset beyond the cap and stores the beacon uncorrected', function (): void {
    $payload = Beacons::payload('heartbeat', 0, ['sentAt' => Beacons::T0 + 90_000_000])->withReceivedAt(Beacons::T0);
    $clock = ClockCorrection::for($payload, CLOCK_CAP);

    expect($clock->source)->toBe('rejected')
        ->and($clock->offsetMs)->toBe(-90_000_000)
        ->and($clock->correct($payload->timestamp))->toBe($payload->timestamp);
});

it('accepts an offset exactly at the cap', function (): void {
    $payload = Beacons::payload('heartbeat', 0, ['sentAt' => Beacons::T0 - CLOCK_CAP])->withReceivedAt(Beacons::T0);

    expect(ClockCorrection::for($payload, CLOCK_CAP)->source)->toBe('sentAt');
});

it('does not overflow on an absurd float sentAt', function (): void {
    $payload = Beacons::payload('heartbeat', 0, ['sentAt' => 1.0e300])->withReceivedAt(Beacons::T0);
    $clock = ClockCorrection::for($payload, CLOCK_CAP);

    expect($clock->source)->toBe('rejected')
        ->and($clock->offsetMs)->toBeNull();
});

it('survives the with*() copies and serialisation', function (): void {
    $payload = Beacons::payload('heartbeat', 0, ['sentAt' => Beacons::T0])->withReceivedAt(Beacons::T0 + 7);

    expect($payload->withCustom(['x' => 1])->receivedAtMs())->toBe(Beacons::T0 + 7)
        ->and($payload->withServer(['tenant' => 't'])->receivedAtMs())->toBe(Beacons::T0 + 7)
        ->and($payload->withIp('203.0.113.9')->receivedAtMs())->toBe(Beacons::T0 + 7)
        ->and(unserialize(serialize($payload))->receivedAtMs())->toBe(Beacons::T0 + 7);
});

it('does not move the body hash', function (): void {
    $payload = Beacons::payload('heartbeat', 0, ['sentAt' => Beacons::T0]);

    expect($payload->withReceivedAt(Beacons::T0 + 99)->bodyHash)->toBe($payload->bodyHash);
});
