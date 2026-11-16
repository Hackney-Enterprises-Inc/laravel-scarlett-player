<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Partners;

use Hei\ScarlettPlayer\Exceptions\InvalidPartnerException;
use Hei\ScarlettPlayer\Player\EmbedConfig;

/**
 * A strict partner origin: a CSP source expression a page may be framed by.
 *
 * Accepted: https://partner.example, https://partner.example:8443,
 * https://*.partner.example (subdomains only, not the apex, as in CSP), and http only
 * for localhost and 127.0.0.1. Everything else is refused: a bare *, a scheme alone, a
 * path, query, fragment or userinfo, whitespace, control characters, semicolons,
 * commas, backslashes and non-ASCII. A wildcard host must contain a dot, so
 * https://*.com (a single-label TLD) is refused. There is no public suffix list:
 * https://*.co.uk is accepted, and the operator who writes origins is trusted to
 * choose a sensible one.
 *
 * The canonical form is lowercase. The same check runs when an origin is stored and
 * again, fail closed, when the page header is built from what is stored.
 */
final class PartnerOrigin
{
    private const SHAPE = '#^(https?)://(\*\.)?([^:/]+)(?::([1-9][0-9]{0,4}))?$#D';

    private const DEV_HOSTS = ['localhost', '127.0.0.1'];

    /**
     * The canonical origin, or null when the value is not a valid one.
     */
    public static function canonical(mixed $origin): ?string
    {
        // Printable ASCII only: no whitespace, controls, CR/LF or non-ASCII, in any position.
        if (! is_string($origin) || preg_match('/^[\x21-\x7e]+$/D', $origin) !== 1) {
            return null;
        }

        if (preg_match(self::SHAPE, strtolower($origin), $parts) !== 1) {
            return null;
        }

        [, $scheme, $wildcard, $host] = $parts;
        $port = $parts[4] ?? '';

        if (! EmbedConfig::isHost($host) || ($port !== '' && (int) $port > 65535)) {
            return null;
        }

        if ($scheme === 'http' && ($wildcard !== '' || ! in_array($host, self::DEV_HOSTS, true))) {
            return null;
        }

        if ($wildcard !== '' && ! str_contains($host, '.')) {
            return null;
        }

        return $scheme.'://'.$wildcard.$host.($port === '' ? '' : ':'.$port);
    }

    /** Whether the value is a valid partner origin, that is canonical() would accept it. */
    public static function isValid(mixed $origin): bool
    {
        return self::canonical($origin) !== null;
    }

    /**
     * @throws InvalidPartnerException when the origin is not valid.
     */
    public static function normalise(mixed $origin): string
    {
        return self::canonical($origin) ?? throw InvalidPartnerException::invalidOrigin($origin);
    }

    /**
     * Every entry of a list canonicalised, or an exception naming the first bad one. For
     * writing: a list with a bad entry is refused whole.
     *
     * @param  array<array-key, mixed>  $origins
     * @return list<string>
     *
     * @throws InvalidPartnerException
     */
    public static function normaliseAll(array $origins): array
    {
        return array_values(array_unique(array_map(self::normalise(...), $origins)));
    }

    /**
     * What is left of a stored value after dropping every entry that is not a valid
     * origin. For reading (the frame-ancestors header): a stored value that is not even
     * a list yields nothing, and the caller then emits 'none'.
     *
     * @return list<string>
     */
    public static function validOnly(mixed $stored): array
    {
        if (! is_array($stored)) {
            return [];
        }

        $valid = [];

        foreach ($stored as $entry) {
            $canonical = self::canonical($entry);

            if ($canonical !== null) {
                $valid[] = $canonical;
            }
        }

        return array_values(array_unique($valid));
    }
}
