<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Clips;

/**
 * What ClipVerifier measured on a rendered file, and whether it may become ready.
 */
final readonly class Verification
{
    public const REASON_EXCEEDS_BOUNDS = 'render_exceeds_bounds';

    public const REASON_UNREADABLE = 'render_unreadable';

    /**
     * @param  float|null  $earliest  Earliest video packet pts relative to the container start.
     * @param  float|null  $span  Max video packet pts minus min, seconds.
     * @param  float  $limit  The largest span allowed.
     */
    public function __construct(
        public bool $passed,
        public ?string $reason,
        public ?float $earliest,
        public ?float $span,
        public float $limit,
        public string $detail = '',
    ) {}
}
