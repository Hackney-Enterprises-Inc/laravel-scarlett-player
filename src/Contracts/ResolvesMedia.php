<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Contracts;

use Hei\ScarlettPlayer\Data\MediaSource;

/**
 * Answers "what is this mediaId?" for every module: the playback URL, the rights flags,
 * the mezzanine file clips cut from. Bind your own implementation for signed URLs,
 * entitlement or multi-tenant lookups; the default is ConfigModelResolver.
 */
interface ResolvesMedia
{
    /**
     * Resolve a host media id.
     *
     * Return null for an unknown id; the package turns that into a 404.
     */
    public function resolve(string $mediaId): ?MediaSource;
}
