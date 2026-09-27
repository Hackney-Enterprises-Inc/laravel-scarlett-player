<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Exceptions;

/**
 * A clip generator could not produce a file: no usable source, or the renderer failed.
 */
class ClipGenerationException extends ScarlettPlayerException
{
    public static function noSource(string $mediaId): self
    {
        return new self("Scarlett media [{$mediaId}] has neither a readable mezzanine nor a playback URL to clip from.");
    }

    public static function renderFailed(string $mediaId, string $output): self
    {
        return new self("Rendering a clip of Scarlett media [{$mediaId}] failed: ".trim($output));
    }

    public static function storeFailed(string $clipUuid, string $disk, string $path, string $why): self
    {
        return new self("Storing clip [{$clipUuid}] at [{$path}] on disk [{$disk}] failed: {$why}");
    }

    public static function unknownDriver(string $driver): self
    {
        return new self("Scarlett clip generator [{$driver}] is not defined under clips.generators.");
    }
}
