<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Exceptions;

/**
 * The resolver returned null for a media id. HTTP modules render it as a 404.
 */
class MediaNotFoundException extends ScarlettPlayerException
{
    public function __construct(
        public readonly string $mediaId,
    ) {
        parent::__construct("Scarlett media [{$mediaId}] was not found.");
    }
}
