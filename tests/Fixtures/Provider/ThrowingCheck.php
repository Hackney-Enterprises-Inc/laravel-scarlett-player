<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Tests\Fixtures\Provider;

use Hei\ScarlettPlayer\Doctor\Check;
use Hei\ScarlettPlayer\Doctor\CheckResult;
use RuntimeException;

class ThrowingCheck implements Check
{
    public function name(): string
    {
        return 'probe throw';
    }

    public function run(): CheckResult
    {
        throw new RuntimeException('probe exploded');
    }
}
