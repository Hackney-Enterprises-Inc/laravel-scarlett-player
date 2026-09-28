<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Commands;

use Hei\ScarlettPlayer\Enums\ClipStatus;
use Hei\ScarlettPlayer\Jobs\RenderClip;
use Hei\ScarlettPlayer\Models\Clip;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
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
        [$resynced, $unsynced] = $this->resyncVisibility((bool) $this->option('resync-all'));

        if (! config('scarlett-player.clips.enabled', true)) {
            // While clips are off nothing is dispatched and no stuck render is retried;
            // pending and stuck clips wait for re-enabling. Pruning and the visibility
            // pass still run.
            $this->components->info("Clips are disabled (clips.enabled): nothing dispatched or retried, {$deleted} rejected assets deleted, {$resynced} objects re-synced.");

            return $this->visibilityOutcome($unsynced);
        }

        $dispatched = $this->dispatchPending();
        [$retried, $failed] = $this->recoverStuck();

        $this->components->info(sprintf(
            'Clips reconciled: %d dispatched, %d stuck renders retried, %d failed after max attempts, %d rejected assets deleted, %d objects re-synced.',
            $dispatched, $retried, $failed, $deleted, $resynced,
        ));

        return $this->visibilityOutcome($unsynced);
    }

    /**
     * Fail the run when a visibility write failed (refused or thrown by the disk): the
     * object and its row are still apart, and a scheduler or monitor watching the exit
     * code should see it.
     */
    private function visibilityOutcome(int $unsynced): int
    {
        if ($unsynced === 0) {
            return self::SUCCESS;
        }

        $this->components->error("{$unsynced} clip objects could not be re-synced: the disk refused the visibility write (each is reported). They are retried on the next run.");

        return self::FAILURE;
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
     *
     * A write that fails (false from a throw => false disk, or a throwing disk) is counted
     * apart, never as re-synced, and the row's updated_at is bumped so the clip stays
     * inside the window until a write lands.
     *
     * @return array{0: int, 1: int} Objects re-synced, and objects whose write failed.
     */
    private function resyncVisibility(bool $all = false): array
    {
        if (config('scarlett-player.clips.public_delivery') !== 'disk-public') {
            return [0, 0];
        }

        $synced = 0;
        $unsynced = 0;

        Clip::query()
            ->whereNotNull('path')
            ->when(! $all, fn ($query) => $query->where('updated_at', '>=', now()->subSeconds(self::RESYNC_WINDOW)))
            ->orderBy('id')
            ->each(function (Clip $clip) use (&$synced, &$unsynced): void {
                try {
                    $clip->withModerationLock(fn () => $clip->syncAssetVisibility());
                    $synced++;
                } catch (LockTimeoutException $e) {
                    // A moderation in flight holds the clip: it writes the row and the
                    // object itself, and stamps updated_at. Not a failure of this pass.
                    report($e);
                } catch (Throwable $e) {
                    // The write failed (refused or thrown by the disk): the run fails, and
                    // a conditional touch keeps the clip inside the scheduled window, so
                    // it really is retried next run.
                    $unsynced++;
                    Clip::query()->whereKey($clip->getKey())->update(['updated_at' => now()]);
                    report($e);
                }
            });

        return [$synced, $unsynced];
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
