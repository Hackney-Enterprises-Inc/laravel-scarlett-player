<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Exceptions;

/**
 * beacons.store names neither a shipped store (eloquent, null) nor a class
 * implementing Contracts\BeaconStore.
 */
class InvalidBeaconStoreException extends ScarlettPlayerException
{
    public static function unknown(string $store): self
    {
        return new self("Beacon store [{$store}] is not eloquent, null, or a class implementing Hei\\ScarlettPlayer\\Contracts\\BeaconStore.");
    }

    public static function unsupportedConnection(string $class): self
    {
        return new self("EloquentBeaconStore needs an Illuminate\\Database\\Connection, the default connection resolved to [{$class}].");
    }
}
