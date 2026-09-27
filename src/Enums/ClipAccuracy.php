<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Enums;

/**
 * How a clip is cut.
 *
 * Keyframe stream copy can carry up to a GOP of media before the in point, so it is
 * allowed only for unprotected sources; protected sources always render exact.
 */
enum ClipAccuracy: string
{
    /** Decode, seek on the output, re-encode. Frame accurate, slow. */
    case Exact = 'exact';

    /** Seek on the input and stream copy. Fast, boundaries land on keyframes. */
    case Keyframe = 'keyframe';
}
