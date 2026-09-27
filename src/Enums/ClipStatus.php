<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Enums;

/**
 * Where a clip is in its render lifecycle.
 */
enum ClipStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Rejected = 'rejected';

    /**
     * Statuses a render job may still act on.
     *
     * @return list<self>
     */
    public static function renderable(): array
    {
        return [self::Pending, self::Processing];
    }

    /**
     * Whether no render job should touch the clip again.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Ready, self::Failed, self::Rejected], true);
    }
}
