<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Http;

/**
 * Reads routes.middleware.clips so the read-only clip routes can drop what only the
 * create route needs.
 *
 * The configured list guards creating a clip. Status, play and preview run without its
 * `auth` and `scarlett-clips` throttle entries: status admits guests when
 * clips.allow_guests is on and authorizes through ClipPolicy::view, play is public once
 * a clip is ready and public, and preview is guarded by its signature and
 * ClipPolicy::preview.
 */
final class ClipRouteMiddleware
{
    /**
     * The configured clips middleware.
     *
     * @return list<string>
     */
    public static function configured(): array
    {
        $middleware = config('scarlett-player.routes.middleware.clips', []);

        return array_values(array_filter((array) $middleware, 'is_string'));
    }

    /**
     * Entries the read-only routes exclude: every `auth` / `auth:<guards>` entry and the
     * scarlett-clips throttle.
     *
     * @return list<string>
     */
    public static function createOnly(): array
    {
        return array_values(array_filter(self::configured(), fn (string $entry): bool => self::isAuth($entry)
            || str_starts_with($entry, 'throttle:scarlett-clips')));
    }

    /**
     * Guards named by the configured auth entries, in order; null is the default guard.
     *
     * @return list<string|null>
     */
    public static function guards(): array
    {
        $guards = [];

        foreach (self::configured() as $entry) {
            if (! self::isAuth($entry)) {
                continue;
            }

            $named = str_contains($entry, ':') ? explode(',', substr($entry, strpos($entry, ':') + 1)) : [];
            $named = array_values(array_filter($named, fn (string $guard): bool => $guard !== ''));

            array_push($guards, ...($named === [] ? [null] : $named));
        }

        return $guards === [] ? [null] : array_values(array_unique($guards, SORT_REGULAR));
    }

    private static function isAuth(string $entry): bool
    {
        return $entry === 'auth' || str_starts_with($entry, 'auth:');
    }
}
