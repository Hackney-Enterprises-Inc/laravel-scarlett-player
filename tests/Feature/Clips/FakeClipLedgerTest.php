<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Facades\ScarlettPlayer;
use Hei\ScarlettPlayer\Jobs\RenderClip;
use Hei\ScarlettPlayer\Tests\Feature\Clips\Support\ClipTestSupport as T;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\ExpectationFailedException;

beforeEach(function (): void {
    T::createUsers();
    $this->usesMigrations();
    T::fakeDisk();
    Bus::fake([RenderClip::class]);
});

test('Scarlett::fake() records clips requested through the endpoint', function (): void {
    $fake = ScarlettPlayer::fake()->withMedia(T::source());
    $user = T::user();

    $this->actingAs($user);
    T::post($this, T::payload())->assertStatus(202);

    $fake->assertResolved('vid-1')
        ->assertClipRequested()
        ->assertClipRequested(fn (array $clip): bool => $clip['mediaId'] === 'vid-1'
            && $clip['clientRequestId'] === 'c7f1a2b3-0000-4000-8000-000000000001'
            && $clip['duration'] === 30.0
            && $clip['userId'] === $user->id);

    expect($fake->recordedClips())->toHaveCount(1);
});

test('a retry is not recorded twice', function (): void {
    $fake = ScarlettPlayer::fake()->withMedia(T::source());
    $this->actingAs(T::user());

    T::post($this, T::payload())->assertStatus(202);
    T::post($this, T::payload())->assertStatus(202);

    expect($fake->recordedClips())->toHaveCount(1);
});

test('assertClipRequested fails when nothing was requested', function (): void {
    ScarlettPlayer::fake()->assertClipRequested();
})->throws(ExpectationFailedException::class);
