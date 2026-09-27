<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Models;

use Hei\ScarlettPlayer\Enums\ClipStatus;
use Hei\ScarlettPlayer\Enums\ClipVisibility;
use Hei\ScarlettPlayer\Events\ClipApproved;
use Hei\ScarlettPlayer\Events\ClipFailed;
use Hei\ScarlettPlayer\Events\ClipRejected;
use Hei\ScarlettPlayer\Exceptions\ClipStateException;
use Hei\ScarlettPlayer\Jobs\RenderClip;
use Illuminate\Bus\UniqueLock;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * A viewer-requested clip: a separately rendered, separately stored asset.
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $clippable_type
 * @property string|null $clippable_id
 * @property string $media_id
 * @property string $client_request_id
 * @property int|null $user_id
 * @property string|null $title
 * @property float $start_seconds
 * @property float $end_seconds
 * @property float $duration_seconds
 * @property Carbon|null $captured_at
 * @property ClipStatus $status
 * @property ClipVisibility $visibility
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $processing_started_at
 * @property Carbon|null $rendered_at
 * @property Carbon|null $verified_at
 * @property int $attempts
 * @property string|null $disk
 * @property string|null $path
 * @property int|null $size_bytes
 * @property string|null $failure_reason
 * @property Carbon|null $approved_at
 * @property int|null $approved_by
 * @property Carbon|null $rejected_at
 * @property int|null $rejected_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Clip extends Model
{
    protected $table = 'scarlett_clips';

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => 'pending',
        'visibility' => 'pending_review',
        'attempts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'start_seconds' => 'float',
            'end_seconds' => 'float',
            'duration_seconds' => 'float',
            'captured_at' => 'datetime',
            'status' => ClipStatus::class,
            'visibility' => ClipVisibility::class,
            'dispatched_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'rendered_at' => 'datetime',
            'verified_at' => 'datetime',
            'attempts' => 'integer',
            'size_bytes' => 'integer',
            'approved_at' => 'datetime',
            'approved_by' => 'integer',
            'rejected_at' => 'datetime',
            'rejected_by' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * The host media the clip was cut from, when the resolver supplied a model.
     *
     * @return MorphTo<Model, $this>
     */
    public function clippable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The viewer who requested the clip, through the app's user model.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $model */
        $model = Config::get('auth.providers.users.model');

        return $this->belongsTo($model, 'user_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithStatus(Builder $query, ClipStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    /**
     * Whether the clip may be watched by anyone: rendered and public.
     */
    public function isPubliclyPlayable(): bool
    {
        return $this->status === ClipStatus::Ready
            && $this->visibility === ClipVisibility::Public
            && $this->path !== null;
    }

    /**
     * Whether this clip was requested by the given user. Guests own nothing.
     */
    public function isOwnedBy(?Authenticatable $user): bool
    {
        return $user !== null
            && $this->user_id !== null
            && (string) $this->user_id === (string) $user->getAuthIdentifier();
    }

    /**
     * Dispatch the render job when the clip is pending and has no live dispatch: never
     * dispatched, or dispatched longer than clips.redispatch_after ago without the job
     * ever starting. Safe to call on every retry and from the reconciler; a conditional
     * update decides which caller dispatches.
     *
     * @return bool Whether this call queued a job.
     */
    public function ensureDispatched(): bool
    {
        if ($this->status !== ClipStatus::Pending) {
            return false;
        }

        $cutoff = Carbon::now()->subSeconds((int) Config::get('scarlett-player.clips.redispatch_after', 120));
        $stamp = Carbon::now();
        $previous = $this->dispatched_at;

        $claimed = static::query()
            ->whereKey($this->getKey())
            ->where('status', ClipStatus::Pending->value)
            ->where(fn (Builder $query) => $query
                ->whereNull('dispatched_at')
                ->orWhere(fn (Builder $stale) => $stale
                    ->where('dispatched_at', '<', $cutoff)
                    ->whereNull('processing_started_at')))
            ->update(['dispatched_at' => $stamp]);

        if ($claimed === 0) {
            $this->refresh();

            return false;
        }

        try {
            $queued = static::dispatchRender($this);
        } catch (Throwable $e) {
            $queued = false;
            $failure = $e;
        }

        if (! $queued) {
            // Nothing was queued (the push threw, or a queued job already holds the
            // lock): put the previous stamp back rather than record a dispatch that did
            // not happen.
            static::query()->whereKey($this->getKey())->where('dispatched_at', $stamp)->update(['dispatched_at' => $previous]);
        }

        $this->refresh();

        if (isset($failure)) {
            throw $failure;
        }

        return $queued;
    }

    /**
     * Queue the render job on the clips connection and queue.
     *
     * Takes the job's unique lock itself so a failed push releases it: Laravel's pending
     * dispatch keeps the lock when the push throws, which would silently swallow the
     * retry that is supposed to recover from exactly that failure. The lock lasts until
     * a worker starts the job (ShouldBeUniqueUntilProcessing), so it covers queue wait
     * however long the backlog; RenderClip's claim guards the processing phase.
     *
     * @return bool False when a render job for this clip is already queued.
     */
    public static function dispatchRender(self $clip): bool
    {
        $connection = Config::get('scarlett-player.clips.connection');
        $queue = Config::get('scarlett-player.clips.queue', 'scarlett-clips');

        $job = (new RenderClip((int) $clip->getKey()))
            ->onConnection(is_string($connection) && $connection !== '' ? $connection : null)
            ->onQueue(is_string($queue) ? $queue : 'scarlett-clips');

        $container = Container::getInstance();
        $lock = new UniqueLock($container->make(CacheRepository::class));

        if (! $lock->acquire($job)) {
            return false;
        }

        try {
            $container->make(Dispatcher::class)->dispatch($job);
        } catch (Throwable $e) {
            $lock->release($job);

            throw $e;
        }

        return true;
    }

    /**
     * Make the clip public. A clip that has not rendered yet is pre-approved and plays
     * once ready. Under disk-public delivery a stored asset's object is made public too.
     *
     * The transition is a conditional update on the database row, not a save of this
     * possibly stale model: a clip rejected or failed meanwhile is not resurrected. The
     * row update and the object visibility write run under the clip's moderation lock,
     * so a concurrent reject() cannot land between them.
     *
     * @throws ClipStateException when the clip failed, was rejected before rendering, or
     *                            is ready with no asset (deleted by the rejected-asset pruning).
     * @throws LockTimeoutException when another moderation of this clip holds the lock.
     */
    public function approve(?Authenticatable $by = null): static
    {
        $this->withModerationLock(function () use ($by): void {
            $updated = static::query()
                ->whereKey($this->getKey())
                ->whereNotIn('status', [ClipStatus::Failed->value, ClipStatus::Rejected->value])
                ->where(fn (Builder $query) => $query
                    ->where('status', '!=', ClipStatus::Ready->value)
                    ->orWhereNotNull('path'))
                ->update([
                    'visibility' => ClipVisibility::Public->value,
                    'approved_at' => Carbon::now(),
                    'approved_by' => $this->userId($by),
                    'rejected_at' => null,
                    'rejected_by' => null,
                ]);

            $this->refresh();

            if ($updated === 0) {
                throw ClipStateException::cannotApprove($this);
            }

            $this->syncAssetVisibility();
        });

        Event::dispatch(new ClipApproved($this, $by));

        return $this;
    }

    /**
     * Hide the clip. A clip still waiting to render is marked rejected so no job
     * renders it; a rendered clip keeps its status, its asset is made private again and
     * later deleted after clips.delete_rejected_after days by scarlett:clips:reconcile.
     *
     * Keyed on the row's current status, not this possibly stale model's, so a reject
     * that lands after a render finished never overwrites ready; runs under the clip's
     * moderation lock like approve().
     *
     * @throws LockTimeoutException when another moderation of this clip holds the lock.
     */
    public function reject(?Authenticatable $by = null): static
    {
        $this->withModerationLock(function () use ($by): void {
            // Object first, row second: hiding fails safe. If the storage write throws,
            // the row is untouched and the moderator sees the error; there is never a
            // hidden row in front of a public object.
            $this->refresh();

            if ($this->hasAsset()) {
                Storage::disk((string) $this->disk)->setVisibility((string) $this->path, 'private');
            }

            $attributes = [
                'visibility' => ClipVisibility::Hidden->value,
                'rejected_at' => Carbon::now(),
                'rejected_by' => $this->userId($by),
            ];
            $renderable = array_map(fn (ClipStatus $s): string => $s->value, ClipStatus::renderable());

            $updated = static::query()
                ->whereKey($this->getKey())
                ->whereIn('status', $renderable)
                ->update($attributes + ['status' => ClipStatus::Rejected->value]);

            if ($updated === 0) {
                static::query()
                    ->whereKey($this->getKey())
                    ->whereNotIn('status', $renderable)
                    ->update($attributes);
            }

            $this->refresh();
        });

        Event::dispatch(new ClipRejected($this, $by));

        return $this;
    }

    /**
     * Run a moderation or visibility step under this clip's atomic lock
     * (scarlett:clip:{uuid}), waiting up to clips.lock_wait seconds. A cache store
     * without locks runs the step unlocked; syncAssetVisibility()'s compensation still
     * converges the object on the row.
     *
     * @template T
     *
     * @param  callable(): T  $step
     * @return T
     *
     * @throws LockTimeoutException when the lock is not free within clips.lock_wait.
     */
    public function withModerationLock(callable $step): mixed
    {
        $store = Cache::store()->getStore();

        if (! $store instanceof LockProvider) {
            return $step();
        }

        $wait = max(0, (int) Config::get('scarlett-player.clips.lock_wait', 5));

        return $store->lock('scarlett:clip:'.$this->uuid, max(10, $wait * 2))->block($wait, $step);
    }

    /**
     * Make the stored object's visibility match the row: public only under disk-public
     * delivery for a clip that is ready and public, private in every other case.
     *
     * After writing public it reads the row again and, if the clip is no longer ready
     * and public (a reject that won a race, or ran without the lock), writes private
     * again. That covers the public path only: a storage write that throws, or a worker
     * that dies between the row and the object, leaves them apart until
     * scarlett:clips:reconcile's visibility pass re-syncs every stored clip. reject()
     * avoids the dangerous half by writing the object private before the row.
     */
    public function syncAssetVisibility(): void
    {
        $this->refresh();

        if (! $this->hasAsset()) {
            return;
        }

        $disk = Storage::disk((string) $this->disk);
        $public = $this->wantsPublicObject();

        $disk->setVisibility((string) $this->path, $public ? 'public' : 'private');

        if (! $public) {
            return;
        }

        $this->refresh();

        if (! $this->wantsPublicObject() && $this->hasAsset()) {
            $disk->setVisibility((string) $this->path, 'private');
        }
    }

    /**
     * Whether this row calls for a public object: disk-public, ready, public, stored.
     */
    private function wantsPublicObject(): bool
    {
        return Config::get('scarlett-player.clips.public_delivery') === 'disk-public'
            && $this->isPubliclyPlayable();
    }

    /**
     * Mark the clip failed for good, if it is still pending or processing, and fire
     * ClipFailed with the reason.
     *
     * @return bool Whether this call made the transition.
     */
    public function markFailed(string $reason): bool
    {
        $updated = static::query()
            ->whereKey($this->getKey())
            ->whereIn('status', array_map(fn (ClipStatus $s): string => $s->value, ClipStatus::renderable()))
            ->update(['status' => ClipStatus::Failed->value, 'failure_reason' => $reason]);

        $this->refresh();

        if ($updated === 1) {
            Event::dispatch(new ClipFailed($this, $reason));
        }

        return $updated === 1;
    }

    /**
     * Whether a rendered asset is recorded for the clip.
     */
    public function hasAsset(): bool
    {
        return $this->disk !== null && $this->path !== null;
    }

    private function userId(?Authenticatable $user): ?int
    {
        $id = $user?->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : null;
    }
}
