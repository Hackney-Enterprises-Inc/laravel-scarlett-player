<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Contracts;

use Hei\ScarlettPlayer\Data\MediaSource;

/**
 * Implemented by a host model that owns its own media mapping. The package never guesses
 * which column is the playback URL or whether the media is protected.
 */
interface ScarlettMedia
{
    /**
     * Build the media source for this model, deciding isLive and isProtected explicitly.
     */
    public function toScarlettMediaSource(): MediaSource;

    /**
     * The id the player submits for this model (HasScarlettClips defaults it to the route key).
     */
    public function scarlettMediaId(): string;
}
