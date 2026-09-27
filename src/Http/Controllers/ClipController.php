<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Http\Controllers;

use Hei\ScarlettPlayer\Clips\ClipUrlIssuer;
use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Events\ClipRequested;
use Hei\ScarlettPlayer\Exceptions\MediaNotFoundException;
use Hei\ScarlettPlayer\Http\ClipRouteMiddleware;
use Hei\ScarlettPlayer\Http\Requests\StoreClipRequest;
use Hei\ScarlettPlayer\Models\Clip;
use Hei\ScarlettPlayer\ScarlettPlayer;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Create answers 202 inside the plugin's 15 s budget: validate, persist pending,
 * dispatch after commit, return. Rendering always happens on the queue.
 */
class ClipController
{
    public const NOT_FOUND_MESSAGE = 'This video could not be found.';

    public const PAST_END_MESSAGE = 'Your clip runs past the end of the video.';

    public const REUSED_REQUEST_MESSAGE = 'Something went wrong creating your clip. Please try again.';

    public const FORBIDDEN_MESSAGE = "You can't make clips from this video.";

    public const DISABLED_MESSAGE = "Clips aren't available right now.";

    public function __construct(
        private readonly ClipUrlIssuer $urls,
    ) {}

    /**
     * POST {prefix}/clips. A repeat of a clientRequestId returns the first clip and makes
     * sure its render job exists; it never renders twice.
     */
    public function store(StoreClipRequest $request, ScarlettPlayer $player): JsonResponse
    {
        // clips.enabled stops new clips; routes.clips (a separate switch) may still
        // register the routes so existing clips keep their status, play and preview.
        abort_unless((bool) config('scarlett-player.clips.enabled', true), 404, self::DISABLED_MESSAGE);

        $user = $request->user();
        $clientRequestId = (string) $request->input('clientRequestId');

        $existing = Clip::query()->where('client_request_id', $clientRequestId)->first();

        if ($existing !== null) {
            return $this->retried($existing, $user);
        }

        $media = $this->resolve($player, (string) $request->input('mediaId'));

        if ($media->isLive) {
            throw ValidationException::withMessages(['mediaId' => StoreClipRequest::LIVE_MESSAGE]);
        }

        // The overlay shows this message to the viewer: never the framework's default.
        abort_unless(Gate::forUser($user)->allows('create', [Clip::class, $media]), 403, self::FORBIDDEN_MESSAGE);

        if ($media->duration !== null
            && $request->endTime() > $media->duration + (float) config('scarlett-player.clips.duration_tolerance', 1.0)) {
            throw ValidationException::withMessages(['endTime' => self::PAST_END_MESSAGE]);
        }

        try {
            $clip = DB::transaction(function () use ($request, $media, $user, $clientRequestId): Clip {
                $clip = new Clip([
                    'uuid' => (string) Str::uuid(),
                    'media_id' => $media->id,
                    'client_request_id' => $clientRequestId,
                    'user_id' => $user?->getAuthIdentifier(),
                    'title' => $request->input('title'),
                    'start_seconds' => $request->startTime(),
                    'end_seconds' => $request->endTime(),
                    'duration_seconds' => $request->clipDuration(),
                    'captured_at' => $this->capturedAt($request->input('capturedAt')),
                    'visibility' => (string) config('scarlett-player.clips.visibility', 'pending_review'),
                ]);

                if ($media->model !== null) {
                    $clip->clippable()->associate($media->model);
                }

                $clip->save();

                DB::afterCommit(fn () => $this->dispatchFirst($clip));

                return $clip;
            });
        } catch (UniqueConstraintViolationException) {
            // Another request with this clientRequestId won the insert: continue as a retry.
            return $this->retried(Clip::query()->where('client_request_id', $clientRequestId)->firstOrFail(), $user);
        }

        ClipRequested::dispatch($clip);

        return $this->accepted($clip->refresh(), $user);
    }

