<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Exceptions;

use Hei\ScarlettPlayer\Models\Clip;

/**
 * A clip was asked to make a transition its current state does not allow.
 */
class ClipStateException extends ScarlettPlayerException
{
    public static function cannotApprove(Clip $clip): self
    {
        return new self("Clip [{$clip->uuid}] is {$clip->status->value} and cannot be approved.");
    }
}
