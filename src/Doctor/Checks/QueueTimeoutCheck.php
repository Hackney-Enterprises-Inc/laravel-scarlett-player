<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Doctor\Checks;

use Hei\ScarlettPlayer\Doctor\Check;
use Hei\ScarlettPlayer\Doctor\CheckResult;
use Hei\ScarlettPlayer\Jobs\RenderClip;

/**
 * A queue worker enforces RenderClip's timeout only with the pcntl extension. Without
 * it a hung render is never killed, the clip stays processing, and recovery waits on
 * scarlett:clips:reconcile.
 */
class QueueTimeoutCheck implements Check
{
    public function name(): string
    {
        return 'Clip worker timeout (pcntl)';
    }

    public function run(): CheckResult
    {
        if (! config('scarlett-player.routes.clips') || ! config('scarlett-player.clips.enabled', true)) {
            return CheckResult::pass('Clips are off.');
        }

        if (! $this->pcntlLoaded()) {
            return CheckResult::warn(sprintf(
                'The pcntl extension is not loaded, so queue workers cannot enforce RenderClip\'s %d s timeout: a hung render is only recovered by scarlett:clips:reconcile. Install pcntl on your clip workers.',
                RenderClip::timeoutFor(),
            ));
        }

        return CheckResult::pass(sprintf('pcntl is loaded; workers can enforce the %d s render timeout.', RenderClip::timeoutFor()));
    }

    protected function pcntlLoaded(): bool
    {
        return extension_loaded('pcntl');
    }
}
