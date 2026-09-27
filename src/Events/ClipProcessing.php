<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Events;

use Hei\ScarlettPlayer\Models\Clip;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A render job claimed the clip and is rendering it.
 */
class ClipProcessing
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Clip $clip,
    ) {}
}
