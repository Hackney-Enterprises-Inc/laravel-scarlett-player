<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Enums\ClipStatus;
use Hei\ScarlettPlayer\Events\ClipRequested;
use Hei\ScarlettPlayer\Http\Requests\StoreClipRequest;
use Hei\ScarlettPlayer\Jobs\RenderClip;
use Hei\ScarlettPlayer\Models\Clip;
use Hei\ScarlettPlayer\Tests\Feature\Clips\Support\ClipTestSupport as T;
use Hei\ScarlettPlayer\Tests\Fixtures\Models\MediaSchema;
use Hei\ScarlettPlayer\Tests\Fixtures\Models\ScarlettVideo;
use Hei\ScarlettPlayer\Tests\Fixtures\Models\UuidVideo;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    T::createUsers();
    $this->usesMigrations();
    T::fakeDisk();
    T::media(T::source(), T::source('ppv-1', isProtected: true), T::source('live-1', isLive: true));
    Bus::fake([RenderClip::class]);
    $this->actingAs($this->user = T::user());
});

describe('accepted', function (): void {
    test('a valid ClipRange answers 202 with the uuid, status and status URL', function (): void {
        $response = T::post($this, T::payload());

        $clip = Clip::query()->sole();

        $response->assertStatus(202)
            ->assertJson([
                'uuid' => $clip->uuid,
                'status' => 'pending',
                'visibility' => 'pending_review',
                'mediaId' => 'vid-1',
                'title' => 'The knockout',
                'startTime' => 120.5,
                'endTime' => 150.5,
                'duration' => 30,
                'statusUrl' => route('scarlett.clips.show', ['uuid' => $clip->uuid]),
                'playbackUrl' => null,
                'previewUrl' => null,
            ]);
    });

    test('camelCase in, snake_case in the database', function (): void {
        T::post($this, T::payload())->assertStatus(202);

        $clip = Clip::query()->sole();

        expect($clip)
            ->media_id->toBe('vid-1')
            ->client_request_id->toBe('c7f1a2b3-0000-4000-8000-000000000001')
            ->user_id->toBe($this->user->id)
            ->title->toBe('The knockout')
            ->start_seconds->toBe(120.5)
            ->end_seconds->toBe(150.5)
            ->duration_seconds->toBe(30.0)
            ->status->toBe(ClipStatus::Pending)
            ->and($clip->captured_at?->toIso8601ZuluString())->toBe('2026-09-07T18:04:11Z');
    });

    test('it never renders inline: the render job is queued on the clips connection and queue', function (): void {
        config()->set('scarlett-player.clips.connection', 'redis');
        config()->set('scarlett-player.clips.queue', 'clip-renders');

        T::post($this, T::payload())->assertStatus(202);

        $clip = Clip::query()->sole();

        Bus::assertDispatched(RenderClip::class, fn (RenderClip $job): bool => $job->clipId === $clip->id
            && $job->connection === 'redis'
            && $job->queue === 'clip-renders');
        Bus::assertDispatchedTimes(RenderClip::class, 1);
        expect($clip->dispatched_at)->not->toBeNull();
    });

    test('ClipRequested fires for a new clip', function (): void {
        Event::fake([ClipRequested::class]);

        T::post($this, T::payload())->assertStatus(202);

        Event::assertDispatchedTimes(ClipRequested::class, 1);
    });

    test('a resolver model becomes the clippable', function (): void {
        MediaSchema::create();
        $video = ScarlettVideo::query()->create(['uuid' => 'c0ffee00-0000-4000-8000-000000000001']);
        T::media(new MediaSource(
            id: $video->uuid, playbackUrl: 'https://cdn.example.test/v.m3u8',
            isLive: false, isProtected: false, duration: 600.0, model: $video,
        ));

        T::post($this, T::payload(['mediaId' => $video->uuid]))->assertStatus(202);

        expect(Clip::query()->sole()->clippable?->is($video))->toBeTrue();
    });

    test('the configured starting visibility is applied', function (): void {
        config()->set('scarlett-player.clips.visibility', 'hidden');

        T::post($this, T::payload())->assertStatus(202)->assertJson(['visibility' => 'hidden']);
    });

    test('a null title and no capturedAt are accepted', function (): void {
        $payload = T::payload(['title' => null]);
        unset($payload['capturedAt']);

        T::post($this, $payload)->assertStatus(202)->assertJson(['title' => null]);
    });
});

