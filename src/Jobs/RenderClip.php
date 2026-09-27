<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Jobs;

use Hei\ScarlettPlayer\Clips\ClipVerifier;
use Hei\ScarlettPlayer\Clips\Verification;
use Hei\ScarlettPlayer\Data\ClipOptions;
use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Enums\ClipAccuracy;
use Hei\ScarlettPlayer\Enums\ClipStatus;
use Hei\ScarlettPlayer\Enums\ClipVisibility;
use Hei\ScarlettPlayer\Events\ClipProcessing;
use Hei\ScarlettPlayer\Events\ClipReady;
use Hei\ScarlettPlayer\Exceptions\ClipGenerationException;
use Hei\ScarlettPlayer\Exceptions\MediaNotFoundException;
use Hei\ScarlettPlayer\Generators\ClipGeneratorManager;
use Hei\ScarlettPlayer\Models\Clip;
use Hei\ScarlettPlayer\ScarlettPlayer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\File;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Renders, verifies and stores one clip. Carries only the clip id.
 *
 * Idempotent and exclusive. The unique lock (ShouldBeUniqueUntilProcessing) covers the
 * wait in the queue, however long the backlog, so a retry or the reconciler cannot
 * queue a second job; once a worker starts it, the claim guards the render: a clip that
 * is processing can only be claimed again when its render is older than the job
 * timeout plus STALE_MARGIN. Every transition is a conditional update, and a retry that
 * finds the asset already written re-verifies it and completes without rendering again.
 */
