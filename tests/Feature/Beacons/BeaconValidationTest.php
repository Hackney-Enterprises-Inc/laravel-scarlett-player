<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Jobs\ProcessBeacon;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\Beacons;
use Hei\ScarlettPlayer\Tests\TestCase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/*
 * Cheap validation before the queue: known keys by type, unknown keys never
 * rejected, one JSON object per request.
 */

beforeEach(function (): void {
    Queue::fake();
});

/**
 * @param  array<string, mixed>|list<mixed>|string  $body
 */
function sendBeaconBody(TestCase $test, array|string $body, string $contentType = 'application/json'): TestResponse
{
    return $test->call('POST', '/api/scarlett/beacons', [], [], [], [
        'CONTENT_TYPE' => $contentType,
        'HTTP_ACCEPT' => '*/*',
        'HTTP_X_API_KEY' => TestCase::BEACON_KEY,
    ], is_string($body) ? $body : (string) json_encode($body));
}

it('stores unknown custom-dimension keys in custom, never a 422', function (): void {
    sendBeaconBody($this, Beacons::body('heartbeat', 0, [
        'plan' => 'ppv',
        'tenantId' => 42,
        'playbackId' => 'future-key-from-a-newer-player',
        'diagnostics' => ['buffer' => [1, 2, 3]],
    ]))->assertNoContent();

    Queue::assertPushed(ProcessBeacon::class, fn (ProcessBeacon $job): bool => $job->payload->custom === [
        'plan' => 'ppv',
        'tenantId' => 42,
        'playbackId' => 'future-key-from-a-newer-player',
        'diagnostics' => ['buffer' => [1, 2, 3]],
    ]);
});

it('returns 204 without aggregating synchronously: the job is queued on the beacons queue and connection', function (): void {
    config()->set('scarlett-player.beacons.connection', 'redis');
    config()->set('scarlett-player.beacons.queue', 'scarlett-beacons');

    sendBeaconBody($this, Beacons::body('heartbeat', 0, ['watchTime' => 1]))->assertNoContent();

    Queue::assertPushedOn('scarlett-beacons', ProcessBeacon::class);
    Queue::assertPushed(ProcessBeacon::class, fn (ProcessBeacon $job): bool => $job->connection === 'redis');
});

it('parses the body as JSON whatever the Content-Type says', function (): void {
    sendBeaconBody($this, Beacons::body('viewEnd'), 'text/plain;charset=UTF-8')->assertNoContent();

    Queue::assertPushed(ProcessBeacon::class, 1);
});

it('accepts a known key carrying another type and keeps it as a custom dimension', function (string $key, mixed $value): void {
    sendBeaconBody($this, Beacons::body('heartbeat', 0, [$key => $value]))->assertNoContent();

    Queue::assertPushed(ProcessBeacon::class, fn (ProcessBeacon $job): bool => $job->payload->custom === [$key => $value]
        && ! $job->payload->has($key));
})->with([
    'watchTime as a string' => ['watchTime', '10'],
    'isLive as a string' => ['isLive', 'false'],
    'fatal as a number' => ['fatal', 1],
    'exitType as a number' => ['exitType', 3],
    'errorCode as an object' => ['errorCode', ['a' => 1]],
    'a host dimension named duration' => ['duration', 'long'],
]);

it('never refuses a long context string: a long videoTitle must not drop the views beacons', function (): void {
    sendBeaconBody($this, Beacons::body('viewEnd', 0, ['videoTitle' => str_repeat('t', 5000)]))->assertNoContent();

    Queue::assertPushed(ProcessBeacon::class, fn (ProcessBeacon $job): bool => mb_strlen((string) $job->payload->get('videoTitle')) === 5000);
});

it('answers a body over beacons.max_body_bytes with a JSON 413', function (): void {
    config()->set('scarlett-player.beacons.max_body_bytes', 1024);

    sendBeaconBody($this, Beacons::body('heartbeat', 0, ['blob' => str_repeat('x', 1024)]))
        ->assertStatus(413)
        ->assertExactJson(['message' => 'A beacon body is at most 1024 bytes.']);

    sendBeaconBody($this, Beacons::body('heartbeat'))->assertNoContent();

    Queue::assertPushed(ProcessBeacon::class, 1);
});

it('caps beacon bodies at 64 KiB by default', function (): void {
    expect(config('scarlett-player.beacons.max_body_bytes'))->toBe(65536);
});

