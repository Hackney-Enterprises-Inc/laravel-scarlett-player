<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Events;

use Hei\ScarlettPlayer\Models\Clip;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The clip failed terminally. reason is a short code such as render_exceeds_bounds.
 */
class ClipFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Clip $clip,
        public readonly string $reason,
    ) {}
}
