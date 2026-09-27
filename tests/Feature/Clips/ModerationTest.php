<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Enums\ClipStatus;
use Hei\ScarlettPlayer\Enums\ClipVisibility;
use Hei\ScarlettPlayer\Events\ClipApproved;
use Hei\ScarlettPlayer\Events\ClipFailed;
use Hei\ScarlettPlayer\Events\ClipProcessing;
use Hei\ScarlettPlayer\Events\ClipReady;
use Hei\ScarlettPlayer\Events\ClipRejected;
use Hei\ScarlettPlayer\Events\ClipRequested;
use Hei\ScarlettPlayer\Exceptions\ClipStateException;
use Hei\ScarlettPlayer\Jobs\RenderClip;
use Hei\ScarlettPlayer\Tests\Feature\Clips\Support\ClipTestSupport as T;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    T::createUsers();
    $this->usesMigrations();
    T::fakeDisk();
    Event::fake([ClipApproved::class, ClipRejected::class]);
    $this->moderator = T::user('moderator');
});

test('approve() makes the clip public, records who and when, and fires ClipApproved', function (): void {
    $clip = T::clip(['status' => 'ready', 'disk' => 'clips', 'path' => 'clips/a.mp4', 'rejected_at' => now()->subDay(), 'rejected_by' => 99]);

    $clip->approve($this->moderator);

    $clip->refresh();

    expect($clip->visibility)->toBe(ClipVisibility::Public)
        ->and($clip->approved_by)->toBe($this->moderator->id)
        ->and($clip->approved_at)->not->toBeNull()
        ->and($clip->rejected_at)->toBeNull()
        ->and($clip->rejected_by)->toBeNull();
    Event::assertDispatched(ClipApproved::class, fn (ClipApproved $e): bool => $e->clip->is($clip) && $e->by?->is($this->moderator));
});

test('approve() before the render pre-approves: the clip plays once ready', function (): void {
    $clip = T::clip(['status' => 'pending']);

    $clip->approve();

    expect($clip->fresh()?->visibility)->toBe(ClipVisibility::Public)
        ->and($clip->fresh()?->status)->toBe(ClipStatus::Pending)
        ->and($clip->fresh()?->approved_by)->toBeNull();
});

test('a failed or rejected clip cannot be approved', function (string $status): void {
    T::clip(['status' => $status])->approve();
})->with(['failed', 'rejected'])->throws(ClipStateException::class);

test('reject() hides a rendered clip, records who and when, and fires ClipRejected', function (): void {
    $clip = T::clip(['status' => 'ready', 'visibility' => 'public']);

    $clip->reject($this->moderator);

    $clip->refresh();

    expect($clip->visibility)->toBe(ClipVisibility::Hidden)
        ->and($clip->status)->toBe(ClipStatus::Ready)
        ->and($clip->rejected_by)->toBe($this->moderator->id)
        ->and($clip->rejected_at)->not->toBeNull();
    Event::assertDispatched(ClipRejected::class, fn (ClipRejected $e): bool => $e->clip->is($clip) && $e->by?->is($this->moderator));
});

test('reject() before the render marks it rejected so no job renders it', function (string $status): void {
    $clip = T::clip(['status' => $status]);

    $clip->reject();

    expect($clip->fresh()?->status)->toBe(ClipStatus::Rejected);

    app()->call([new RenderClip($clip->id), 'handle']);

    expect($clip->fresh()?->status)->toBe(ClipStatus::Rejected)
        ->and($clip->fresh()?->attempts)->toBe(0);
})->with(['pending', 'processing']);

test('the six clip events exist and carry the clip', function (): void {
    $clip = T::clip();

    foreach ([
        new ClipRequested($clip),
        new ClipProcessing($clip),
        new ClipReady($clip),
        new ClipFailed($clip, 'render_error'),
        new ClipApproved($clip),
        new ClipRejected($clip),
    ] as $event) {
        expect($event->clip)->toBe($clip);
    }
});

test('a reject on a stale model after the render finished never overwrites ready', function (): void {
    $clip = T::clip(['status' => 'processing']);
    $stale = $clip->fresh();
    $clip->newQuery()->whereKey($clip->id)->update(['status' => 'ready', 'disk' => 'clips', 'path' => 'clips/a.mp4']);

    $stale?->reject($this->moderator);

    expect($clip->fresh()?->status)->toBe(ClipStatus::Ready)
        ->and($clip->fresh()?->visibility)->toBe(ClipVisibility::Hidden);
});

test('an approve on a stale model of a clip rejected meanwhile throws and changes nothing', function (): void {
    $clip = T::clip(['status' => 'pending']);
    $stale = $clip->fresh();
    $clip->reject($this->moderator);

    expect(fn () => $stale?->approve($this->moderator))->toThrow(ClipStateException::class);
    expect($clip->fresh()?->visibility)->toBe(ClipVisibility::Hidden)
        ->and($clip->fresh()?->status)->toBe(ClipStatus::Rejected);
});

test('a ready clip whose asset was pruned cannot be approved', function (): void {
    Storage::fake('clips');
    Storage::disk('clips')->put('clips/old.mp4', 'x');
    $clip = T::clip(['status' => 'ready', 'visibility' => 'hidden', 'disk' => 'clips', 'path' => 'clips/old.mp4', 'rejected_at' => now()->subDays(8)]);

    $this->artisan('scarlett:clips:reconcile')->assertSuccessful();

    expect($clip->fresh()?->path)->toBeNull()
        ->and(fn () => $clip->fresh()?->approve($this->moderator))->toThrow(ClipStateException::class);
});
