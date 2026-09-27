<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Events;

use Hei\ScarlettPlayer\Models\Clip;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A moderator made the clip public.
 */
class ClipApproved
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Clip $clip,
        public readonly ?Authenticatable $by = null,
    ) {}
}
