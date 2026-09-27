<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Events\ClipRequested;
use Hei\ScarlettPlayer\Jobs\RenderClip;
use Hei\ScarlettPlayer\Models\Clip;
use Hei\ScarlettPlayer\Tests\Feature\Clips\Support\ClipTestSupport as T;
use Hei\ScarlettPlayer\Tests\Feature\Clips\Support\FlakyBus;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    T::createUsers();
    $this->usesMigrations();
    T::fakeDisk();
    T::media(T::source());
    $this->actingAs($this->user = T::user());
});

/**
 * Release the render lock the fake bus left behind, as a finished job would.
 */
function releaseRenderLock(Clip $clip): void
{
    (new UniqueLock(app(Cache::class)))->release(new RenderClip($clip->id));
}

test('dispatch runs after the insert commits', function (): void {
    $bus = FlakyBus::install();

    T::post($this, T::payload())->assertStatus(202);

    expect($bus->transactionLevels)->toBe([0]);
    $bus->assertDispatchedTimes(RenderClip::class, 1);
});

test('a dispatch that throws after the insert still answers 202 and leaves the row undispatched', function (): void {
    $bus = FlakyBus::install(failNext: 1);

    T::post($this, T::payload())->assertStatus(202)->assertJson(['status' => 'pending']);

    expect(Clip::query()->sole()->dispatched_at)->toBeNull();
    $bus->assertNotDispatched(RenderClip::class);
});

test('a retry with the same clientRequestId returns the first clip and dispatches exactly one job when the first dispatch failed', function (): void {
    $bus = FlakyBus::install(failNext: 1);

    $first = T::post($this, T::payload())->assertStatus(202);
    $retry = T::post($this, T::payload())->assertStatus(202);

    expect($retry->json('uuid'))->toBe($first->json('uuid'))
        ->and(Clip::query()->count())->toBe(1)
        ->and(Clip::query()->sole()->dispatched_at)->not->toBeNull();
    $bus->assertDispatchedTimes(RenderClip::class, 1);
});

test('a retry of a clip that was dispatched moments ago dispatches nothing', function (): void {
    $bus = FlakyBus::install();

    T::post($this, T::payload())->assertStatus(202);
    T::post($this, T::payload())->assertStatus(202);
    T::post($this, T::payload())->assertStatus(202);

    $bus->assertDispatchedTimes(RenderClip::class, 1);
});

test('a retry after redispatch_after with no processing start dispatches again', function (): void {
    $bus = FlakyBus::install();

    T::post($this, T::payload())->assertStatus(202);
    $clip = Clip::query()->sole();
    releaseRenderLock($clip);

    $this->travel(121)->seconds();
    T::post($this, T::payload())->assertStatus(202);

    $bus->assertDispatchedTimes(RenderClip::class, 2);
});

test('a retry does not re-dispatch a clip whose job has started', function (): void {
    $bus = FlakyBus::install();

    T::post($this, T::payload())->assertStatus(202);
    Clip::query()->update(['processing_started_at' => now()]);
    releaseRenderLock(Clip::query()->sole());

    $this->travel(300)->seconds();
    T::post($this, T::payload())->assertStatus(202);

    $bus->assertDispatchedTimes(RenderClip::class, 1);
});

test('a retry fires no second ClipRequested', function (): void {
    FlakyBus::install();
    Event::fake([ClipRequested::class]);

    T::post($this, T::payload())->assertStatus(202);
    T::post($this, T::payload())->assertStatus(202);

    Event::assertDispatchedTimes(ClipRequested::class, 1);
});

test('two concurrent submissions with one clientRequestId make one row and one job', function (): void {
    $bus = FlakyBus::install();
    $competitorInserted = false;

    // The competing request inserts between this request's lookup and its insert.
    DB::listen(function ($query) use (&$competitorInserted): void {
        if (! $competitorInserted && str_contains($query->sql, 'from "scarlett_clips"') && str_contains($query->sql, 'client_request_id')) {
            $competitorInserted = true;
            $clip = T::clip(['client_request_id' => 'c7f1a2b3-0000-4000-8000-000000000001', 'user_id' => $this->user->id]);
            Clip::dispatchRender($clip);
            Clip::query()->whereKey($clip->id)->update(['dispatched_at' => now()]);
        }
    });

    $response = T::post($this, T::payload())->assertStatus(202);

    expect($competitorInserted)->toBeTrue()
        ->and(Clip::query()->count())->toBe(1)
        ->and($response->json('uuid'))->toBe(Clip::query()->sole()->uuid);
    $bus->assertDispatchedTimes(RenderClip::class, 1);
});

test('a clientRequestId used by another viewer is refused', function (): void {
    FlakyBus::install();
    T::clip(['client_request_id' => 'c7f1a2b3-0000-4000-8000-000000000001', 'user_id' => T::user('someone')->id]);

    T::post($this, T::payload())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['clientRequestId']);
});

test('ensureDispatched() ignores a clip that is not pending', function (string $status): void {
    $bus = FlakyBus::install();
    $clip = T::clip(['status' => $status]);

    expect($clip->ensureDispatched())->toBeFalse();
    $bus->assertNotDispatched(RenderClip::class);
})->with(['processing', 'ready', 'failed', 'rejected']);

test('ensureDispatched() gives its claim back when the dispatch throws', function (): void {
    FlakyBus::install(failNext: 1);
    $clip = T::clip();

    expect(fn () => $clip->ensureDispatched())->toThrow(RuntimeException::class);
    expect($clip->fresh()?->dispatched_at)->toBeNull();
});

test('a failed dispatch releases the unique lock so the next attempt can queue', function (): void {
    $bus = FlakyBus::install(failNext: 1);
    $clip = T::clip();

    expect(fn () => Clip::dispatchRender($clip))->toThrow(RuntimeException::class);
    expect(Clip::dispatchRender($clip))->toBeTrue();
    $bus->assertDispatchedTimes(RenderClip::class, 1);
});

test('a second dispatch while a render job holds the lock is skipped', function (): void {
    $bus = FlakyBus::install();
    $clip = T::clip();

    expect(Clip::dispatchRender($clip))->toBeTrue()
        ->and(Clip::dispatchRender($clip))->toBeFalse();
    $bus->assertDispatchedTimes(RenderClip::class, 1);
});

test('a re-dispatch while the first job is still queued pushes nothing and keeps the first stamp', function (): void {
    $bus = FlakyBus::install();

    T::post($this, T::payload())->assertStatus(202);
    $first = Clip::query()->sole()->dispatched_at;

    // A long backlog: the job has not started, well past redispatch_after and the old
    // job-timeout lock window. The lock lasts until processing, so nothing is pushed.
    $this->travel(400)->seconds();
    T::post($this, T::payload())->assertStatus(202);
    $this->artisan('scarlett:clips:reconcile')->assertSuccessful();

    $bus->assertDispatchedTimes(RenderClip::class, 1);
    expect(Clip::query()->sole()->dispatched_at?->equalTo($first))->toBeTrue();
});

test('ensureDispatched() reports false and stamps nothing when a queued job holds the lock', function (): void {
    FlakyBus::install();
    $clip = T::clip();
    Clip::dispatchRender($clip);

    expect($clip->ensureDispatched())->toBeFalse()
        ->and($clip->fresh()?->dispatched_at)->toBeNull();
});
