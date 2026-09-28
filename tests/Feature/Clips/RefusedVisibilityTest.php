<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Clips\ClipVerifier;
use Hei\ScarlettPlayer\Enums\ClipStatus;
use Hei\ScarlettPlayer\Enums\ClipVisibility;
use Hei\ScarlettPlayer\Events\ClipApproved;
use Hei\ScarlettPlayer\Events\ClipReady;
use Hei\ScarlettPlayer\Events\ClipRejected;
use Hei\ScarlettPlayer\Exceptions\ClipStorageException;
use Hei\ScarlettPlayer\Generators\ClipGeneratorManager;
use Hei\ScarlettPlayer\Jobs\RenderClip;
use Hei\ScarlettPlayer\Models\Clip;
use Hei\ScarlettPlayer\Tests\Feature\Clips\Support\ClipTestSupport as T;
use Hei\ScarlettPlayer\Tests\Feature\Clips\Support\FakeGenerator;
use Hei\ScarlettPlayer\Tests\Feature\Clips\Support\RefusingVisibilityDisk;
use Hei\ScarlettPlayer\Tests\Feature\Clips\Support\ScriptedVerifier;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;

/*
 * A disk configured throw => false answers a failed visibility write with false, not an
 * exception. Every visibility write the package makes must treat that as a failure:
 * a rejected clip was left with a public object behind a hidden row.
 */

beforeEach(function (): void {
    T::createUsers();
    $this->usesMigrations();
    T::fakeDisk();
    config()->set('scarlett-player.clips.public_delivery', 'disk-public');
    config()->set('scarlett-player.clips.lock_wait', 0);
    $this->disk = RefusingVisibilityDisk::install();
    Event::fake([ClipApproved::class, ClipRejected::class, ClipReady::class]);
});

function refusedStoredClip(array $attributes = [], string $objectVisibility = 'private'): Clip
{
    $clip = T::clip(array_merge(['status' => 'ready', 'disk' => 'clips'], $attributes));
    $clip->forceFill(['path' => "clips/{$clip->uuid}.mp4"])->save();
    Storage::disk('clips')->put((string) $clip->path, 'video', ['visibility' => $objectVisibility]);

    return $clip->refresh();
}

test('the test disk is Laravel\'s adapter answering a refused write with false', function (): void {
    $clip = refusedStoredClip();
    $this->disk->refuse = ['public'];

    expect(Storage::disk('clips')->setVisibility((string) $clip->path, 'public'))->toBeFalse()
        ->and(Storage::disk('clips')->getVisibility((string) $clip->path))->toBe('private');
});

test('reject() throws before touching the row when the private write is refused', function (): void {
    $clip = refusedStoredClip(['visibility' => 'public'], objectVisibility: 'public');
    $this->disk->refuse = ['private'];

    expect(fn () => $clip->reject())->toThrow(ClipStorageException::class, "Clip [{$clip->uuid}]");

    expect($clip->fresh()?->visibility)->toBe(ClipVisibility::Public)
        ->and($clip->fresh()?->rejected_at)->toBeNull()
        ->and(Storage::disk('clips')->getVisibility((string) $clip->path))->toBe('public')
        ->and($this->disk->refused)->toBe([[(string) $clip->path, 'private']]);
    Event::assertNotDispatched(ClipRejected::class);
});

test('approve() surfaces a refused public write; the object stays private', function (): void {
    $clip = refusedStoredClip(['visibility' => 'hidden']);
    $this->disk->refuse = ['public'];

    expect(fn () => $clip->approve())->toThrow(ClipStorageException::class, 'did not make');

    expect(Storage::disk('clips')->getVisibility((string) $clip->path))->toBe('private')
        ->and($clip->fresh()?->visibility)->toBe(ClipVisibility::Public);
    Event::assertNotDispatched(ClipApproved::class);
});

test('syncAssetVisibility() throws when the write the row calls for is refused', function (string $row, string $object, string $refused): void {
    $clip = refusedStoredClip(['visibility' => $row], objectVisibility: $object);
    $this->disk->refuse = [$refused];

    expect(fn () => $clip->syncAssetVisibility())->toThrow(ClipStorageException::class, $refused);

    expect(Storage::disk('clips')->getVisibility((string) $clip->path))->toBe($object);
})->with([
    'hidden row, public object' => ['hidden', 'public', 'private'],
    'public row, private object' => ['public', 'private', 'public'],
]);

