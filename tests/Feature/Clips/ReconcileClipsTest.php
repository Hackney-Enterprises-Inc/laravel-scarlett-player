<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Enums\ClipStatus;
use Hei\ScarlettPlayer\Events\ClipFailed;
use Hei\ScarlettPlayer\Jobs\RenderClip;
use Hei\ScarlettPlayer\Tests\Feature\Clips\Support\ClipTestSupport as T;
use Hei\ScarlettPlayer\Tests\Feature\Clips\Support\FlakyBus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    T::createUsers();
    $this->usesMigrations();
    T::fakeDisk();
    $this->bus = FlakyBus::install();
});

test('reconcile dispatches a pending clip whose dispatched_at is null', function (): void {
    $clip = T::clip(['dispatched_at' => null]);

    $this->artisan('scarlett:clips:reconcile')->assertSuccessful();

    $this->bus->assertDispatched(RenderClip::class, fn (RenderClip $job): bool => $job->clipId === $clip->id);
    expect($clip->fresh()?->dispatched_at)->not->toBeNull();
});

test('reconcile re-dispatches a pending clip dispatched longer than redispatch_after ago that never started', function (): void {
    T::clip(['dispatched_at' => now()->subSeconds(121)]);
    T::clip(['dispatched_at' => now()->subSeconds(30)]);
    T::clip(['dispatched_at' => now()->subSeconds(500), 'processing_started_at' => now()->subSeconds(400)]);

    $this->artisan('scarlett:clips:reconcile')->assertSuccessful();

    $this->bus->assertDispatchedTimes(RenderClip::class, 1);
});

test('reconcile leaves ready, failed and rejected clips alone', function (): void {
    T::clip(['status' => 'ready']);
    T::clip(['status' => 'failed']);
    T::clip(['status' => 'rejected']);

    $this->artisan('scarlett:clips:reconcile')->assertSuccessful();

    $this->bus->assertNotDispatched(RenderClip::class);
});

test('a render stuck in processing past the job timeout is made retryable and dispatched', function (): void {
    $stuck = T::clip(['status' => 'processing', 'attempts' => 1, 'processing_started_at' => now()->subSeconds(RenderClip::timeoutFor() + 61)]);
    $running = T::clip(['status' => 'processing', 'attempts' => 1, 'processing_started_at' => now()->subSeconds(60)]);

    $this->artisan('scarlett:clips:reconcile')->assertSuccessful();

    expect($stuck->fresh()?->status)->toBe(ClipStatus::Pending)
        ->and($stuck->fresh()?->dispatched_at)->not->toBeNull()
        ->and($running->fresh()?->status)->toBe(ClipStatus::Processing);
    $this->bus->assertDispatchedTimes(RenderClip::class, 1);
});

test('a stuck render with no attempts left is marked failed and fires ClipFailed', function (): void {
    Event::fake([ClipFailed::class]);
    $clip = T::clip(['status' => 'processing', 'attempts' => 3, 'processing_started_at' => now()->subHour()]);

    $this->artisan('scarlett:clips:reconcile')->assertSuccessful();

    expect($clip->fresh()?->status)->toBe(ClipStatus::Failed)
        ->and($clip->fresh()?->failure_reason)->toBe('attempts_exhausted');
    Event::assertDispatched(ClipFailed::class, fn (ClipFailed $e): bool => $e->reason === 'attempts_exhausted');
    $this->bus->assertNotDispatched(RenderClip::class);
});

test('rejected assets are deleted after delete_rejected_after days', function (): void {
    Storage::disk('clips')->put('clips/old.mp4', 'x');
    Storage::disk('clips')->put('clips/new.mp4', 'x');
    $old = T::clip(['status' => 'ready', 'visibility' => 'hidden', 'disk' => 'clips', 'path' => 'clips/old.mp4', 'rejected_at' => now()->subDays(8)]);
    $new = T::clip(['status' => 'ready', 'visibility' => 'hidden', 'disk' => 'clips', 'path' => 'clips/new.mp4', 'rejected_at' => now()->subDays(2)]);

    $this->artisan('scarlett:clips:reconcile')->assertSuccessful();

    Storage::disk('clips')->assertMissing('clips/old.mp4');
    Storage::disk('clips')->assertExists('clips/new.mp4');
    expect($old->fresh()?->path)->toBeNull()
        ->and($new->fresh()?->path)->toBe('clips/new.mp4');
});

test('a null delete_rejected_after keeps rejected assets', function (): void {
    config()->set('scarlett-player.clips.delete_rejected_after', null);
    Storage::disk('clips')->put('clips/old.mp4', 'x');
    T::clip(['status' => 'ready', 'visibility' => 'hidden', 'disk' => 'clips', 'path' => 'clips/old.mp4', 'rejected_at' => now()->subYear()]);

    $this->artisan('scarlett:clips:reconcile')->assertSuccessful();

    Storage::disk('clips')->assertExists('clips/old.mp4');
});

test('with clips disabled, reconcile dispatches nothing and still prunes', function (): void {
    config()->set('scarlett-player.clips.enabled', false);
    $pending = T::clip(['dispatched_at' => null]);
    Storage::disk('clips')->put('clips/old.mp4', 'x');
    T::clip(['status' => 'ready', 'visibility' => 'hidden', 'disk' => 'clips', 'path' => 'clips/old.mp4', 'rejected_at' => now()->subDays(8)]);

    $this->artisan('scarlett:clips:reconcile')->assertSuccessful();

    $this->bus->assertNotDispatched(RenderClip::class);
    expect($pending->fresh()?->dispatched_at)->toBeNull();
    Storage::disk('clips')->assertMissing('clips/old.mp4');

    config()->set('scarlett-player.clips.enabled', true);
    $this->artisan('scarlett:clips:reconcile')->assertSuccessful();

    $this->bus->assertDispatched(RenderClip::class, fn (RenderClip $job): bool => $job->clipId === $pending->id);
});
