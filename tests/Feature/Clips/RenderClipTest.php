<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Clips\ClipVerifier;
use Hei\ScarlettPlayer\Clips\Verification;
use Hei\ScarlettPlayer\Contracts\ResolvesMedia;
use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Enums\ClipAccuracy;
use Hei\ScarlettPlayer\Enums\ClipStatus;
use Hei\ScarlettPlayer\Events\ClipFailed;
use Hei\ScarlettPlayer\Events\ClipProcessing;
use Hei\ScarlettPlayer\Events\ClipReady;
use Hei\ScarlettPlayer\Generators\ClipGeneratorManager;
use Hei\ScarlettPlayer\Jobs\RenderClip;
use Hei\ScarlettPlayer\Models\Clip;
use Hei\ScarlettPlayer\ScarlettPlayer;
use Hei\ScarlettPlayer\Tests\Feature\Clips\Support\ClipTestSupport as T;
use Hei\ScarlettPlayer\Tests\Feature\Clips\Support\FakeGenerator;
use Hei\ScarlettPlayer\Tests\Feature\Clips\Support\FlakyBus;
use Hei\ScarlettPlayer\Tests\Feature\Clips\Support\ScriptedVerifier;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    T::createUsers();
    $this->usesMigrations();
    T::fakeDisk();
    T::media(T::source(), T::source('ppv-1', isProtected: true), T::source('live-1', isLive: true));

    config()->set('scarlett-player.clips.generator', 'fake');
    config()->set('scarlett-player.clips.generators.fake', ['driver' => 'fake', 'timeout' => 100]);
    $generator = $this->generator = new FakeGenerator;
    app(ClipGeneratorManager::class)->extend('fake', fn () => $generator);

    $this->verifier = new ScriptedVerifier(app('config'));
    app()->instance(ClipVerifier::class, $this->verifier);

    Event::fake([ClipProcessing::class, ClipReady::class, ClipFailed::class]);
});

function render(Clip $clip): void
{
    app()->call([new RenderClip($clip->id), 'handle']);
}

describe('job shape', function (): void {
    test('timeout is the generator timeout plus 30; the unique lock covers an hour of queue wait', function (): void {
        $job = new RenderClip(7);

        expect($job->timeout)->toBe(130)
            ->and($job->uniqueFor)->toBe(3600)
            ->and(RenderClip::staleAfter())->toBe(190)
            ->and($job->uniqueId())->toBe('7')
            ->and($job->tries())->toBe(3)
            ->and($job->backoff())->toBe([30, 120]);
    });

    test('the default local-ffmpeg timeout gives 330', function (): void {
        config()->set('scarlett-player.clips.generator', 'local-ffmpeg');

        expect((new RenderClip(1))->timeout)->toBe(330);
    });

    test('it is unique until processing and queued', function (): void {
        expect(new RenderClip(1))
            ->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
            ->toBeInstanceOf(ShouldQueue::class);
    });

    test('the asset path is deterministic: {path}/{uuid}.mp4', function (): void {
        $clip = T::clip(['uuid' => 'aaaaaaaa-0000-4000-8000-000000000001']);

        expect(RenderClip::assetPath($clip))->toBe('clips/aaaaaaaa-0000-4000-8000-000000000001.mp4');

        config()->set('scarlett-player.clips.path', '/highlights/');

        expect(RenderClip::assetPath($clip))->toBe('highlights/aaaaaaaa-0000-4000-8000-000000000001.mp4');
    });
});

