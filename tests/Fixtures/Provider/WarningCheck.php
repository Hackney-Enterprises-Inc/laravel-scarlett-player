<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Tests\Fixtures\Provider;

use Hei\ScarlettPlayer\Doctor\Check;
use Hei\ScarlettPlayer\Doctor\CheckResult;

class WarningCheck implements Check
{
    public function name(): string
    {
        return 'probe warn';
    }

    public function run(): CheckResult
    {
        return CheckResult::warn('probe warned');
    }
}