    /**
     * GET {prefix}/clips/{uuid}: status, with a playback or preview URL per ClipUrlIssuer.
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $clip = $this->find($uuid);
        $user = $this->viewer($request);

        if ($user === null && ! config('scarlett-player.clips.allow_guests', false)) {
            throw new AuthenticationException;
        }

        Gate::forUser($user)->authorize('view', $clip);

        return response()->json($this->present($clip, $user));
    }

    /**
     * GET {prefix}/clips/{uuid}/play: redirect to the asset once ready and public, else 404.
     */
    public function play(string $uuid): RedirectResponse
    {
        $target = $this->urls->playRedirect($this->find($uuid));

        abort_if($target === null, 404);

        return redirect()->away($target);
    }

    /**
     * GET {prefix}/clips/{uuid}/preview: temporary signed; re-checks ClipPolicy::preview
     * for the viewer the URL was issued to, then redirects to a short-lived object URL.
     */
    public function preview(Request $request, string $uuid): RedirectResponse
    {
        $clip = $this->find($uuid);
        $viewer = $this->issuedTo($request);

        abort_unless($viewer !== null && Gate::forUser($viewer)->allows('preview', $clip), 403);

        $target = $this->urls->previewRedirect($clip);

        abort_if($target === null, 404);

        return redirect()->away($target);
    }

    private function resolve(ScarlettPlayer $player, string $mediaId): MediaSource
    {
        try {
            return $player->resolve($mediaId);
        } catch (MediaNotFoundException) {
            abort(404, self::NOT_FOUND_MESSAGE);
        }
    }

    private function retried(Clip $clip, ?Authenticatable $user): JsonResponse
    {
        if ((string) ($clip->user_id ?? '') !== (string) ($user?->getAuthIdentifier() ?? '')) {
            throw ValidationException::withMessages(['clientRequestId' => self::REUSED_REQUEST_MESSAGE]);
        }

        try {
            $clip->ensureDispatched();
        } catch (Throwable $e) {
            // The row is safe; scarlett:clips:reconcile dispatches it later.
            report($e);
        }

        return $this->accepted($clip, $user);
    }

    /**
     * Dispatch a new clip's render job and stamp dispatched_at. A failed dispatch is
     * reported and left for a retry or the reconciler: the viewer still gets a 202.
     */
    private function dispatchFirst(Clip $clip): void
    {
        try {
            Clip::dispatchRender($clip);
        } catch (Throwable $e) {
            report($e);

            return;
        }

        Clip::query()->whereKey($clip->getKey())->whereNull('dispatched_at')->update(['dispatched_at' => now()]);
    }

    private function accepted(Clip $clip, ?Authenticatable $user): JsonResponse
    {
        return response()->json($this->present($clip, $user), 202);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Clip $clip, ?Authenticatable $user): array
    {
        return [
            'uuid' => $clip->uuid,
            'status' => $clip->status->value,
            'visibility' => $clip->visibility->value,
            'mediaId' => $clip->media_id,
            'title' => $clip->title,
            'startTime' => $clip->start_seconds,
            'endTime' => $clip->end_seconds,
            'duration' => $clip->duration_seconds,
            'failureReason' => $clip->failure_reason,
            'statusUrl' => route('scarlett.clips.show', ['uuid' => $clip->uuid]),
            'playbackUrl' => $this->urls->playbackUrl($clip),
            'previewUrl' => $this->urls->previewUrl($clip, $user),
        ];
    }

    private function find(string $uuid): Clip
    {
        return Clip::query()->where('uuid', $uuid)->firstOr(fn () => abort(404));
    }

    /**
     * The signed-in viewer on a route that ran without the auth middleware: the first
     * guard named by routes.middleware.clips that has a user.
     */
    private function viewer(Request $request): ?Authenticatable
    {
        foreach (ClipRouteMiddleware::guards() as $guard) {
            $user = Auth::guard($guard)->user();

            if ($user !== null) {
                return $user;
            }
        }

        return $request->user();
    }

    /**
     * The viewer a preview URL was issued to, loaded through the app's user provider.
     */
    private function issuedTo(Request $request): ?Authenticatable
    {
        $id = $request->query('viewer');

        if (! is_string($id) || $id === '') {
            return null;
        }

        $guard = (string) config('auth.defaults.guard', 'web');
        $provider = Auth::createUserProvider(config("auth.guards.{$guard}.provider"));

        return $provider?->retrieveById($id);
    }

    private function capturedAt(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