describe('render', function (): void {
    test('it renders, verifies, stores privately at the deterministic path and marks ready', function (): void {
        $clip = T::clip();

        render($clip);

        $clip->refresh();
        $path = "clips/{$clip->uuid}.mp4";

        expect($clip->status)->toBe(ClipStatus::Ready)
            ->and($clip->attempts)->toBe(1)
            ->and($clip->disk)->toBe('clips')
            ->and($clip->path)->toBe($path)
            ->and($clip->size_bytes)->toBe(strlen('rendered-bytes'))
            ->and($clip->rendered_at)->not->toBeNull()
            ->and($clip->verified_at)->not->toBeNull()
            ->and($clip->failure_reason)->toBeNull();

        Storage::disk('clips')->assertExists($path);
        expect(Storage::disk('clips')->getVisibility($path))->toBe('private')
            ->and(file_exists($this->generator->calls[0]['file']))->toBeFalse();

        Event::assertDispatched(ClipProcessing::class);
        Event::assertDispatched(ClipReady::class, fn (ClipReady $e): bool => $e->clip->is($clip));
    });

    test('the generator gets the stored interval, not anything the client sent', function (): void {
        render(T::clip(['start_seconds' => 12.25, 'end_seconds' => 42.25]));

        expect($this->generator->calls[0]['start'])->toBe(12.25)
            ->and($this->generator->calls[0]['end'])->toBe(42.25);
    });

    test('an unprotected source renders with the configured accuracy', function (string $accuracy): void {
        config()->set('scarlett-player.clips.accuracy', $accuracy);

        render(T::clip());

        expect($this->generator->calls[0]['options']->accuracy)->toBe(ClipAccuracy::from($accuracy));
    })->with(['keyframe', 'exact']);

    test('a protected source renders exact regardless of the accuracy config', function (): void {
        config()->set('scarlett-player.clips.accuracy', 'keyframe');

        render(T::clip(['media_id' => 'ppv-1']));

        expect($this->generator->calls[0]['options']->accuracy)->toBe(ClipAccuracy::Exact);
    });

    test('an unknown accuracy config renders exact', function (): void {
        config()->set('scarlett-player.clips.accuracy', 'fastest');

        render(T::clip());

        expect($this->generator->calls[0]['options']->accuracy)->toBe(ClipAccuracy::Exact);
    });

    test('a failed verification marks render_exceeds_bounds, deletes the temp file, stores nothing and fires ClipFailed', function (): void {
        config()->set('scarlett-player.clips.accuracy', 'exact');
        $this->verifier->verdicts = [false];
        $clip = T::clip();

        render($clip);

        expect($clip->fresh()?->status)->toBe(ClipStatus::Failed)
            ->and($clip->fresh()?->failure_reason)->toBe('render_exceeds_bounds')
            ->and($this->generator->calls)->toHaveCount(1)
            ->and(file_exists($this->generator->calls[0]['file']))->toBeFalse();
        Storage::disk('clips')->assertMissing("clips/{$clip->uuid}.mp4");
        Event::assertDispatched(ClipFailed::class, fn (ClipFailed $e): bool => $e->reason === 'render_exceeds_bounds');
        Event::assertNotDispatched(ClipReady::class);
    });
});

describe('keyframe fallback', function (): void {
    test('an unprotected keyframe miss re-renders exact in the same attempt and ends ready', function (): void {
        config()->set('scarlett-player.clips.accuracy', 'keyframe');
        $this->verifier->verdicts = [false, true];
        Log::spy();
        $clip = T::clip();

        render($clip);

        expect($clip->fresh()?->status)->toBe(ClipStatus::Ready)
            ->and($clip->fresh()?->attempts)->toBe(1)
            ->and($this->generator->calls)->toHaveCount(2)
            ->and($this->generator->calls[0]['options']->accuracy)->toBe(ClipAccuracy::Keyframe)
            ->and($this->generator->calls[1]['options']->accuracy)->toBe(ClipAccuracy::Exact)
            ->and(file_exists($this->generator->calls[0]['file']))->toBeFalse()
            ->and(file_exists($this->generator->calls[1]['file']))->toBeFalse();
        Log::shouldHaveReceived('info')->once()
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'keyframe') && $context['clip'] === $clip->uuid);
    });

    test('a protected source is rendered exactly once, and a miss fails it', function (): void {
        config()->set('scarlett-player.clips.accuracy', 'keyframe');
        $this->verifier->verdicts = [false];
        $clip = T::clip(['media_id' => 'ppv-1']);

        render($clip);

        expect($this->generator->calls)->toHaveCount(1)
            ->and($this->generator->calls[0]['options']->accuracy)->toBe(ClipAccuracy::Exact)
            ->and($clip->fresh()?->failure_reason)->toBe('render_exceeds_bounds');
    });

    test('at most one fallback: an exact re-render that also misses fails the clip', function (): void {
        config()->set('scarlett-player.clips.accuracy', 'keyframe');
        $this->verifier->verdicts = [false];
        $clip = T::clip();

        render($clip);

        expect($this->generator->calls)->toHaveCount(2)
            ->and($clip->fresh()?->status)->toBe(ClipStatus::Failed)
            ->and($clip->fresh()?->failure_reason)->toBe('render_exceeds_bounds');
    });

    test('a keyframe render that passes is not re-rendered', function (): void {
        config()->set('scarlett-player.clips.accuracy', 'keyframe');

        render(T::clip());

        expect($this->generator->calls)->toHaveCount(1);
    });
});

