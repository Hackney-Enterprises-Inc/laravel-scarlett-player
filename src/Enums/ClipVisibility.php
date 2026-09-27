<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Enums;

/**
 * Who may watch a rendered clip. Only public clips get a playback URL.
 */
enum ClipVisibility: string
{
    case PendingReview = 'pending_review';
    case Public = 'public';
    case Hidden = 'hidden';
}
