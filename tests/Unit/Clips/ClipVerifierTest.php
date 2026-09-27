<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Clips\ClipVerifier;
use Hei\ScarlettPlayer\Clips\Verification;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

/**
 * Fake ffprobe answering with these video packet pts values.
 *
 * @param  list<string>  $pts
 */
function probeReturns(array $pts, string $start = '0.000000', float $containerDuration = 60.0): void
{
    Process::fake(fn () => Process::result((string) json_encode([
        'packets' => array_map(fn (string $t): array => ['stream_index' => 0, 'pts_time' => $t], $pts),
        'streams' => [['index' => 0, 'codec_type' => 'video']],
        'format' => ['start_time' => $start, 'duration' => (string) $containerDuration],
    ])));
}

function verifier(): ClipVerifier
{
    return app(ClipVerifier::class);
}

test('a render inside the requested span passes', function (): void {
    probeReturns(['0.000000', '30.000000', '59.960000']);

    $result = verifier()->verify('/tmp/clip.mp4', 9, 69, false);

    expect($result->passed)->toBeTrue()
        ->and($result->reason)->toBeNull()
        ->and($result->earliest)->toBe(0.0)
        ->and($result->span)->toBe(59.96)
        ->and($result->limit)->toBe(61.0);
});

test('it measures the packet span, not the container duration', function (): void {
    // The reproduced keyframe copy: nominal container duration 60.01 s, 68.96 s of packets.
    probeReturns(['0.000000', '68.960000'], containerDuration: 60.01);

    $result = verifier()->verify('/tmp/clip.mp4', 9, 69, false);

    expect($result->passed)->toBeFalse()
        ->and($result->reason)->toBe(Verification::REASON_EXCEEDS_BOUNDS)
        ->and($result->span)->toBe(68.96);
});

test('packets before the container start beyond the tolerance fail', function (): void {
    probeReturns(['-9.000000', '0.000000', '50.000000']);

    $result = verifier()->verify('/tmp/clip.mp4', 9, 69, false);

    expect($result->passed)->toBeFalse()
        ->and($result->reason)->toBe(Verification::REASON_EXCEEDS_BOUNDS)
        ->and($result->earliest)->toBe(-9.0);
});

test('earliest is measured relative to the container start_time', function (): void {
    probeReturns(['1.400000', '61.300000'], start: '1.400000');

    $result = verifier()->verify('/tmp/clip.mp4', 0, 60, false);

    expect($result->passed)->toBeTrue()
        ->and($result->earliest)->toBe(0.0)
        ->and(round((float) $result->span, 3))->toBe(59.9);
});

test('the tolerance comes from config', function (): void {
    config()->set('scarlett-player.clips.duration_tolerance', 0.25);
    probeReturns(['0.000000', '30.500000']);

    expect(verifier()->verify('/tmp/clip.mp4', 0, 30, false)->passed)->toBeFalse();
});

test('a protected source is also capped at max_duration plus tolerance', function (): void {
    config()->set('scarlett-player.clips.max_duration', 20);
    probeReturns(['0.000000', '29.900000']);

    expect(verifier()->verify('/tmp/clip.mp4', 0, 30, true)->passed)->toBeFalse()
        ->and(verifier()->verify('/tmp/clip.mp4', 0, 30, false)->passed)->toBeTrue();
});

test('a file with no readable video packets fails as unreadable', function (array $packets): void {
    Process::fake(fn () => Process::result((string) json_encode(['packets' => $packets, 'format' => []])));

    $result = verifier()->verify('/tmp/clip.mp4', 0, 30, false);

    expect($result->passed)->toBeFalse()->and($result->reason)->toBe(Verification::REASON_UNREADABLE);
})->with([
    'no packets' => [[]],
    'only N/A pts' => [[['pts_time' => 'N/A']]],
]);

test('an ffprobe failure fails as unreadable', function (): void {
    Process::fake(fn () => Process::result(errorOutput: 'moov atom not found', exitCode: 1));

    expect(verifier()->verify('/tmp/clip.mp4', 0, 30, false)->reason)->toBe(Verification::REASON_UNREADABLE);
});

test('it probes every stream with the configured ffprobe', function (): void {
    config()->set('scarlett-player.clips.generators.local-ffmpeg.ffprobe', '/opt/bin/ffprobe');
    probeReturns(['0.000000', '10.000000']);

    verifier()->verify('/tmp/clip.mp4', 0, 10, false);

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [
        '/opt/bin/ffprobe', '-v', 'error',
        '-show_entries', 'packet=stream_index,pts_time:stream=index,codec_type:format=start_time',
        '-of', 'json', '/tmp/clip.mp4',
    ]);
});

test('a file with no video stream fails as unreadable, whatever else it carries', function (): void {
    Process::fake(fn () => Process::result((string) json_encode([
        'packets' => [['stream_index' => 0, 'pts_time' => '0.000000'], ['stream_index' => 0, 'pts_time' => '10.000000']],
        'streams' => [['index' => 0, 'codec_type' => 'audio']],
        'format' => ['start_time' => '0.000000'],
    ])));

    expect(verifier()->verify('/tmp/clip.mp4', 0, 10, false)->reason)->toBe(Verification::REASON_UNREADABLE);
});

test('the span counts every stream: audio running past the range fails', function (): void {
    Process::fake(fn () => Process::result((string) json_encode([
        'packets' => [
            ['stream_index' => 0, 'pts_time' => '0.000000'],
            ['stream_index' => 0, 'pts_time' => '29.960000'],
            ['stream_index' => 1, 'pts_time' => '0.000000'],
            ['stream_index' => 1, 'pts_time' => '45.000000'],
        ],
        'streams' => [['index' => 0, 'codec_type' => 'video'], ['index' => 1, 'codec_type' => 'audio']],
        'format' => ['start_time' => '0.000000'],
    ])));

    $result = verifier()->verify('/tmp/clip.mp4', 0, 30, false);

    expect($result->passed)->toBeFalse()
        ->and($result->reason)->toBe(Verification::REASON_EXCEEDS_BOUNDS)
        ->and($result->span)->toBe(45.0);
});

test('audio packets before the container start count against the earliest bound', function (): void {
    Process::fake(fn () => Process::result((string) json_encode([
        'packets' => [
            ['stream_index' => 0, 'pts_time' => '0.000000'],
            ['stream_index' => 1, 'pts_time' => '-5.000000'],
            ['stream_index' => 0, 'pts_time' => '20.000000'],
        ],
        'streams' => [['index' => 0, 'codec_type' => 'video'], ['index' => 1, 'codec_type' => 'audio']],
        'format' => ['start_time' => '0.000000'],
    ])));

    expect(verifier()->verify('/tmp/clip.mp4', 0, 30, false)->passed)->toBeFalse();
});
