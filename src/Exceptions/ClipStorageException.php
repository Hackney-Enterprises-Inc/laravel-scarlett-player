<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Exceptions;

/**
 * The clip disk refused a write the package relies on. A disk configured with
 * throw => false reports a failed write only as a false return; the package turns that
 * into this exception rather than carrying on as if the write had landed.
 *
 * $visibility is the visibility the refused write asked for: a refused 'private' can
 * leave a public object behind (the unsafe side), a refused 'public' leaves it private.
 */
class ClipStorageException extends ScarlettPlayerException
{
    public function __construct(
        string $message,
        public readonly string $clipUuid,
        public readonly string $disk,
        public readonly string $path,
        public readonly string $visibility,
    ) {
        parent::__construct($message);
    }

    public static function visibilityNotSet(string $uuid, string $disk, string $path, string $visibility): self
    {
        return new self(
            "Clip [{$uuid}]: disk [{$disk}] did not make [{$path}] {$visibility} (setVisibility() returned false; ".
            'with throw => false the disk hides the cause, so turn throw on for it or check its logs). '.
            'scarlett:clips:reconcile retries the write under disk-public delivery.',
            $uuid,
            $disk,
            $path,
            $visibility,
        );
    }

    /**
     * Whether the refused write was the one that hides the object: the object may still
     * be public while the row says it should not be.
     */
    public function leftObjectExposed(): bool
    {
        return $this->visibility === 'private';
    }
}