it('requires the four ids, the event and an integer timestamp', function (string $key, mixed $value): void {
    $body = Beacons::body('heartbeat');
    $body[$key] = $value;

    sendBeaconBody($this, $body)->assertStatus(422)->assertJsonStructure(['errors' => [$key]]);
})->with([
    'no viewId' => ['viewId', null],
    'empty sessionId' => ['sessionId', ''],
    'numeric viewerId' => ['viewerId', 7],
    'no videoId' => ['videoId', null],
    'no event' => ['event', null],
    'timestamp as a string' => ['timestamp', '1790000000000'],
    'negative timestamp' => ['timestamp', -1],
    'fractional timestamp' => ['timestamp', 1.5],
]);

it('accepts errorCode as a string or a number', function (mixed $code): void {
    sendBeaconBody($this, Beacons::body('error', 0, [
        'errorType' => 'NetworkError', 'errorMessage' => 'x', 'errorCode' => $code, 'fatal' => false,
    ]))->assertNoContent();
})->with(['string' => 'E_NET', 'number' => 3]);

it('refuses a batched array body by name', function (): void {
    sendBeaconBody($this, [Beacons::body('heartbeat', 1), Beacons::body('heartbeat', 2)])
        ->assertStatus(422)
        ->assertExactJson(['message' => 'A beacon body is one JSON object; batched beacons are not supported.']);
});

it('refuses a body that is not a JSON object', function (string $body): void {
    sendBeaconBody($this, $body)->assertStatus(422)->assertExactJson(['message' => 'A beacon body is one JSON object.']);
})->with(['empty' => '', 'not json' => 'event=viewStart', 'a scalar' => '42', 'an empty object' => '{}']);

it('answers 204 and queues nothing while beacons.enabled is off', function (): void {
    config()->set('scarlett-player.beacons.enabled', false);

    sendBeaconBody($this, Beacons::body('viewStart'))->assertNoContent();

    Queue::assertNothingPushed();
});

it('attaches no client address unless beacons.store_ip is on, and truncates it when anonymize_ip is', function (bool $store, bool $anonymize, ?string $expected): void {
    config()->set('scarlett-player.beacons.store_ip', $store);
    config()->set('scarlett-player.beacons.anonymize_ip', $anonymize);

    $this->call('POST', '/api/scarlett/beacons', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_API_KEY' => TestCase::BEACON_KEY,
        'REMOTE_ADDR' => '203.0.113.77',
    ], (string) json_encode(Beacons::body('viewStart')))->assertNoContent();

    Queue::assertPushed(ProcessBeacon::class, fn (ProcessBeacon $job): bool => $job->payload->ip === $expected);
})->with([
    'default: not stored' => [false, true, null],
    'stored, anonymised' => [true, true, '203.0.113.0'],
    'stored in full' => [true, false, '203.0.113.77'],
]);

it('accepts isLive null on a viewStart as absent, never a 422 and never a custom dimension', function (): void {
    sendBeaconBody($this, Beacons::body('viewStart', 0, ['isLive' => null]))->assertNoContent();

    Queue::assertPushed(ProcessBeacon::class, fn (ProcessBeacon $job): bool => ! $job->payload->has('isLive')
        && ! array_key_exists('isLive', $job->payload->custom));
});

it('queues player 1.19.3 order and seek fields separately from host dimensions', function (): void {
    sendBeaconBody($this, Beacons::body('seeking', 0, [
        'beaconSeq' => 3, 'seekSource' => 'element', 'campaign' => 'spring',
    ]))->assertNoContent();

    Queue::assertPushed(ProcessBeacon::class, fn (ProcessBeacon $job): bool => $job->payload->fields === [
        'beaconSeq' => 3, 'seekSource' => 'element',
    ] && $job->payload->custom === ['campaign' => 'spring']);
});

it('accepts wrong-type player 1.19.3 fields as custom dimensions over HTTP', function (): void {
    sendBeaconBody($this, Beacons::body('seeking', 0, [
        'beaconSeq' => '3', 'seekSource' => 7, 'campaign' => 'spring',
    ]))->assertNoContent();

    Queue::assertPushed(ProcessBeacon::class, fn (ProcessBeacon $job): bool => $job->payload->fields === []
        && $job->payload->custom === ['beaconSeq' => '3', 'seekSource' => 7, 'campaign' => 'spring']);
});
