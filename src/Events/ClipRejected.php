<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Events;

use Hei\ScarlettPlayer\Models\Clip;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A moderator hid the clip.
 */
class ClipRejected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Clip $clip,
        public readonly ?Authenticatable $by = null,
    ) {}
}
