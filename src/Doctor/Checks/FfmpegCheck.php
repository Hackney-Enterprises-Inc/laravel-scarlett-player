<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Doctor\Checks;

use Hei\ScarlettPlayer\Doctor\Check;
use Hei\ScarlettPlayer\Doctor\CheckResult;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * The local-ffmpeg generator needs ffmpeg and ffprobe; ClipVerifier needs ffprobe for
 * every driver.
 */
class FfmpegCheck implements Check
{
    public function name(): string
    {
        return 'Clip toolchain (ffmpeg, ffprobe)';
    }

    public function run(): CheckResult
    {
        $generator = (string) config('scarlett-player.clips.generator', 'local-ffmpeg');
        $config = (array) config("scarlett-player.clips.generators.{$generator}", []);

        $binaries = ['ffprobe' => is_string($config['ffprobe'] ?? null) ? $config['ffprobe'] : 'ffprobe'];

        if (($config['driver'] ?? $generator) === 'local-ffmpeg') {
            $binaries = ['ffmpeg' => is_string($config['binary'] ?? null) ? $config['binary'] : 'ffmpeg'] + $binaries;
        }

        $missing = [];

        foreach ($binaries as $name => $binary) {
            try {
                if (! Process::timeout(15)->run([$binary, '-version'])->successful()) {
                    $missing[] = "{$name} ({$binary})";
                }
            } catch (Throwable) {
                $missing[] = "{$name} ({$binary})";
            }
        }

        if ($missing !== []) {
            return CheckResult::fail('Not runnable: '.implode(', ', $missing).'. Install it or set FFMPEG_BINARY / FFPROBE_BINARY.');
        }

        return CheckResult::pass(implode(' and ', array_keys($binaries)).' run.');
    }
}
