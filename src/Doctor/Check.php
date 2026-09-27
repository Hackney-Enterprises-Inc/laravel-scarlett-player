<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Doctor;

/**
 * One scarlett:doctor check. Implementations are resolved from the container, so
 * they may type-hint what they inspect in the constructor.
 */
interface Check
{
    /**
     * The short label printed in the doctor table.
     */
    public function name(): string;

    public function run(): CheckResult;
}