class RenderClip implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Seconds past the generator timeout before the worker kills the job. */
    public const TIMEOUT_MARGIN = 30;

    public const REASON_ATTEMPTS_EXHAUSTED = 'attempts_exhausted';

    public const REASON_MEDIA_NOT_FOUND = 'media_not_found';

    public const REASON_LIVE_SOURCE = 'live_source';

    public const REASON_RENDER_ERROR = 'render_error';

    /** Seconds the unique lock may cover a job waiting in the queue. */
    public const UNIQUE_FOR = 3600;

    /** Seconds past the job timeout before a processing clip counts as abandoned. */
    public const STALE_MARGIN = 60;

    /** Generator timeout + 30. The worker's --timeout must be at least this. */
    public int $timeout;

    /** Whether this worker process already logged that clips are disabled. */
    private static bool $disabledLogged = false;

    /** Seconds the unique lock is held while the job waits; released when it starts. */
    public int $uniqueFor = self::UNIQUE_FOR;

    public function __construct(
        public readonly int $clipId,
    ) {
        $this->timeout = static::timeoutFor();
    }

    /**
     * Seconds after processing_started_at when a processing clip counts as abandoned by
     * a dead worker: the job timeout plus STALE_MARGIN.
     */
    public static function staleAfter(): int
    {
        return static::timeoutFor() + self::STALE_MARGIN;
    }

    /**
     * The job timeout for the configured generator: its timeout plus 30 seconds.
     */
    public static function timeoutFor(): int
    {
        return app(ClipGeneratorManager::class)->timeout() + self::TIMEOUT_MARGIN;
    }

    public function uniqueId(): string
    {
        return (string) $this->clipId;
    }

    public function tries(): int
    {
        return max(1, (int) config('scarlett-player.clips.max_attempts', 3));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(ClipGeneratorManager $generators, ClipVerifier $verifier, ScarlettPlayer $player): void
    {
        if (! config('scarlett-player.clips.enabled', true)) {
            // Left pending and unclaimed: once clips are enabled again the reconciler
            // dispatches it.
            if (! self::$disabledLogged) {
                self::$disabledLogged = true;
                Log::info('Scarlett clips are disabled (clips.enabled); render jobs exit and leave their clips pending.');
            }

            return;
        }

        $clip = Clip::query()->find($this->clipId);

        if ($clip === null) {
            return;
        }

        if (! $this->claimable($clip)) {
            // Processing and not yet stale: a render is live, or its worker was killed
            // and the queue redelivered before staleAfter(). Returning would delete this
            // delivery as a success and strand the clip if the render is dead; come back
            // once it would count as abandoned, and claim it then.
            if ($clip->status === ClipStatus::Processing && $clip->processing_started_at !== null) {
                $age = (int) $clip->processing_started_at->diffInSeconds(now(), true);
                $this->release(max(1, static::staleAfter() - $age + 1));
            }

            return;
        }

        if ($clip->attempts >= $this->tries()) {
            $clip->markFailed(self::REASON_ATTEMPTS_EXHAUSTED);

            return;
        }

        if (! $this->claim($clip)) {
            return;
        }

        // Anything that throws after the claim (the verifier, the resolver, a disk) goes
        // through the same hand-back as a generator error, so the queue retry can claim
        // the clip again instead of finding it processing and exiting.
        try {
            $this->process($clip, $generators, $verifier, $player);
        } catch (Throwable $e) {
            $this->renderError($clip->refresh(), $e);
        }
    }

    /**
     * Render, verify and store a claimed clip.
     */
    private function process(Clip $clip, ClipGeneratorManager $generators, ClipVerifier $verifier, ScarlettPlayer $player): void
    {
        ClipProcessing::dispatch($clip);

        try {
            $source = $player->resolve($clip->media_id);
        } catch (MediaNotFoundException) {
            $clip->markFailed(self::REASON_MEDIA_NOT_FOUND);

            return;
        }

        if ($source->isLive) {
            $clip->markFailed(self::REASON_LIVE_SOURCE);

            return;
        }

        $disk = (string) config('scarlett-player.clips.disk');
        $path = static::assetPath($clip);
        $storage = Storage::disk($disk);

        if ($storage->exists($path) && $this->completeFromExisting($clip, $storage, $disk, $path, $source, $verifier)) {
            return;
        }

        $accuracy = ClipOptions::accuracyFor($source, $this->configuredAccuracy());

        $rendered = $this->renderAndVerify($clip, $source, $generators, $verifier, $accuracy);

        // A keyframe copy carries up to a GOP before the in point, so on a long-GOP
        // source it can miss the tolerance. An unprotected source gets one exact
        // re-render in the same attempt; protected sources were exact already.
        if ($rendered !== null && ! $rendered[1]->passed && $accuracy === ClipAccuracy::Keyframe && ! $source->isProtected) {
            @unlink($rendered[0]);

            Log::info('Scarlett clip keyframe render exceeded bounds; re-rendering exact.', [
                'clip' => $clip->uuid,
                'media_id' => $clip->media_id,
                'earliest' => $rendered[1]->earliest,
                'span' => $rendered[1]->span,
                'limit' => $rendered[1]->limit,
            ]);

            $rendered = $this->renderAndVerify($clip, $source, $generators, $verifier, ClipAccuracy::Exact);
        }

        if ($rendered === null) {
            return;
        }

        [$file, $verification] = $rendered;

        try {
            if (! $verification->passed) {
                $clip->markFailed((string) $verification->reason);

                return;
            }

            $size = (int) filesize($file);
            $failure = $this->store($clip, $storage, $disk, $path, $file, $size);

            if ($failure !== null) {
                $this->renderError($clip, $failure);

                return;
            }

            $this->ready($clip, $disk, $path, $size, rendered: true);
        } finally {
            @unlink($file);
        }
    }

    /**
     * Render with one accuracy and verify the result. Null when the generator threw
     * (already handed to renderError()).
     *
     * @return array{0: string, 1: Verification}|null The temp file and its verification.
     */
    private function renderAndVerify(Clip $clip, MediaSource $source, ClipGeneratorManager $generators, ClipVerifier $verifier, ClipAccuracy $accuracy): ?array
    {
        try {
            $file = $generators->driver()->generate($source, $clip->start_seconds, $clip->end_seconds, new ClipOptions(
                accuracy: $accuracy,
                clipUuid: $clip->uuid,
                timeout: $generators->timeout(),
            ));
        } catch (Throwable $e) {
            $this->renderError($clip, $e);

            return null;
        }

        try {
            return [$file, $verifier->verify($file, $clip->start_seconds, $clip->end_seconds, $source->isProtected)];
        } catch (Throwable $e) {
            @unlink($file);

            throw $e;
        }
    }

    /**
     * Laravel gave up on the job (tries exhausted or the worker timed it out).
     */
    public function failed(?Throwable $exception = null): void
    {
        $clip = Clip::query()->find($this->clipId);

        if ($clip !== null) {
            $clip->markFailed(self::REASON_RENDER_ERROR);
        }
    }

    /**
     * The deterministic, private location of a clip's asset: {clips.path}/{uuid}.mp4.
     */
    public static function assetPath(Clip $clip): string
    {
        $directory = trim((string) config('scarlett-player.clips.path', 'clips'), '/');

        return ($directory === '' ? '' : $directory.'/').$clip->uuid.'.mp4';
    }

    /**
     * Whether this delivery may take the clip: pending, or processing with a render
     * older than staleAfter() (its worker died). A second delivery during a live render
     * exits here without touching the clip.
     */
    private function claimable(Clip $clip): bool
    {
        if ($clip->status === ClipStatus::Pending) {
            return true;
        }

        return $clip->status === ClipStatus::Processing
            && ($clip->processing_started_at === null
                || $clip->processing_started_at->lt(now()->subSeconds(static::staleAfter())));
    }

    /**
     * Move the clip to processing, counting the attempt, unless another delivery or a
     * moderator got there first.
     */
    private function claim(Clip $clip): bool
    {
        $stale = now()->subSeconds(static::staleAfter());

        $claimed = Clip::query()
            ->whereKey($clip->getKey())
            ->where('attempts', $clip->attempts)
            ->where(fn ($query) => $query
                ->where('status', ClipStatus::Pending->value)
                ->orWhere(fn ($processing) => $processing
                    ->where('status', ClipStatus::Processing->value)
                    ->where(fn ($started) => $started
                        ->whereNull('processing_started_at')
                        ->orWhere('processing_started_at', '<', $stale))))
            ->update([
                'status' => ClipStatus::Processing->value,
                'processing_started_at' => now(),
                'attempts' => $clip->attempts + 1,
            ]);

        $clip->refresh();

        return $claimed === 1;
    }

    /**
     * Write the verified file to the clip disk, privately, and confirm the object is
     * there at the expected size. A write that returns false or throws, or an object that
     * is missing or short afterwards, is a render error for this attempt: the clip never
     * becomes ready without its object.
     *
     * @return Throwable|null The failure, or null when the object is stored.
     */
    private function store(Clip $clip, Filesystem $storage, string $disk, string $path, string $file, int $size): ?Throwable
    {
        try {
            $written = $storage->putFileAs(dirname($path), new File($file), basename($path), ['visibility' => 'private']);
        } catch (Throwable $e) {
            return ClipGenerationException::storeFailed($clip->uuid, $disk, $path, $e->getMessage());
        }

        if ($written === false) {
            return ClipGenerationException::storeFailed($clip->uuid, $disk, $path, 'the disk refused the write');
        }

        try {
            $stored = $storage->exists($path) ? $storage->size($path) : null;
        } catch (Throwable) {
            $stored = null;
        }

        if ($stored !== $size) {
            try {
                $storage->delete($path);
            } catch (Throwable) {
                // Best effort: the path is deterministic, so the next attempt overwrites it.
            }

            return ClipGenerationException::storeFailed($clip->uuid, $disk, $path, $stored === null
                ? 'the object is missing after the write'
                : "the stored object is {$stored} bytes, expected {$size}");
        }

        return null;
    }

    /**
     * A previous delivery wrote the asset and died before marking the clip ready:
     * re-verify it and finish without rendering. A file that fails is deleted and
     * rendered again.
     */
    private function completeFromExisting(Clip $clip, Filesystem $storage, string $disk, string $path, MediaSource $source, ClipVerifier $verifier): bool
    {
        [$local, $temporary] = $this->localCopy($storage, $disk, $path);

        try {
            $verification = $verifier->verify($local, $clip->start_seconds, $clip->end_seconds, $source->isProtected);
        } finally {
            if ($temporary) {
                @unlink($local);
            }
        }

        if (! $verification->passed) {
            $storage->delete($path);

            return false;
        }

        $storage->setVisibility($path, 'private');
        $this->ready($clip, $disk, $path, $storage->size($path), rendered: false);

        return true;
    }

    /**
     * A local file path for an object on a disk, copying it down when the disk is remote.
     *
     * @return array{0: string, 1: bool} The path, and whether it is a temporary copy.
     */
    private function localCopy(Filesystem $storage, string $disk, string $path): array
    {
        if (config("filesystems.disks.{$disk}.driver") === 'local') {
            return [$storage->path($path), false];
        }

        $local = tempnam(sys_get_temp_dir(), 'scarlett-verify-').'.mp4';
        $stream = $storage->readStream($path);

        if (is_resource($stream)) {
            file_put_contents($local, $stream);
            fclose($stream);
        }

        return [$local, true];
    }

    private function ready(Clip $clip, string $disk, string $path, int $size, bool $rendered): void
    {
        $attributes = [
            'status' => ClipStatus::Ready->value,
            'verified_at' => now(),
            'disk' => $disk,
            'path' => $path,
            'size_bytes' => $size,
            'failure_reason' => null,
        ];

        if ($rendered || $clip->rendered_at === null) {
            $attributes['rendered_at'] = now();
        }

        $updated = Clip::query()
            ->whereKey($clip->getKey())
            ->where('status', ClipStatus::Processing->value)
            ->update($attributes);

        $clip->refresh();

        if ($updated === 0) {
            // Rejected (or failed) while it rendered: the path was never recorded, so no
            // pruning would ever find this object. Remove it now, unless another
            // delivery already completed the clip with this same file.
            if (! ($clip->status === ClipStatus::Ready && $clip->path === $path)) {
                Storage::disk($disk)->delete($path);
            }

            return;
        }

        // Approved before it rendered: under disk-public the object must now be public.
        // Under the moderation lock, and compensated, so a concurrent reject wins.
        if ($clip->visibility === ClipVisibility::Public && config('scarlett-player.clips.public_delivery') === 'disk-public') {
            try {
                $clip->withModerationLock(fn () => $clip->syncAssetVisibility());
            } catch (LockTimeoutException) {
                $clip->syncAssetVisibility();
            }
        }

        ClipReady::dispatch($clip);
    }

    /**
     * The generator threw. Retry through the queue while attempts remain; after the last
     * attempt, fail the clip.
     */
    private function renderError(Clip $clip, Throwable $e): void
    {
        report($e);

        if ($clip->attempts >= $this->tries()) {
            $clip->markFailed(self::REASON_RENDER_ERROR);

            return;
        }

        $backoff = $this->backoff()[max(0, min($clip->attempts - 1, count($this->backoff()) - 1))];

        // Hand the clip back as pending so the released job can claim it again. Stamp
        // dispatched_at at the moment that retry becomes due, so the reconciler (which
        // waits redispatch_after past the stamp) never queues a second job during the
        // backoff.
        Clip::query()
            ->whereKey($clip->getKey())
            ->where('status', ClipStatus::Processing->value)
            ->where('attempts', $clip->attempts)
            ->update([
                'status' => ClipStatus::Pending->value,
                'processing_started_at' => null,
                'dispatched_at' => now()->addSeconds($backoff),
                'failure_reason' => self::REASON_RENDER_ERROR,
            ]);

        $this->release($backoff);
    }

    private function configuredAccuracy(): ClipAccuracy
    {
        return ClipAccuracy::tryFrom((string) config('scarlett-player.clips.accuracy', 'keyframe')) ?? ClipAccuracy::Exact;
    }
}
