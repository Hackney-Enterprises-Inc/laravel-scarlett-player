<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Generators;

use Hei\ScarlettPlayer\Contracts\ClipGenerator;
use Hei\ScarlettPlayer\Data\ClipOptions;
use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Enums\ClipAccuracy;
use Hei\ScarlettPlayer\Exceptions\ClipGenerationException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The single-box driver: ffmpeg on the worker, reading the mezzanine (or, without one,
 * the playback URL) and writing an mp4 to a temporary local file.
 *
 * Keyframe mode seeks on the input and stream copies, so the output can start up to a
 * GOP before the in point. RenderClip only asks for it on unprotected sources, and
 * ClipVerifier rejects any output whose packet span exceeds the request.
 */
class LocalFfmpegGenerator implements ClipGenerator
{
    public function __construct(
        private readonly string $binary = 'ffmpeg',
        private readonly int $timeout = 300,
        private readonly ?string $tempDirectory = null,
    ) {}

    public function generate(MediaSource $source, float $start, float $end, ClipOptions $options): string
    {
        $input = $this->input($source, $options);
        $output = $this->temporaryPath($options);

        $result = Process::timeout(max(1, $options->timeout ?: $this->timeout))
            ->run($this->command($input, $start, $end, $options->accuracy, $output));

        if (! $result->successful() || ! is_file($output) || filesize($output) === 0) {
            @unlink($output);

            throw ClipGenerationException::renderFailed($source->id, $result->errorOutput() ?: $result->output());
        }

        return $output;
    }

    /**
     * The ffmpeg argument list for one render.
     *
     * Exact: `-i` first, then `-ss`/`-to` as output options, re-encoded, so decoding
     * starts before the in point and nothing earlier is written. Keyframe: `-ss`/`-to`
     * before `-i` with stream copy.
     *
     * @return list<string>
     */
    public function command(string $input, float $start, float $end, ClipAccuracy $accuracy, string $output): array
    {
        $head = [$this->binary, '-hide_banner', '-nostdin', '-loglevel', 'error', '-y'];
        $range = ['-ss', $this->seconds($start), '-to', $this->seconds($end)];
        $map = ['-map', '0:v:0', '-map', '0:a:0?'];
        $tail = ['-movflags', '+faststart', $output];

        if ($accuracy === ClipAccuracy::Keyframe) {
            return [...$head, ...$range, '-i', $input, ...$map, '-c', 'copy', ...$tail];
        }

        return [
            ...$head, '-i', $input, ...$range, ...$map,
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20',
            '-c:a', 'aac', '-b:a', '128k',
            ...$tail,
        ];
    }

    /**
     * What ffmpeg reads: a local mezzanine path, a temporary URL to a remote mezzanine,
     * or the playback URL when the source has no mezzanine.
     */
    public function input(MediaSource $source, ?ClipOptions $options = null): string
    {
        if ($source->sourceDisk !== null && $source->sourcePath !== null) {
            $disk = Storage::disk($source->sourceDisk);

            if (config("filesystems.disks.{$source->sourceDisk}.driver") === 'local') {
                return $disk->path($source->sourcePath);
            }

            try {
                return $disk->temporaryUrl($source->sourcePath, now()->addSeconds(($options->timeout ?? $this->timeout) + 60));
            } catch (Throwable) {
                // A disk without temporary URLs falls through to the playback URL.
            }
        }

        if ($source->playbackUrl !== '') {
            return $source->playbackUrl;
        }

        throw ClipGenerationException::noSource($source->id);
    }

    private function temporaryPath(ClipOptions $options): string
    {
        $directory = $this->tempDirectory ?? sys_get_temp_dir().DIRECTORY_SEPARATOR.'scarlett-clips';

        if (! is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }

        // Unique per call, so two deliveries of one clip never write the same file.
        return $directory.DIRECTORY_SEPARATOR.$options->clipUuid.'-'.Str::random(8).'.mp4';
    }

    private function seconds(float $value): string
    {
        return sprintf('%.3F', $value);
    }
}
