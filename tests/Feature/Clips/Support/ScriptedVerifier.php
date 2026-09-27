<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Tests\Feature\Clips\Support;

use Hei\ScarlettPlayer\Clips\ClipVerifier;
use Hei\ScarlettPlayer\Clips\Verification;

/**
 * A verifier whose verdicts are scripted in order; the last one repeats.
 */
class ScriptedVerifier extends ClipVerifier
{
    /** @var list<bool> */
    public array $verdicts = [true];

    /** @var list<string> */
    public array $verified = [];

    public function verify(string $path, float $start, float $end, bool $isProtected): Verification
    {
        $this->verified[] = $path;
        $passed = count($this->verdicts) > 1 ? array_shift($this->verdicts) : $this->verdicts[0];

        return $passed
            ? new Verification(true, null, 0.0, $end - $start, $end - $start + 1)
            : new Verification(false, Verification::REASON_EXCEEDS_BOUNDS, -9.0, 68.96, $end - $start + 1);
    }
}
