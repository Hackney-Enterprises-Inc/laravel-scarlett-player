<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Tests\Fixtures\Provider;

use Hei\ScarlettPlayer\Contracts\ResolvesMedia;
use Hei\ScarlettPlayer\Data\MediaSource;

/**
 * A host resolver over an in-memory list, for tests of anything that resolves media.
 */
class ArrayResolver implements ResolvesMedia
{
    /** @var array<string, MediaSource> */
    public array $sources = [];

    /** @var list<string> */
    public array $calls = [];

    public function add(MediaSource $source): self
    {
        $this->sources[$source->id] = $source;

        return $this;
    }

    public function resolve(string $mediaId): ?MediaSource
    {
        $this->calls[] = $mediaId;

        return $this->sources[$mediaId] ?? null;
    }

    public static function source(string $id, bool $isProtected = false): MediaSource
    {
        return new MediaSource(
            id: $id,
            playbackUrl: "https://media.example.test/{$id}.m3u8",
            isLive: false,
            isProtected: $isProtected,
            duration: 120.0,
        );
    }
}
