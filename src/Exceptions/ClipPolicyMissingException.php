<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Exceptions;

/**
 * Clips were asked for on protected media and no policy is registered for the Clip
 * model. Protection fails closed: without a host policy, nobody clips paid content.
 */
class ClipPolicyMissingException extends ScarlettPlayerException
{
    public static function forProtectedMedia(string $mediaId, string $clipClass): self
    {
        return new self(
            "Cannot enable clips on protected Scarlett media [{$mediaId}]: no policy is registered for [{$clipClass}]. ".
            'Register one with Gate::policy() to decide who may clip protected media.'
        );
    }
}
