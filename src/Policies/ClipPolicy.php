<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Policies;

use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Enums\ClipVisibility;
use Hei\ScarlettPlayer\Models\Clip;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use ReflectionMethod;

/**
 * The package default, registered only when the host has not registered its own policy
 * for Clip. It fails closed: nobody may clip protected media, and nobody moderates.
 *
 * Turning paid media into public files must be an explicit host decision, and writing a
 * create() ability is that decision: extend this class, override create() (and
 * moderate()), and register it with Gate::policy(Clip::class, ...). A registered policy
 * that inherits this create() still denies protected media.
 */
class ClipPolicy
{
    /**
     * Whether the host has published its own clip policy: the gate resolves a policy for
     * Clip that is not this exact class and that declares its own create(). A subclass
     * inheriting create() does not count. The one place the fail-closed rule for
     * protected media is decided; the player config builder asks the same question.
     */
    public static function hostPolicyPublished(Gate $gate): bool
    {
        $policy = $gate->getPolicyFor(Clip::class);

        if ($policy === null || $policy::class === self::class || ! method_exists($policy, 'create')) {
            return false;
        }

        return (new ReflectionMethod($policy, 'create'))->getDeclaringClass()->getName() !== self::class;
    }

    /**
     * Request a clip of this media. Signed-in viewers, unprotected media only. A host
     * that clips protected media writes its own create().
     */
    public function create(?Authenticatable $user, MediaSource $media): bool
    {
        return $user !== null && ! $media->isProtected;
    }

    /**
     * Read a clip's status. Guests only when clips.allow_guests is on, and then only for
     * clips that are ready and public; a pending or hidden clip's title and range stay
     * with its submitter and the moderators.
     */
    public function view(?Authenticatable $user, Clip $clip): bool
    {
        if ($user === null) {
            return (bool) config('scarlett-player.clips.allow_guests', false) && $clip->isPubliclyPlayable();
        }

        return $clip->isOwnedBy($user)
            || $clip->isPubliclyPlayable()
            || $this->moderate($user);
    }

    /**
     * Watch a clip that is not public: the submitter while it waits for review, or a
     * moderator at any time.
     */
    public function preview(?Authenticatable $user, Clip $clip): bool
    {
        if ($user === null) {
            return false;
        }

        if ($this->moderate($user)) {
            return true;
        }

        return $clip->isOwnedBy($user) && $clip->visibility === ClipVisibility::PendingReview;
    }

    /**
     * Approve, reject and preview any clip. Nobody, until the host says who.
     */
    public function moderate(?Authenticatable $user, ?Clip $clip = null): bool
    {
        return false;
    }
}
