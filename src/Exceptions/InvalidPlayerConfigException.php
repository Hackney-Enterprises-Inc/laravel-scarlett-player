<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Exceptions;

/**
 * The player configuration asked for something the package cannot build.
 */
class InvalidPlayerConfigException extends ScarlettPlayerException
{
    public static function unknownMode(string $mode): self
    {
        return new self("Unknown Scarlett player mode [{$mode}]: use 'module' or 'embed'.");
    }

    public static function unknownFeature(string $feature): self
    {
        return new self("Unknown Scarlett player feature [{$feature}].");
    }

    public static function routeMissing(string $feature, string $route, string $switch): self
    {
        return new self("Cannot configure {$feature}: the [{$route}] route is not registered. Turn on scarlett-player.{$switch}.");
    }

    public static function heartbeatInterval(mixed $value): self
    {
        $shown = is_scalar($value) ? var_export($value, true) : get_debug_type($value);

        return new self("Invalid heartbeat interval [{$shown}]: scarlett-player.player.heartbeat_interval and heartbeatInterval() take a number of seconds above zero, or null for the player default.");
    }

    public static function invalidEntry(string $what, int|string $index, string $reason): self
    {
        return new self("Invalid {$what} entry [{$index}]: {$reason}.");
    }
}
