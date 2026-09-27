<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Events;

use Hei\ScarlettPlayer\Models\Clip;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A viewer's clip request was accepted and stored as pending. Not fired on a retry.
 */
class ClipRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Clip $clip,
    ) {}
}
