<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Commands;

use Hei\ScarlettPlayer\Doctor\CheckRegistry;
use Hei\ScarlettPlayer\Doctor\CheckResult;
use Hei\ScarlettPlayer\Doctor\CheckStatus;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runs every registered doctor check and prints one row each. Fails when any check
 * fails; warnings alone still succeed.
 */
class DoctorCommand extends Command
{
    protected $signature = 'scarlett:doctor';

    protected $description = 'Check the Scarlett Player integration: media mapping, beacon key, queues, stores';

    public function handle(CheckRegistry $registry): int
    {
        $rows = [];
        $failed = false;

        foreach ($registry->checks() as $check) {
            try {
                $result = $check->run();
            } catch (Throwable $e) {
                $result = CheckResult::fail($e::class.': '.$e->getMessage());
            }

            $failed = $failed || $result->status === CheckStatus::Fail;
            $rows[] = [$check->name(), strtoupper($result->status->value), $result->message];
        }

        $this->table(['Check', 'Status', 'Detail'], $rows);

        if ($failed) {
            $this->error('One or more checks failed.');

            return self::FAILURE;
        }

        $this->info('No check failed.');

        return self::SUCCESS;
    }
}
