<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Data;

use Hei\ScarlettPlayer\Enums\ClipAccuracy;

/**
 * How a generator renders one clip.
 */
final readonly class ClipOptions
{
    /**
     * @param  ClipAccuracy  $accuracy  Exact for every protected source; RenderClip decides.
     * @param  string  $clipUuid  The clip being rendered, for temp file names and logs.
     * @param  int  $timeout  Seconds the generator may spend rendering.
     * @param  array<string, mixed>  $meta  Passed through to custom drivers.
     */
    public function __construct(
        public ClipAccuracy $accuracy,
        public string $clipUuid,
        public int $timeout = 300,
        public array $meta = [],
    ) {}

    /**
     * The accuracy a source must render with: exact whenever the source is protected,
     * otherwise the configured accuracy.
     */
    public static function accuracyFor(MediaSource $source, ClipAccuracy $configured): ClipAccuracy
    {
        return $source->isProtected ? ClipAccuracy::Exact : $configured;
    }
}
