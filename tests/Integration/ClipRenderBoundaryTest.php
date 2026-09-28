<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Clips\ClipVerifier;
use Hei\ScarlettPlayer\Clips\Verification;
use Hei\ScarlettPlayer\Data\ClipOptions;
use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Enums\ClipAccuracy;
use Hei\ScarlettPlayer\Enums\ClipStatus;
use Hei\ScarlettPlayer\Generators\ClipGeneratorManager;
use Hei\ScarlettPlayer\Jobs\RenderClip;
use Hei\ScarlettPlayer\Tests\Feature\Clips\Support\ClipTestSupport as T;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/*
 * The rights boundary, on real media. A synthetic source with a 10 s GOP (testsrc,
 * 25 fps, -g 250) is cut at [9, 69] both ways: the keyframe stream copy starts at the
 * keyframe before the in point and carries about 69 s of video in a file whose nominal
 * duration is about 60 s, and the verifier must reject it; the exact render must pass.
 * Argument-order unit tests cannot establish this; only real packets can.
 */

beforeEach(function (): void {
    $this->workdir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'scarlett-boundary-'.bin2hex(random_bytes(6));
    mkdir($this->workdir, 0700, true);

    (new Process([
        (string) config('scarlett-player.clips.generators.local-ffmpeg.binary'),
        '-hide_banner', '-loglevel', 'error', '-nostdin', '-y',
        '-f', 'lavfi', '-i', 'testsrc=size=320x240:rate=25:duration=80',
        '-f', 'lavfi', '-i', 'sine=frequency=440:duration=80',
        '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p',
        '-g', '250', '-keyint_min', '250', '-sc_threshold', '0',
        '-c:a', 'aac', '-shortest',
        $this->workdir.DIRECTORY_SEPARATOR.'source.mp4',
    ]))->setTimeout(120)->mustRun();

    config()->set('filesystems.disks.mezzanine', ['driver' => 'local', 'root' => $this->workdir]);

    $this->source = fn (bool $protected): MediaSource => new MediaSource(
        id: $protected ? 'ppv-1' : 'vid-1',
        playbackUrl: 'https://cdn.example.test/unused.m3u8',
        isLive: false,
        isProtected: $protected,
        duration: 80.0,
        sourceDisk: 'mezzanine',
        sourcePath: 'source.mp4',
    );
});

afterEach(function (): void {
    // The group's beforeEach skips this file without ffmpeg, so the workdir is never set.
    if (! isset($this->workdir)) {
        return;
    }

    foreach (glob($this->workdir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($this->workdir);
});

function renderRange(ClipAccuracy $accuracy, MediaSource $source): string
{
    return app(ClipGeneratorManager::class)->driver()->generate($source, 9.0, 69.0, new ClipOptions($accuracy, 'boundary-'.$accuracy->value, 120));
}

function containerDuration(string $file): float
{
    $probe = (new Process([
        (string) config('scarlett-player.clips.generators.local-ffmpeg.ffprobe'),
        '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $file,
    ]))->setTimeout(30)->mustRun();

    return (float) trim($probe->getOutput());
}

test('the keyframe copy of [9, 69] fails the verifier on packet span, though its container duration looks fine', function (): void {
    $file = renderRange(ClipAccuracy::Keyframe, ($this->source)(false));

    try {
        $result = app(ClipVerifier::class)->verify($file, 9.0, 69.0, false);

        expect(containerDuration($file))->toBeLessThan(61.0)
            ->and($result->passed)->toBeFalse()
            ->and($result->reason)->toBe(Verification::REASON_EXCEEDS_BOUNDS)
            ->and(($result->span ?? 0) > 61.0 || ($result->earliest ?? 0) < -1.0)->toBeTrue();
    } finally {
        @unlink($file);
    }
});

test('the exact render of [9, 69] passes the verifier', function (): void {
    $file = renderRange(ClipAccuracy::Exact, ($this->source)(false));

    try {
        $result = app(ClipVerifier::class)->verify($file, 9.0, 69.0, false);

        expect($result->passed)->toBeTrue()
            ->and($result->earliest)->toBeGreaterThanOrEqual(-1.0)
            ->and($result->span)->toBeLessThanOrEqual(61.0)
            ->and($result->span)->toBeGreaterThan(59.0);
    } finally {
        @unlink($file);
    }
});

describe('end to end through RenderClip', function (): void {
    beforeEach(function (): void {
        T::createUsers();
        $this->usesMigrations();
        T::fakeDisk();
        config()->set('scarlett-player.clips.accuracy', 'keyframe');
        config()->set('scarlett-player.clips.max_duration', 60);
    });

    test('a protected source renders exact and becomes ready despite accuracy keyframe', function (): void {
        T::media(($this->source)(true));
        $clip = T::clip(['media_id' => 'ppv-1', 'start_seconds' => 9, 'end_seconds' => 69, 'duration_seconds' => 60]);

        app()->call([new RenderClip($clip->id), 'handle']);

        expect($clip->fresh()?->status)->toBe(ClipStatus::Ready);
        Storage::disk('clips')->assertExists("clips/{$clip->uuid}.mp4");
    });

    test('an unprotected keyframe render that leaks past the in point falls back to exact and stores a bounded clip', function (): void {
        T::media(($this->source)(false));
        $clip = T::clip(['media_id' => 'vid-1', 'start_seconds' => 9, 'end_seconds' => 69, 'duration_seconds' => 60]);

        app()->call([new RenderClip($clip->id), 'handle']);

        $clip->refresh();
        $stored = Storage::disk('clips')->path("clips/{$clip->uuid}.mp4");

        expect($clip->status)->toBe(ClipStatus::Ready)
            ->and(app(ClipVerifier::class)->verify($stored, 9.0, 69.0, false)->passed)->toBeTrue();
    });
});
