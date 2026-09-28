<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Exceptions;

/**
 * The clip disk refused a write the package relies on. A disk configured with
 * throw => false reports a failed write only as a false return; the package turns that
 * into this exception rather than carrying on as if the write had landed.
 */
class ClipStorageException extends ScarlettPlayerException
{
    public static function visibilityNotSet(string $uuid, string $disk, string $path, string $visibility): self
    {
        return new self(
            "Clip [{$uuid}]: disk [{$disk}] did not make [{$path}] {$visibility} (setVisibility() returned false; ".
            'with throw => false the disk hides the cause, so turn throw on for it or check its logs). '.
            'scarlett:clips:reconcile retries the write under disk-public delivery.'
        );
    }
}
