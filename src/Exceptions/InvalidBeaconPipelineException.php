<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Exceptions;

/**
 * A class listed in beacons.pipeline does not implement Contracts\ProcessesBeacon.
 */
class InvalidBeaconPipelineException extends ScarlettPlayerException
{
    public static function notAStep(string $class): self
    {
        return new self("Beacon pipeline step [{$class}] does not implement Hei\\ScarlettPlayer\\Contracts\\ProcessesBeacon.");
    }
}
