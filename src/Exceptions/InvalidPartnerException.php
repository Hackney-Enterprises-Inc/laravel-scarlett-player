<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Exceptions;

use Hei\ScarlettPlayer\Partners\PartnerParams;

/**
 * A partner, partner origin or partner snippet the package refuses to use.
 */
class InvalidPartnerException extends ScarlettPlayerException
{
    public static function invalidRef(string $ref): self
    {
        return new self('Partner ref ['.self::printable($ref).'] is not valid: use lowercase letters, digits and hyphens, starting with a letter or digit, at most 64 characters.');
    }

    public static function refIsImmutable(string $from, string $to): self
    {
        return new self("Partner ref [{$from}] cannot be renamed to [{$to}]: the ref is the analytics identity. Create a new partner instead.");
    }

    public static function invalidOrigin(mixed $origin): self
    {
        $shown = is_string($origin) ? self::printable($origin) : get_debug_type($origin);

        return new self("Partner origin [{$shown}] is not valid: use https://host, https://host:port or https://*.host (http only for localhost and 127.0.0.1), with no path, query, userinfo or whitespace.");
    }

    public static function unknown(string $ref): self
    {
        return new self("No partner with ref [{$ref}] exists.");
    }

    public static function revoked(string $ref): self
    {
        return new self("Partner [{$ref}] is revoked.");
    }

    public static function expired(string $ref): self
    {
        return new self("Partner [{$ref}] has expired.");
    }

    public static function alwaysSignOff(): self
    {
        return new self('Partner embeds need scarlett-player.embed.always_sign set to true: without it, removing the partner parameters from a snippet leaves an unlocked page.');
    }

    public static function invalidMediaId(string $media): self
    {
        return new self('Media id ['.self::printable($media).'] cannot be used in a partner snippet: only '.PartnerParams::MEDIA_ID.' is signed byte for byte through the unload beacon.');
    }

    public static function protectedMediaNeedsExpiry(): self
    {
        return new self('This media is protected: a partner snippet for it needs an expiry (--expires).');
    }

    public static function expiryTooFar(): self
    {
        return new self('A partner snippet expiry may be at most '.intdiv(PartnerParams::MAX_EXPIRES, 86400).' days away.');
    }

    /** Whatever was supplied, made safe to print (no control characters, bounded). */
    private static function printable(string $value): string
    {
        $value = (string) preg_replace('/[^\x20-\x7e]/', '?', $value);

        return strlen($value) > 80 ? substr($value, 0, 77).'...' : $value;
    }
}