describe('idempotency', function (): void {
    test('a worker that died after writing the asset: the retry re-verifies it and completes without rendering', function (): void {
        $clip = T::clip(['status' => 'processing', 'attempts' => 1, 'processing_started_at' => now()->subMinutes(10)]);
        Storage::disk('clips')->put("clips/{$clip->uuid}.mp4", 'already-rendered', ['visibility' => 'private']);

        render($clip);

        expect($clip->fresh()?->status)->toBe(ClipStatus::Ready)
            ->and($clip->fresh()?->size_bytes)->toBe(strlen('already-rendered'))
            ->and($this->generator->calls)->toBe([])
            ->and($this->verifier->verified)->toHaveCount(1);
        Event::assertDispatched(ClipReady::class);
    });

    test('an existing asset that fails verification is deleted and rendered again', function (): void {
        $this->verifier->verdicts = [false, true];
        $clip = T::clip();
        Storage::disk('clips')->put("clips/{$clip->uuid}.mp4", 'bad', ['visibility' => 'private']);

        render($clip);

        expect($clip->fresh()?->status)->toBe(ClipStatus::Ready)
            ->and($this->generator->calls)->toHaveCount(1)
            ->and(Storage::disk('clips')->get("clips/{$clip->uuid}.mp4"))->toBe('rendered-bytes');
    });

    test('a terminal clip is left alone', function (string $status): void {
        $clip = T::clip(['status' => $status]);

        render($clip);

        expect($clip->fresh()?->status->value)->toBe($status)
            ->and($clip->fresh()?->attempts)->toBe(0)
            ->and($this->generator->calls)->toBe([]);
    })->with(['ready', 'failed', 'rejected']);

    test('a clip rejected while it rendered does not become ready', function (): void {
        $clip = T::clip();
        $this->verifier = new class(app('config')) extends ScriptedVerifier
        {
            public function verify(string $path, float $start, float $end, bool $isProtected): Verification
            {
                Clip::query()->update(['status' => 'rejected', 'visibility' => 'hidden']);

                return parent::verify($path, $start, $end, $isProtected);
            }
        };
        app()->instance(ClipVerifier::class, $this->verifier);

        render($clip);

        expect($clip->fresh()?->status)->toBe(ClipStatus::Rejected)
            ->and($clip->fresh()?->path)->toBeNull();
        Event::assertNotDispatched(ClipReady::class);
        // The path was never recorded, so pruning would never find it: deleted in the job.
        expect(Storage::disk('clips')->allFiles())->toBe([]);
    });

    test('a missing clip is a no-op', function (): void {
        app()->call([new RenderClip(999), 'handle']);

        expect($this->generator->calls)->toBe([]);
    });
});

describe('failure', function (): void {
    test('attempts are counted and exhausting max_attempts marks the clip failed', function (): void {
        $clip = T::clip(['attempts' => 3]);

        render($clip);

        expect($clip->fresh()?->status)->toBe(ClipStatus::Failed)
            ->and($clip->fresh()?->failure_reason)->toBe('attempts_exhausted')
            ->and($this->generator->calls)->toBe([]);
    });

    test('media the resolver no longer knows fails the clip', function (): void {
        $clip = T::clip(['media_id' => 'gone']);

        render($clip);

        expect($clip->fresh()?->failure_reason)->toBe('media_not_found')
            ->and($clip->fresh()?->status)->toBe(ClipStatus::Failed);
    });

    test('a source that became live fails the clip', function (): void {
        $clip = T::clip(['media_id' => 'live-1']);

        render($clip);

        expect($clip->fresh()?->failure_reason)->toBe('live_source');
    });

    test('a generator error with attempts left hands the clip back as pending for the queue retry', function (): void {
        $this->generator->throws = true;
        $clip = T::clip();

        render($clip);

        expect($clip->fresh()?->status)->toBe(ClipStatus::Pending)
            ->and($clip->fresh()?->processing_started_at)->toBeNull()
            ->and($clip->fresh()?->dispatched_at)->not->toBeNull()
            ->and($clip->fresh()?->attempts)->toBe(1)
            ->and($clip->fresh()?->failure_reason)->toBe('render_error');
        Event::assertNotDispatched(ClipFailed::class);
    });

    test('a generator error on the last attempt fails the clip', function (): void {
        $this->generator->throws = true;
        $clip = T::clip(['attempts' => 2]);

        render($clip);

        expect($clip->fresh()?->status)->toBe(ClipStatus::Failed)
            ->and($clip->fresh()?->failure_reason)->toBe('render_error');
        Event::assertDispatched(ClipFailed::class);
    });

    test('failed() marks the clip failed when the queue gives up', function (): void {
        $clip = T::clip(['status' => 'processing', 'attempts' => 3]);

        (new RenderClip($clip->id))->failed(new RuntimeException('timed out'));

        expect($clip->fresh()?->status)->toBe(ClipStatus::Failed)
            ->and($clip->fresh()?->failure_reason)->toBe('render_error');
    });
});

