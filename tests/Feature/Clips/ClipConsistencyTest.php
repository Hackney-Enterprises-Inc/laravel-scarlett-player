<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Clips\ClipVerifier;
use Hei\ScarlettPlayer\Commands\ReconcileClipsCommand;
use Hei\ScarlettPlayer\Enums\ClipStatus;
use Hei\ScarlettPlayer\Enums\ClipVisibility;
use Hei\ScarlettPlayer\Events\ClipFailed;
use Hei\ScarlettPlayer\Events\ClipReady;
use Hei\ScarlettPlayer\Generators\ClipGeneratorManager;
use Hei\ScarlettPlayer\Jobs\RenderClip;
use Hei\ScarlettPlayer\Models\Clip;
use Hei\ScarlettPlayer\Tests\Feature\Clips\Support\ClipTestSupport as T;
use Hei\ScarlettPlayer\Tests\Feature\Clips\Support\FakeGenerator;
use Hei\ScarlettPlayer\Tests\Feature\Clips\Support\ScriptedDisk;
use Hei\ScarlettPlayer\Tests\Feature\Clips\Support\ScriptedVerifier;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/*
 * The row and the stored object must agree. Two reviewer findings: under disk-public a
 * reject() landing between approve()'s row update and its object write left a hidden
 * clip with a public object; and a store write that failed quietly still went ready.
 */

beforeEach(function (): void {
    T::createUsers();
    $this->usesMigrations();
    T::fakeDisk();
    config()->set('scarlett-player.clips.public_delivery', 'disk-public');
    config()->set('scarlett-player.clips.lock_wait', 0);
    $this->disk = ScriptedDisk::install();
});

function storedClip(array $attributes = []): Clip
{
    $clip = T::clip(array_merge(['status' => 'ready', 'disk' => 'clips'], $attributes));
    $clip->forceFill(['path' => "clips/{$clip->uuid}.mp4"])->save();
    Storage::disk('clips')->put((string) $clip->path, 'video', ['visibility' => 'private']);

    return $clip->refresh();
}

describe('moderation race under disk-public', function (): void {
    test('a reject() between approve()s row update and its object write is refused by the lock; the object follows the row', function (): void {
        $clip = storedClip();
        $rejected = null;

        // The interleaving: another request calls reject() after approve() updated the
        // row and before it writes the object. approve() holds the clip's lock.
        $this->disk->beforeVisibility = function () use ($clip, &$rejected): void {
            try {
                $clip->fresh()?->reject();
                $rejected = true;
            } catch (LockTimeoutException) {
                $rejected = false;
            }
        };

        $clip->approve();

        expect($rejected)->toBeFalse()
            ->and($clip->fresh()?->visibility)->toBe(ClipVisibility::Public)
            ->and(Storage::disk('clips')->getVisibility((string) $clip->path))->toBe('public');

        // The moderator's retry then lands after approve() and wins, row and object both.
        $clip->fresh()?->reject();

        expect($clip->fresh()?->visibility)->toBe(ClipVisibility::Hidden)
            ->and(Storage::disk('clips')->getVisibility((string) $clip->path))->toBe('private');
    });

    test('a reject that ignores the lock (or a crash) between the two writes still converges on private', function (): void {
        $clip = storedClip();

        // The reviewer's interleaving, as another process that never took the lock:
        // reject's row update and its private write land between approve's two writes.
        $this->disk->beforeVisibility = function (string $path) use ($clip): void {
            Clip::query()->whereKey($clip->id)->update(['visibility' => 'hidden', 'rejected_at' => now()]);
            Storage::disk('clips')->getDriver()->setVisibility($path, 'private');
        };

        $clip->approve();

        // approve()'s delayed public write happened, then its compensation re-read the row.
        expect($this->disk->visibilityWrites)->toBe([
            [(string) $clip->path, 'public'],
            [(string) $clip->path, 'private'],
        ])
            ->and($clip->fresh()?->visibility)->toBe(ClipVisibility::Hidden)
            ->and(Storage::disk('clips')->getVisibility((string) $clip->path))->toBe('private');
    });

    test('approve() and reject() wait for the clip lock and fail when it stays taken', function (string $method): void {
        $clip = storedClip();
        $lock = Cache::lock('scarlett:clip:'.$clip->uuid, 10);
        $lock->get();

        expect(fn () => $clip->{$method}())->toThrow(LockTimeoutException::class);

        $lock->release();
    })->with(['approve', 'reject']);

    test('a render finishing for a pre-approved clip takes the same lock and compensates', function (): void {
        config()->set('scarlett-player.clips.generator', 'fake');
        config()->set('scarlett-player.clips.generators.fake', ['driver' => 'fake', 'timeout' => 100]);
        $generator = new FakeGenerator;
        app(ClipGeneratorManager::class)->extend('fake', fn () => $generator);
        app()->instance(ClipVerifier::class, new ScriptedVerifier(app('config')));
        T::media(T::source());

        $clip = T::clip();
        $clip->approve();

        // A reject slips in, unlocked, right before the render's public write.
        $this->disk->beforeVisibility = function (string $path, string $visibility) use ($clip): void {
            if ($visibility === 'public') {
                Clip::query()->whereKey($clip->id)->update(['visibility' => 'hidden']);
            }
        };

        app()->call([new RenderClip($clip->id), 'handle']);

        expect($clip->fresh()?->visibility)->toBe(ClipVisibility::Hidden)
            ->and(Storage::disk('clips')->getVisibility((string) $clip->fresh()?->path))->toBe('private');
    });
});

