<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Exceptions;

/**
 * beacons.context names a class that is not a Contracts\ResolvesBeaconContext, or the
 * server context given to a beacon uses a key it cannot carry.
 */
class InvalidBeaconContextException extends ScarlettPlayerException
{
    public static function notAResolver(string $class): self
    {
        return new self("Beacon context resolver [{$class}] does not implement Hei\\ScarlettPlayer\\Contracts\\ResolvesBeaconContext.");
    }

    public static function reservedKey(string $key): self
    {
        return new self("Beacon server context cannot set [{$key}]: event, timestamp and the four ids identify the beacon and are never server-owned.");
    }

    public static function invalidKey(string $key): self
    {
        return new self("Beacon server context key [{$key}] is not a usable name: keys must be non-empty strings.");
    }
}
