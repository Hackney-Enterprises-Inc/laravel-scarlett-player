<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Events;

use Hei\ScarlettPlayer\Data\BeaconPayload;

/**
 * Fired once per error beacon actually stored, warnings and reconnecting errors
 * included. A duplicate delivery of the same error beacon fires nothing.
 *
 * Decide whether playback failed with isFatal(), never `$payload->get('fatal')`: from
 * player 1.22.0 an error the provider reconnects from carries `fatal: true` with
 * `errorSeverity: 'warning'` and `reconnecting: true`, and its view stays open.
 */
final class PlaybackErrorReported
{
    public function __construct(
        public readonly string $viewId,
        public readonly BeaconPayload $payload,
    ) {}

    /** The error ended its view: severity fatal (or fatal, before severity) and not reconnecting. */
    public function isFatal(): bool
    {
        return $this->payload->isFatalError();
    }

    /** The provider is auto-reconnecting from this error; the view stays open. */
    public function isReconnecting(): bool
    {
        return $this->payload->isReconnectingError();
    }
}
