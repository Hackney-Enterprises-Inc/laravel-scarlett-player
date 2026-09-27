<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Doctor\Checks;

use Hei\ScarlettPlayer\Doctor\Check;
use Hei\ScarlettPlayer\Doctor\CheckResult;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The clip disk must accept a private write, report it private, and issue temporary
 * URLs: previews need them in every delivery mode, and signed-redirect needs them for /play.
 */
class ClipDiskCheck implements Check
{
    public function name(): string
    {
        return 'Clip disk';
    }

    public function run(): CheckResult
    {
        $name = (string) config('scarlett-player.clips.disk');

        if ($name === '' || config("filesystems.disks.{$name}") === null) {
            return CheckResult::fail("clips.disk [{$name}] is not a configured filesystem disk.");
        }

        $path = trim((string) config('scarlett-player.clips.path', 'clips'), '/').'/.scarlett-doctor-'.Str::random(8);

        try {
            $disk = Storage::disk($name);

            if (! $disk->put($path, 'scarlett', ['visibility' => 'private'])) {
                return CheckResult::fail("Could not write to clip disk [{$name}].");
            }

            try {
                if ($disk->getVisibility($path) !== 'private') {
                    return CheckResult::fail("Clip disk [{$name}] did not keep a private write private. Clip assets must never be public on render.");
                }

                // Previews always redirect to a temporary URL, whatever the delivery mode.
                try {
                    $disk->temporaryUrl($path, now()->addMinute());
                } catch (Throwable) {
                    return CheckResult::fail("Clip disk [{$name}] cannot issue temporary URLs, which previews (and signed-redirect delivery) need.");
                }
            } finally {
                $disk->delete($path);
            }
        } catch (Throwable $e) {
            return CheckResult::fail("Clip disk [{$name}] failed: {$e->getMessage()}");
        }

        return CheckResult::pass("Clip disk [{$name}] writes private objects and issues temporary URLs.");
    }
}
