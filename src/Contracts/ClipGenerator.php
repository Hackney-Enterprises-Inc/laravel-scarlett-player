<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Contracts;

use Hei\ScarlettPlayer\Data\ClipOptions;
use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Exceptions\ClipGenerationException;

/**
 * Renders a clip from a media source. Drivers are registered on ClipGeneratorManager.
 *
 * A driver does not get to skip verification: RenderClip runs ClipVerifier on whatever
 * file it returns before the clip can become ready.
 */
interface ClipGenerator
{
    /**
     * Render [start,end] of $source to a temporary local file and return its path.
     * Always called from a queued job. Must be safe to call twice for the same clip.
     *
     * @param  float  $start  In point, media seconds.
     * @param  float  $end  Out point, media seconds.
     *
     * @throws ClipGenerationException when no file could be produced.
     */
    public function generate(MediaSource $source, float $start, float $end, ClipOptions $options): string;
}