describe('duration', function (): void {
    test('duration is re-derived from endTime - startTime, whatever the client says', function (): void {
        T::post($this, T::payload(['startTime' => 100, 'endTime' => 140, 'duration' => 5]))->assertStatus(202);

        expect(Clip::query()->sole()->duration_seconds)->toBe(40.0);
    });

    test('a client duration disagreeing with the range cannot widen the clip past max_duration', function (): void {
        T::post($this, T::payload(['startTime' => 0, 'endTime' => 90, 'duration' => 30]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Clips can be at most 60 seconds.')
            ->assertJsonValidationErrors(['endTime' => 'Clips can be at most 60 seconds.']);

        expect(Clip::query()->count())->toBe(0);
    });

    test('max_duration from config wins over any client value', function (): void {
        config()->set('scarlett-player.clips.max_duration', 20);

        T::post($this, T::payload(['duration' => 20]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Clips can be at most 20 seconds.');
    });

    test('min_duration from config is enforced', function (): void {
        config()->set('scarlett-player.clips.min_duration', 10);

        T::post($this, T::payload(['startTime' => 10, 'endTime' => 15, 'duration' => 30]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Clips must be at least 10 seconds.');
    });

    test('exactly max_duration is accepted', function (): void {
        T::post($this, T::payload(['startTime' => 0, 'endTime' => 60]))->assertStatus(202);
    });

    test('a clip ending past the end of the media is refused', function (): void {
        T::post($this, T::payload(['startTime' => 590, 'endTime' => 620]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Your clip runs past the end of the video.');
    });

    test('endTime must be after startTime', function (): void {
        T::post($this, T::payload(['startTime' => 50, 'endTime' => 40]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Your clip has to end after it starts.');
    });
});

describe('live is rejected in v1', function (): void {
    test('isLive true is a 422 with the viewer message', function (): void {
        T::post($this, T::payload(['isLive' => true]))
            ->assertStatus(422)
            ->assertJsonPath('message', StoreClipRequest::LIVE_MESSAGE)
            ->assertJsonValidationErrors(['isLive' => StoreClipRequest::LIVE_MESSAGE]);
    });

    test('a missing isLive is refused', function (): void {
        $payload = T::payload();
        unset($payload['isLive']);

        T::post($this, $payload)->assertStatus(422)->assertJsonPath('message', StoreClipRequest::LIVE_MESSAGE);
    });

    test('any non-null live field is a 422 with the viewer message', function (string $field, mixed $value): void {
        T::post($this, T::payload([$field => $value]))
            ->assertStatus(422)
            ->assertJsonPath('message', StoreClipRequest::LIVE_MESSAGE)
            ->assertJsonValidationErrors([$field]);
    })->with([
        ['seekableStart', 0],
        ['seekableEnd', 3600.0],
        ['startDate', '2026-09-07T18:00:00.000Z'],
        ['endDate', '2026-09-07T18:00:30.000Z'],
    ]);

    test('the live check comes before the numbers', function (): void {
        T::post($this, T::payload(['isLive' => true, 'startTime' => 0, 'endTime' => 600]))
            ->assertStatus(422)
            ->assertJsonPath('message', StoreClipRequest::LIVE_MESSAGE);
    });

    test('a live MediaSource is a 422 with the viewer message', function (): void {
        T::post($this, T::payload(['mediaId' => 'live-1']))
            ->assertStatus(422)
            ->assertJsonPath('message', StoreClipRequest::LIVE_MESSAGE);

        expect(Clip::query()->count())->toBe(0);
        Bus::assertNotDispatched(RenderClip::class);
    });
});

describe('refused', function (): void {
    test('an unknown mediaId is a 404', function (): void {
        T::post($this, T::payload(['mediaId' => 'nope']))
            ->assertStatus(404)
            ->assertJsonPath('message', 'This video could not be found.');
    });

    test('protected media with no published policy is denied (fail closed)', function (): void {
        T::post($this, T::payload(['mediaId' => 'ppv-1']))
            ->assertStatus(403)
            ->assertJsonPath('message', "You can't make clips from this video.");

        expect(Clip::query()->count())->toBe(0);
        Bus::assertNotDispatched(RenderClip::class);
    });

    test('messages are one viewer sentence, with no developer suffix', function (): void {
        $response = T::post($this, T::payload(['startTime' => 'soon', 'endTime' => 'later', 'mediaId' => null]))
            ->assertStatus(422);

        expect($response->json('message'))->not->toContain('(and');
    });

    test('a required key missing is a 422 on its camelCase name', function (string $key): void {
        $payload = T::payload();
        unset($payload[$key]);

        T::post($this, $payload)->assertStatus(422)->assertJsonValidationErrors([$key]);
    })->with(['mediaId', 'clientRequestId', 'startTime', 'endTime']);

    test('a too-long title is refused with a viewer message', function (): void {
        T::post($this, T::payload(['title' => str_repeat('a', 256)]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Clip titles can be at most 255 characters.');
    });
});

describe('bounds and host keys', function (): void {
    test('a time past what the columns hold is a viewer 422, not a 500, when the media has no duration', function (string $field): void {
        T::media(new MediaSource(id: 'vid-1', playbackUrl: 'https://cdn.example.test/v.m3u8', isLive: false, isProtected: false, duration: null));

        $payload = $field === 'startTime'
            ? ['startTime' => 99999999, 'endTime' => 99999999 + 30]
            : ['startTime' => 9999990, 'endTime' => 10000010];

        T::post($this, T::payload($payload))
            ->assertStatus(422)
            ->assertJsonPath('message', 'That point is past the end of the video.');
    })->with(['startTime', 'endTime']);

    test('a uuid-keyed host model is stored as the clippable', function (): void {
        MediaSchema::createUuidVideos();
        $video = UuidVideo::query()->create(['title' => 'Keyed by uuid']);
        T::media(new MediaSource(id: 'uuid-media', playbackUrl: 'https://cdn.example.test/u.m3u8', isLive: false, isProtected: false, duration: 600.0, model: $video));

        T::post($this, T::payload(['mediaId' => 'uuid-media']))->assertStatus(202);

        $clip = Clip::query()->sole();

        expect($clip->clippable_id)->toBe($video->id)
            ->and($clip->clippable?->is($video))->toBeTrue()
            ->and($video->clips()->pluck('id')->all())->toBe([$clip->id]);
    });
});

describe('clips disabled', function (): void {
    test('the create endpoint answers 404 with a viewer message and queues nothing', function (): void {
        config()->set('scarlett-player.clips.enabled', false);

        T::post($this, T::payload())
            ->assertStatus(404)
            ->assertJsonPath('message', "Clips aren't available right now.");

        expect(Clip::query()->count())->toBe(0);
        Bus::assertNotDispatched(RenderClip::class);
    });

    test('existing clips keep their status and play routes', function (): void {
        config()->set('scarlett-player.clips.enabled', false);
        $clip = T::clip(['user_id' => $this->user->id, 'status' => 'ready', 'visibility' => 'public', 'disk' => 'clips', 'path' => 'clips/x.mp4']);
        Storage::disk('clips')->put('clips/x.mp4', 'v');

        $this->getJson(route('scarlett.clips.show', ['uuid' => $clip->uuid]))->assertOk()->assertJson(['status' => 'ready']);
        $this->get(route('scarlett.clips.play', ['uuid' => $clip->uuid]))->assertRedirect();
    });
});

test('with clips disabled, even an invalid request is a 404, not a validation error', function (): void {
    config()->set('scarlett-player.clips.enabled', false);

    T::post($this, ['startTime' => 'nonsense'])
        ->assertStatus(404)
        ->assertJsonPath('message', "Clips aren't available right now.");
});
