<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Events;

use Hei\ScarlettPlayer\Data\BeaconPayload;

/**
 * Fired once, when a view first gains an end (its first viewEnd). A second viewEnd for
 * the same view, which the player can legitimately send, merges and fires nothing.
 */
final class ViewEnded
{
    public function __construct(
        public readonly string $viewId,
        public readonly BeaconPayload $payload,
    ) {}
}
