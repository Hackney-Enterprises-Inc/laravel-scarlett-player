<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * Proves the integration leg has a working toolchain before any clip test relies
 * on it: the ffmpeg and ffprobe the local-ffmpeg generator is configured with can
 * encode a clip and read it back. The binaries come from the same config keys the
 * driver reads, so a broken FFMPEG_BINARY or FFPROBE_BINARY fails here by name.
 */

it('encodes a 1 s test clip with the configured ffmpeg and reads it back with the configured ffprobe', function (): void {
    $ffmpeg = (string) config('scarlett-player.clips.generators.local-ffmpeg.binary');
    $ffprobe = (string) config('scarlett-player.clips.generators.local-ffmpeg.ffprobe');

    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'scarlett-toolchain-'.bin2hex(random_bytes(6));
    $clip = $directory.DIRECTORY_SEPARATOR.'testsrc.mp4';

    mkdir($directory, 0700, true);

    try {
        (new Process([
            $ffmpeg, '-hide_banner', '-loglevel', 'error', '-nostdin', '-y',
            '-f', 'lavfi', '-i', 'testsrc=duration=1:size=320x240:rate=25',
            '-c:v', 'libx264', '-pix_fmt', 'yuv420p',
            $clip,
        ]))->setTimeout(60)->mustRun();

        $probe = (new Process([
            $ffprobe, '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams',
            $clip,
        ]))->setTimeout(30)->mustRun();

        /** @var array{streams: list<array{codec_type: string}>, format: array{duration: string}} $info */
        $info = json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        $video = array_values(array_filter($info['streams'], fn (array $stream): bool => $stream['codec_type'] === 'video'));

        expect($info['streams'])->toHaveCount(1)
            ->and($video)->toHaveCount(1)
            ->and((float) $info['format']['duration'])->toEqualWithDelta(1.0, 0.1);
    } finally {
        if (is_file($clip)) {
            unlink($clip);
        }

        rmdir($directory);
    }
});
