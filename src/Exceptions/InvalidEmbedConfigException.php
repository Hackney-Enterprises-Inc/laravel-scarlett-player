<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Exceptions;

/**
 * An embed.* config value the package cannot use safely.
 */
class InvalidEmbedConfigException extends ScarlettPlayerException
{
    public static function notAHost(string $entry): self
    {
        return new self("scarlett-player.embed.allowed_domains entry [{$entry}] is not a host name; list bare host names such as 'example.com'.");
    }
}