test('syncAssetVisibility() throws when its compensating private write is refused', function (): void {
    $clip = refusedStoredClip(['visibility' => 'public']);
    $this->disk->refuse = ['private'];
    // A reject that ran without the lock lands between the public write and the re-read.
    $this->disk->before = function () use ($clip): void {
        Clip::query()->whereKey($clip->getKey())->update(['visibility' => ClipVisibility::Hidden->value]);
    };

    expect(fn () => $clip->syncAssetVisibility())->toThrow(ClipStorageException::class, 'private');

    expect($this->disk->refused)->toBe([[(string) $clip->path, 'private']]);
});

describe('render', function (): void {
    beforeEach(function (): void {
        config()->set('scarlett-player.clips.generator', 'fake');
        config()->set('scarlett-player.clips.generators.fake', ['driver' => 'fake', 'timeout' => 100]);
        $generator = $this->generator = new FakeGenerator;
        app(ClipGeneratorManager::class)->extend('fake', fn () => $generator);
        app()->instance(ClipVerifier::class, new ScriptedVerifier(app('config')));
        T::media(T::source());
        Exceptions::fake();
    });

    test('a clip approved before it rendered still goes ready when the public write is refused, private and reported', function (): void {
        $clip = T::clip(['visibility' => 'public']);
        $this->disk->refuse = ['public'];

        app()->call([new RenderClip($clip->id), 'handle']);

        $clip->refresh();

        expect($clip->status)->toBe(ClipStatus::Ready)
            ->and(Storage::disk('clips')->getVisibility((string) $clip->path))->toBe('private');
        Event::assertDispatched(ClipReady::class);
        Exceptions::assertReported(ClipStorageException::class);
    });

    test('completing from an existing asset whose private write is refused is a render error, not ready', function (): void {
        $clip = T::clip(['status' => 'processing', 'attempts' => 1, 'processing_started_at' => now()->subMinutes(10)]);
        Storage::disk('clips')->put("clips/{$clip->uuid}.mp4", 'already-rendered', ['visibility' => 'private']);
        $this->disk->refuse = ['private'];

        app()->call([new RenderClip($clip->id), 'handle']);

        expect($clip->fresh()?->status)->toBe(ClipStatus::Pending)
            ->and($clip->fresh()?->failure_reason)->toBe('render_error');
        Event::assertNotDispatched(ClipReady::class);
        Exceptions::assertReported(ClipStorageException::class);
    });
});

test('reconcile counts a refused write apart, reports it and exits with failure', function (): void {
    Exceptions::fake();
    $stuck = refusedStoredClip(['visibility' => 'hidden'], objectVisibility: 'public');
    $healed = refusedStoredClip(['visibility' => 'public']);
    $this->disk->refuse = ['private'];

    $this->artisan('scarlett:clips:reconcile')
        ->expectsOutputToContain('1 objects re-synced')
        ->expectsOutputToContain('1 clip objects could not be re-synced')
        ->assertFailed();

    expect(Storage::disk('clips')->getVisibility((string) $stuck->path))->toBe('public')
        ->and(Storage::disk('clips')->getVisibility((string) $healed->path))->toBe('public');
    Exceptions::assertReported(fn (ClipStorageException $e): bool => str_contains($e->getMessage(), (string) $stuck->uuid));

    // Once the disk accepts the write, the next run heals it and succeeds.
    $this->disk->refuse = [];

    $this->artisan('scarlett:clips:reconcile')->assertSuccessful();
    expect(Storage::disk('clips')->getVisibility((string) $stuck->path))->toBe('private');
});

test('a private write to an object that no longer exists is not a failure; a public one is', function (): void {
    $clip = refusedStoredClip(['visibility' => 'public']);
    Storage::disk('clips')->delete((string) $clip->path);

    $clip->reject();

    expect($clip->fresh()?->visibility)->toBe(ClipVisibility::Hidden);
    Event::assertDispatched(ClipRejected::class);

    expect(fn () => $clip->fresh()?->approve())->toThrow(ClipStorageException::class, 'public');
});
