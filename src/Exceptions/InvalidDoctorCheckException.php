<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Exceptions;

/**
 * A class registered as a scarlett:doctor check does not implement Doctor\Check.
 */
class InvalidDoctorCheckException extends ScarlettPlayerException
{
    public static function notACheck(string $class): self
    {
        return new self("Doctor check [{$class}] does not implement Hei\\ScarlettPlayer\\Doctor\\Check.");
    }
}
