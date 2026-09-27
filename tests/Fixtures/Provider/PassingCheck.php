<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Tests\Fixtures\Provider;

use Hei\ScarlettPlayer\Doctor\Check;
use Hei\ScarlettPlayer\Doctor\CheckResult;

class PassingCheck implements Check
{
    public function name(): string
    {
        return 'probe pass';
    }

    public function run(): CheckResult
    {
        return CheckResult::pass('probe passed');
    }
}
