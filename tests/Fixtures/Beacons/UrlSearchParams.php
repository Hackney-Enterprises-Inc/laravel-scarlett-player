<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Tests\Fixtures\Beacons;

/**
 * What the browser's `url.searchParams.set()` does to a URL: the existing query is parsed
 * as application/x-www-form-urlencoded and the whole of it, the new pair appended, is
 * serialised again. This is how the player attaches api_key to a beacon URL for
 * sendBeacon, so a proof signed over the original bytes must survive it.
 *
 * Serialisation: space as `+`, every byte except ASCII alphanumerics and `*-._`
 * percent-encoded with uppercase hex.
 */
final class UrlSearchParams
{
    public static function append(string $url, string $name, string $value): string
    {
        [$base, $query] = array_pad(explode('?', $url, 2), 2, '');

        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$key, $val] = array_pad(explode('=', $pair, 2), 2, '');
            $pairs[] = [urldecode($key), urldecode($val)];
        }

        $pairs[] = [$name, $value];

        return $base.'?'.implode('&', array_map(
            fn (array $pair): string => self::encode($pair[0]).'='.self::encode($pair[1]),
            $pairs,
        ));
    }

    private static function encode(string $text): string
    {
        return (string) preg_replace_callback(
            '/[^A-Za-z0-9*._-]/',
            fn (array $match): string => $match[0] === ' ' ? '+' : sprintf('%%%02X', ord($match[0])),
            $text,
        );
    }
}
