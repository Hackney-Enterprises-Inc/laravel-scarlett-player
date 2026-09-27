<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Events;

use Hei\ScarlettPlayer\Models\Clip;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The clip rendered, passed ClipVerifier and is stored. Carries the clip, not a URL: ask ClipUrlIssuer.
 */
class ClipReady
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Clip $clip,
    ) {}
}