test('a protected source builds an exact ffmpeg command even when accuracy is keyframe', function (): void {
    config()->set('scarlett-player.clips.generator', 'local-ffmpeg');
    config()->set('scarlett-player.clips.accuracy', 'keyframe');
    config()->set('filesystems.disks.mezzanine', ['driver' => 'local', 'root' => sys_get_temp_dir().'/scarlett-mezzanine']);
    app()->forgetInstance(ClipVerifier::class);

    Process::fake(function (PendingProcess $process) {
        $command = (array) $process->command;

        if ($command[0] === 'ffprobe') {
            return Process::result(json_encode([
                'packets' => [['stream_index' => 0, 'pts_time' => '0.000000'], ['stream_index' => 0, 'pts_time' => '29.960000']],
                'streams' => [['index' => 0, 'codec_type' => 'video']],
                'format' => ['start_time' => '0.000000'],
            ]));
        }

        file_put_contents((string) end($command), 'video');

        return Process::result();
    });

    $clip = T::clip(['media_id' => 'ppv-1']);
    render($clip);

    Process::assertRan(function (PendingProcess $process): bool {
        $command = (array) $process->command;

        return $command[0] === 'ffmpeg'
            && array_search('-i', $command, true) < array_search('-ss', $command, true)
            && in_array('libx264', $command, true)
            && ! in_array('copy', $command, true);
    });
    expect($clip->fresh()?->status)->toBe(ClipStatus::Ready);
});

describe('exclusive render', function (): void {
    test('a second delivery during a live render exits without claiming, and attempts stay N', function (): void {
        $clip = T::clip(['status' => 'processing', 'attempts' => 1, 'processing_started_at' => now()->subSeconds(60)]);

        render($clip);

        expect($clip->fresh()?->status)->toBe(ClipStatus::Processing)
            ->and($clip->fresh()?->attempts)->toBe(1)
            ->and($this->generator->calls)->toBe([]);
        Event::assertNotDispatched(ClipProcessing::class);
    });

    test('a second delivery on the last attempt never fails a live render', function (): void {
        $clip = T::clip(['status' => 'processing', 'attempts' => 3, 'processing_started_at' => now()->subSeconds(60)]);

        render($clip);

        expect($clip->fresh()?->status)->toBe(ClipStatus::Processing);
        Event::assertNotDispatched(ClipFailed::class);
    });

    test('a render abandoned past staleAfter() is claimed again', function (): void {
        $clip = T::clip(['status' => 'processing', 'attempts' => 1, 'processing_started_at' => now()->subSeconds(RenderClip::staleAfter() + 1)]);

        render($clip);

        expect($clip->fresh()?->status)->toBe(ClipStatus::Ready)
            ->and($clip->fresh()?->attempts)->toBe(2)
            ->and($this->generator->calls)->toHaveCount(1);
    });
});

describe('disk-public', function (): void {
    test('a clip approved before it rendered gets a public object once ready', function (): void {
        config()->set('scarlett-player.clips.public_delivery', 'disk-public');
        $clip = T::clip();
        $clip->approve();

        render($clip);

        $clip->refresh();

        expect($clip->status)->toBe(ClipStatus::Ready)
            ->and(Storage::disk('clips')->getVisibility((string) $clip->path))->toBe('public');
    });

    test('an unapproved clip stays private under disk-public', function (): void {
        config()->set('scarlett-player.clips.public_delivery', 'disk-public');
        $clip = T::clip();

        render($clip);

        expect(Storage::disk('clips')->getVisibility((string) $clip->fresh()?->path))->toBe('private');
    });

    test('an approved clip stays private on disk under signed-redirect', function (): void {
        $clip = T::clip();
        $clip->approve();

        render($clip);

        expect(Storage::disk('clips')->getVisibility((string) $clip->fresh()?->path))->toBe('private');
    });
});

