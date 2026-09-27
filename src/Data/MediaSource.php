<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Data;

use Illuminate\Database\Eloquent\Model;

/**
 * One resolved piece of host media.
 *
 * isLive and isProtected deliberately have no defaults: every rights decision hangs on
 * them, so a caller cannot construct a MediaSource without deciding both.
 */
final readonly class MediaSource
{
    /**
     * @param  string  $id  The host id as submitted, echoed on every record.
     * @param  string  $playbackUrl  What the player plays (may be signed).
     * @param  bool  $isLive  Whether the source is a live stream.
     * @param  bool  $isProtected  PPV or entitled content. No default: the caller must decide.
     * @param  float|null  $duration  Seconds; null for live.
     * @param  string|null  $sourceDisk  Mezzanine disk name for clips.
     * @param  string|null  $sourcePath  Mezzanine path relative to the disk.
     * @param  string|null  $title  Display title.
     * @param  string|null  $poster  Poster image URL.
     * @param  Model|null  $model  The host model, for the polymorphic relation and policies.
     * @param  array<string, mixed>  $meta  Passed through to events and the config builder.
     */
    public function __construct(
        public string $id,
        public string $playbackUrl,
        public bool $isLive,
        public bool $isProtected,
        public ?float $duration,
        public ?string $sourceDisk = null,
        public ?string $sourcePath = null,
        public ?string $title = null,
        public ?string $poster = null,
        public ?Model $model = null,
        public array $meta = [],
    ) {}
}
