<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Commands;

use Hei\ScarlettPlayer\Enums\ClipStatus;
use Hei\ScarlettPlayer\Jobs\RenderClip;
use Hei\ScarlettPlayer\Models\Clip;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The recovery path for a dispatch that failed after the request already returned, and
 * for a worker that died mid-render. Scheduled every minute unless
 * clips.schedule_reconcile is off.
 */
class ReconcileClipsCommand extends Command
{
    /** Seconds back the visibility pass looks for changed clips. */
    public const RESYNC_WINDOW = 86400;

    protected $signature = 'scarlett:clips:reconcile
        {--resync-all : Re-sync the object visibility of every stored clip, not only those changed in the last day}';

    protected $description = 'Dispatch pending clips with no live render job, retry stuck renders and delete rejected clip assets past their retention';

    public function handle(): int
    {
        $deleted = $this->pruneRejected();
        $resynced = $this->resyncVisibility((bool) $this->option('resync-all'));

        if (! config('scarlett-player.clips.enabled', true)) {
            // While clips are off nothing is dispatched and no stuck render is retried;
            // pending and stuck clips wait for re-enabling. Pruning and the visibility
            // pass still run.
            $this->components->info("Clips are disabled (clips.enabled): nothing dispatched or retried, {$deleted} rejected assets deleted, {$resynced} objects re-synced.");

            return self::SUCCESS;
        }

        $dispatched = $this->dispatchPending();
        [$retried, $failed] = $this->recoverStuck();

        $this->components->info(sprintf(
            'Clips reconciled: %d dispatched, %d stuck renders retried, %d failed after max attempts, %d rejected assets deleted, %d objects re-synced.',
            $dispatched, $retried, $failed, $deleted, $resynced,
        ));

        return self::SUCCESS;
    }

    private function dispatchPending(): int
    {
        $cutoff = now()->subSeconds((int) config('scarlett-player.clips.redispatch_after', 120));
        $dispatched = 0;

        Clip::query()
            ->where('status', ClipStatus::Pending->value)
            ->where(fn ($query) => $query
                ->whereNull('dispatched_at')
                ->orWhere(fn ($stale) => $stale->where('dispatched_at', '<', $cutoff)->whereNull('processing_started_at')))
            ->orderBy('id')
            ->each(function (Clip $clip) use (&$dispatched): void {
                try {
                    $dispatched += $clip->ensureDispatched() ? 1 : 0;
                } catch (Throwable $e) {
                    report($e);
                    $this->components->warn("Clip [{$clip->uuid}] could not be dispatched: {$e->getMessage()}");
                }
            });

        return $dispatched;
    }

    /**
     * @return array{0: int, 1: int} Retried, failed.
     */
    private function recoverStuck(): array
    {
        $cutoff = now()->subSeconds(RenderClip::staleAfter());
        $maxAttempts = max(1, (int) config('scarlett-player.clips.max_attempts', 3));
        $retried = 0;
        $failed = 0;

        Clip::query()
            ->where('status', ClipStatus::Processing->value)
            ->where('processing_started_at', '<', $cutoff)
            ->orderBy('id')
            ->each(function (Clip $clip) use ($maxAttempts, &$retried, &$failed): void {
                if ($clip->attempts >= $maxAttempts) {
                    $failed += $clip->markFailed(RenderClip::REASON_ATTEMPTS_EXHAUSTED) ? 1 : 0;

                    return;
                }

                $reset = Clip::query()
                    ->whereKey($clip->getKey())
                    ->where('status', ClipStatus::Processing->value)
                    ->where('processing_started_at', $clip->processing_started_at)
                    ->update([
                        'status' => ClipStatus::Pending->value,
                        'processing_started_at' => null,
                        'dispatched_at' => null,
                    ]);

                if ($reset === 1) {
                    try {
                        $retried += $clip->refresh()->ensureDispatched() ? 1 : 0;
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });

        return [$retried, $failed];
    }

    /**
     * Under disk-public, set each recently changed clip's object to the visibility its
     * row wants (public only when ready and public). Heals a storage write that threw or
     * a worker that died between a moderation's row update and its object write.
     * setVisibility() is idempotent.
     *
     * The scheduled pass looks only at rows changed in the last RESYNC_WINDOW seconds (a
     * day): drift can only arise at moderation or render time, and both touch
     * updated_at, while a full pass would cost one billable storage call per clip every
     * minute. A host that suspects older drift runs the command with --resync-all.
     */
    private function resyncVisibility(bool $all = false): int
    {
        if (config('scarlett-player.clips.public_delivery') !== 'disk-public') {
            return 0;
        }

        $synced = 0;

        Clip::query()
            ->whereNotNull('path')
            ->when(! $all, fn ($query) => $query->where('updated_at', '>=', now()->subSeconds(self::RESYNC_WINDOW)))
            ->orderBy('id')
            ->each(function (Clip $clip) use (&$synced): void {
                try {
                    $clip->withModerationLock(fn () => $clip->syncAssetVisibility());
                    $synced++;
                } catch (Throwable $e) {
                    // Locked by a moderation in flight, or storage failing: next run.
                    report($e);
                }
            });

        return $synced;
    }

    private function pruneRejected(): int
    {
        $days = config('scarlett-player.clips.delete_rejected_after');

        if (! is_numeric($days)) {
            return 0;
        }

        $deleted = 0;

        Clip::query()
            ->whereNotNull('rejected_at')
            ->where('rejected_at', '<', now()->subDays((int) $days))
            ->whereNotNull('path')
            ->orderBy('id')
            ->each(function (Clip $clip) use (&$deleted): void {
                // Unrecord the path first, and only while the clip is still rejected: a
                // clip approved again since the query ran keeps its asset.
                $unrecorded = Clip::query()
                    ->whereKey($clip->getKey())
                    ->whereNotNull('rejected_at')
                    ->where('path', $clip->path)
                    ->update(['path' => null, 'size_bytes' => null]);

                if ($unrecorded === 1) {
                    Storage::disk((string) $clip->disk)->delete((string) $clip->path);
                    $deleted++;
                }
            });

        return $deleted;
    }
}