describe('an exception after the claim', function (): void {
    test('a verifier that throws hands the clip back; the retry 30 s later claims it and ends ready', function (): void {
        $flaky = new class(app('config')) extends ScriptedVerifier
        {
            public bool $throwNext = true;

            public function verify(string $path, float $start, float $end, bool $isProtected): Verification
            {
                if ($this->throwNext) {
                    $this->throwNext = false;

                    throw new RuntimeException('ffprobe crashed');
                }

                return parent::verify($path, $start, $end, $isProtected);
            }
        };
        app()->instance(ClipVerifier::class, $flaky);
        $clip = T::clip();

        render($clip);

        expect($clip->fresh()?->status)->toBe(ClipStatus::Pending)
            ->and($clip->fresh()?->attempts)->toBe(1)
            ->and($clip->fresh()?->failure_reason)->toBe('render_error')
            ->and(file_exists($this->generator->calls[0]['file']))->toBeFalse();

        $this->travel(30)->seconds();
        render($clip);

        expect($clip->fresh()?->status)->toBe(ClipStatus::Ready)
            ->and($clip->fresh()?->attempts)->toBe(2);
    });

    test('on the last attempt it fails the clip with the reason', function (): void {
        app()->instance(ClipVerifier::class, new class(app('config')) extends ScriptedVerifier
        {
            public function verify(string $path, float $start, float $end, bool $isProtected): Verification
            {
                throw new RuntimeException('ffprobe crashed');
            }
        });
        $clip = T::clip(['attempts' => 2]);

        render($clip);

        expect($clip->fresh()?->status)->toBe(ClipStatus::Failed)
            ->and($clip->fresh()?->failure_reason)->toBe('render_error');
        Event::assertDispatched(ClipFailed::class, fn (ClipFailed $e): bool => $e->reason === 'render_error');
    });

    test('a resolver that throws is handed back too', function (): void {
        app()->instance(ResolvesMedia::class, new class implements ResolvesMedia
        {
            public function resolve(string $mediaId): ?MediaSource
            {
                throw new RuntimeException('database gone');
            }
        });
        app()->forgetInstance(ScarlettPlayer::class);
        $clip = T::clip();

        render($clip);

        expect($clip->fresh()?->status)->toBe(ClipStatus::Pending)
            ->and($clip->fresh()?->attempts)->toBe(1);
    });
});

describe('clips disabled', function (): void {
    test('a render job exits without claiming, leaves the clip pending, and logs once', function (): void {
        config()->set('scarlett-player.clips.enabled', false);
        (new ReflectionProperty(RenderClip::class, 'disabledLogged'))->setValue(null, false);
        Log::spy();
        $a = T::clip();
        $b = T::clip();

        render($a);
        render($b);

        expect($a->fresh()?->status)->toBe(ClipStatus::Pending)
            ->and($a->fresh()?->attempts)->toBe(0)
            ->and($this->generator->calls)->toBe([]);
        Log::shouldHaveReceived('info')->once();
    });
});

describe('redelivery during a live render', function (): void {
    test('a delivery that finds the clip processing but not stale releases itself until staleAfter()', function (): void {
        $this->freezeSecond();
        $clip = T::clip(['status' => 'processing', 'attempts' => 1, 'processing_started_at' => now()->subSeconds(100)]);
        $job = (new RenderClip($clip->id))->withFakeQueueInteractions();

        app()->call([$job, 'handle']);

        $job->assertReleased(RenderClip::staleAfter() - 100 + 1);
        expect($clip->fresh()?->status)->toBe(ClipStatus::Processing)
            ->and($clip->fresh()?->attempts)->toBe(1);

        // When it comes back, the render counts as abandoned and the delivery claims it.
        $this->travel(RenderClip::staleAfter() - 100 + 1)->seconds();
        $retry = (new RenderClip($clip->id))->withFakeQueueInteractions();
        app()->call([$retry, 'handle']);

        $retry->assertNotReleased();
        expect($clip->fresh()?->status)->toBe(ClipStatus::Ready)
            ->and($clip->fresh()?->attempts)->toBe(2);
    });

    test('a terminal clip is not released, just left', function (): void {
        $clip = T::clip(['status' => 'ready']);
        $job = (new RenderClip($clip->id))->withFakeQueueInteractions();

        app()->call([$job, 'handle']);

        $job->assertNotReleased();
    });

    test('a generator error stamps dispatched_at past the backoff, so reconcile waits for the retry', function (): void {
        $this->freezeSecond();
        $this->generator->throws = true;
        $clip = T::clip();
        $bus = FlakyBus::install();

        render($clip);

        expect($clip->fresh()?->dispatched_at?->timestamp)->toBe(now()->addSeconds(30)->timestamp);

        $this->travel(30 + 100)->seconds();
        $this->artisan('scarlett:clips:reconcile')->assertSuccessful();
        $bus->assertNotDispatched(RenderClip::class);

        $this->travel(21)->seconds();
        $this->artisan('scarlett:clips:reconcile')->assertSuccessful();
        $bus->assertDispatched(RenderClip::class);
    });
});
