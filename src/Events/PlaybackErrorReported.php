<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Events;

use Hei\ScarlettPlayer\Data\BeaconPayload;

/**
 * Fired once per error beacon actually stored. A duplicate delivery of the same error
 * beacon fires nothing.
 */
final class PlaybackErrorReported
{
    public function __construct(
        public readonly string $viewId,
        public readonly BeaconPayload $payload,
    ) {}
}
