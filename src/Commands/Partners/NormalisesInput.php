<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Commands\Partners;

/**
 * One place that turns a console argument or option into a string. It takes `mixed`, so
 * the check is never redundant whatever type the framework reports for the value, which
 * differs between Laravel and larastan versions.
 */
trait NormalisesInput
{
    /**
     * The value when it is a string, else an empty string.
     */
    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * The value when it is an array, as a list, else an empty list.
     *
     * @return list<mixed>
     */
    private function items(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