describe('a store write that fails never goes ready', function (): void {
    beforeEach(function (): void {
        config()->set('scarlett-player.clips.public_delivery', 'signed-redirect');
        config()->set('scarlett-player.clips.generator', 'fake');
        config()->set('scarlett-player.clips.generators.fake', ['driver' => 'fake', 'timeout' => 100]);
        $generator = $this->generator = new FakeGenerator;
        app(ClipGeneratorManager::class)->extend('fake', fn () => $generator);
        app()->instance(ClipVerifier::class, new ScriptedVerifier(app('config')));
        T::media(T::source());
        Event::fake([ClipReady::class, ClipFailed::class]);
    });

    test('a refused, throwing or short write is a render error for the attempt: pending, temp file gone, no ClipReady', function (string $write): void {
        $this->disk->write = $write;
        $clip = T::clip();

        app()->call([new RenderClip($clip->id), 'handle']);

        $clip->refresh();

        expect($clip->status)->toBe(ClipStatus::Pending)
            ->and($clip->attempts)->toBe(1)
            ->and($clip->failure_reason)->toBe('render_error')
            ->and($clip->path)->toBeNull()
            ->and(file_exists($this->generator->calls[0]['file']))->toBeFalse()
            ->and(Storage::disk('clips')->allFiles())->toBe([]);
        Event::assertNotDispatched(ClipReady::class);
    })->with(['false', 'throw', 'short']);

    test('a failed write on the last attempt fails the clip', function (): void {
        $this->disk->write = 'false';
        $clip = T::clip(['attempts' => 2]);

        app()->call([new RenderClip($clip->id), 'handle']);

        expect($clip->fresh()?->status)->toBe(ClipStatus::Failed)
            ->and($clip->fresh()?->failure_reason)->toBe('render_error');
        Event::assertNotDispatched(ClipReady::class);
        Event::assertDispatched(ClipFailed::class);
    });

    test('a good write goes ready', function (): void {
        $clip = T::clip();

        app()->call([new RenderClip($clip->id), 'handle']);

        expect($clip->fresh()?->status)->toBe(ClipStatus::Ready);
        Event::assertDispatched(ClipReady::class);
    });
});

describe('reject fails safe, and reconcile re-syncs objects', function (): void {
    test('a reject whose private write throws leaves the row unrejected and the object as it was', function (): void {
        $clip = storedClip(['visibility' => 'public']);
        Storage::disk('clips')->setVisibility((string) $clip->path, 'public');
        $this->disk->visibilityWrites = [];
        $this->disk->beforeVisibility = function (string $path, string $visibility): void {
            if ($visibility === 'private') {
                throw new RuntimeException('S3 503');
            }
        };

        expect(fn () => $clip->reject())->toThrow(RuntimeException::class, 'S3 503');

        expect($clip->fresh()?->visibility)->toBe(ClipVisibility::Public)
            ->and($clip->fresh()?->rejected_at)->toBeNull()
            ->and(Storage::disk('clips')->getVisibility((string) $clip->path))->toBe('public');
    });

    test('reject writes the object private before the row', function (): void {
        $clip = storedClip(['visibility' => 'public']);
        $rowAtWrite = null;
        $this->disk->beforeVisibility = function () use ($clip, &$rowAtWrite): void {
            $rowAtWrite = $clip->fresh()?->visibility;
        };

        $clip->reject();

        expect($rowAtWrite)->toBe(ClipVisibility::Public)
            ->and($clip->fresh()?->visibility)->toBe(ClipVisibility::Hidden);
    });

    test('the reconcile pass fixes a hidden row with a public object, and a public row with a private object', function (): void {
        $hidden = storedClip(['visibility' => 'hidden']);
        Storage::disk('clips')->setVisibility((string) $hidden->path, 'public');
        $public = storedClip(['visibility' => 'public']);
        Storage::disk('clips')->setVisibility((string) $public->path, 'private');

        $this->artisan('scarlett:clips:reconcile')->assertSuccessful();

        expect(Storage::disk('clips')->getVisibility((string) $hidden->path))->toBe('private')
            ->and(Storage::disk('clips')->getVisibility((string) $public->path))->toBe('public');
    });

    test('the reconcile pass leaves objects alone under signed-redirect, and skips rows older than the window', function (): void {
        $old = storedClip(['visibility' => 'hidden']);
        Storage::disk('clips')->setVisibility((string) $old->path, 'public');
        Clip::query()->whereKey($old->id)->update(['updated_at' => now()->subSeconds(ReconcileClipsCommand::RESYNC_WINDOW + 60)]);

        $this->artisan('scarlett:clips:reconcile')->assertSuccessful();
        expect(Storage::disk('clips')->getVisibility((string) $old->path))->toBe('public');

        config()->set('scarlett-player.clips.public_delivery', 'signed-redirect');
        $recent = storedClip(['visibility' => 'public']);
        $this->disk->visibilityWrites = [];

        $this->artisan('scarlett:clips:reconcile')->assertSuccessful();
        expect($this->disk->visibilityWrites)->toBe([]);
    });
});

test('--resync-all re-syncs clips older than the window too', function (): void {
    $old = storedClip(['visibility' => 'hidden']);
    Storage::disk('clips')->setVisibility((string) $old->path, 'public');
    Clip::query()->whereKey($old->id)->update(['updated_at' => now()->subDays(30)]);

    $this->artisan('scarlett:clips:reconcile')->assertSuccessful();
    expect(Storage::disk('clips')->getVisibility((string) $old->path))->toBe('public');

    $this->artisan('scarlett:clips:reconcile', ['--resync-all' => true])->assertSuccessful();
    expect(Storage::disk('clips')->getVisibility((string) $old->path))->toBe('private');
});
