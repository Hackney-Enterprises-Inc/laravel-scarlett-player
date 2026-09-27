<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Clips;

use Hei\ScarlettPlayer\Enums\ClipStatus;
use Hei\ScarlettPlayer\Enums\ClipVisibility;
use Hei\ScarlettPlayer\Models\Clip;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * The only class that produces a clip URL. Assets are private on disk, so a URL is
 * the whole of the access decision:
 *
 * - ready + public: a playback URL (the /play route under signed-redirect, the object
 *   URL under disk-public);
 * - ready + not public: no playback URL; a preview URL for whoever passes the preview
 *   ability, expiring after clips.preview_ttl;
 * - anything else: nothing.
 */
class ClipUrlIssuer
{
    public function __construct(
        private readonly Repository $config,
    ) {}

    /**
     * The URL anyone may play the clip from, or null when it is not ready and public.
     */
    public function playbackUrl(Clip $clip): ?string
    {
        if (! $clip->isPubliclyPlayable()) {
            return null;
        }

        if ($this->delivery() === 'disk-public') {
            return Storage::disk((string) $clip->disk)->url((string) $clip->path);
        }

        return route('scarlett.clips.play', ['uuid' => $clip->uuid]);
    }

    /**
     * Where /play sends the browser: a short-lived URL to the private object, or the
     * public object URL under disk-public. Null means 404.
     */
    public function playRedirect(Clip $clip): ?string
    {
        if (! $clip->isPubliclyPlayable()) {
            return null;
        }

        if ($this->delivery() === 'disk-public') {
            return Storage::disk((string) $clip->disk)->url((string) $clip->path);
        }

        return $this->temporaryObjectUrl($clip, (int) $this->config->get('scarlett-player.clips.play_ttl', 300));
    }

    /**
     * A temporary signed URL to the preview route, for a viewer who may preview a
     * rendered clip that is not public. Null for everyone else.
     */
    public function previewUrl(Clip $clip, ?Authenticatable $viewer): ?string
    {
        if ($viewer === null
            || $clip->status !== ClipStatus::Ready
            || $clip->visibility === ClipVisibility::Public
            || ! $clip->hasAsset()
            || ! Gate::forUser($viewer)->allows('preview', $clip)) {
            return null;
        }

        return URL::temporarySignedRoute(
            'scarlett.clips.preview',
            now()->addSeconds($this->previewTtl()),
            ['uuid' => $clip->uuid, 'viewer' => $viewer->getAuthIdentifier()],
        );
    }

    /**
     * Where the preview route sends a viewer who passed the policy: a short-lived URL
     * to the private object. Null when there is nothing rendered.
     */
    public function previewRedirect(Clip $clip): ?string
    {
        if ($clip->status !== ClipStatus::Ready || ! $clip->hasAsset()) {
            return null;
        }

        return $this->temporaryObjectUrl($clip, $this->previewTtl());
    }

    private function temporaryObjectUrl(Clip $clip, int $ttl): string
    {
        return Storage::disk((string) $clip->disk)->temporaryUrl((string) $clip->path, now()->addSeconds(max(1, $ttl)));
    }

    private function previewTtl(): int
    {
        return (int) $this->config->get('scarlett-player.clips.preview_ttl', 600);
    }

    private function delivery(): string
    {
        return (string) $this->config->get('scarlett-player.clips.public_delivery', 'signed-redirect');
    }
}
