<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Clips\ClipUrlIssuer;
use Hei\ScarlettPlayer\Models\Clip;
use Hei\ScarlettPlayer\Tests\Feature\Clips\Support\ClipTestSupport as T;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    T::createUsers();
    $this->usesMigrations();
    T::fakeDisk();
    $this->owner = T::user('owner');
});

function readyClip(array $attributes = []): Clip
{
    $clip = T::clip(array_merge(['status' => 'ready', 'disk' => 'clips'], $attributes));
    $clip->forceFill(['path' => "clips/{$clip->uuid}.mp4"])->save();
    Storage::disk('clips')->put($clip->path, 'video', ['visibility' => 'private']);

    return $clip->refresh();
}

describe('status', function (): void {
    test('ready + pending_review: no playbackUrl, and a previewUrl only for the submitter', function (): void {
        $clip = readyClip(['user_id' => $this->owner->id]);

        $this->actingAs($this->owner)->getJson(route('scarlett.clips.show', ['uuid' => $clip->uuid]))
            ->assertOk()
            ->assertJson(['uuid' => $clip->uuid, 'status' => 'ready', 'visibility' => 'pending_review', 'playbackUrl' => null])
            ->assertJsonPath('previewUrl', fn (?string $url): bool => $url !== null && str_contains($url, '/preview'));
    });

    test('another viewer cannot read a clip that is not public', function (): void {
        $clip = readyClip(['user_id' => $this->owner->id]);

        $this->actingAs(T::user('other'))->getJson(route('scarlett.clips.show', ['uuid' => $clip->uuid]))->assertForbidden();
    });

    test('a public ready clip shows a playbackUrl to any viewer and no previewUrl', function (): void {
        $clip = readyClip(['user_id' => $this->owner->id, 'visibility' => 'public']);

        $this->actingAs(T::user('other'))->getJson(route('scarlett.clips.show', ['uuid' => $clip->uuid]))
            ->assertOk()
            ->assertJson([
                'playbackUrl' => route('scarlett.clips.play', ['uuid' => $clip->uuid]),
                'previewUrl' => null,
            ]);
    });

    test('a pending clip has neither URL', function (): void {
        $clip = T::clip(['user_id' => $this->owner->id]);

        $this->actingAs($this->owner)->getJson(route('scarlett.clips.show', ['uuid' => $clip->uuid]))
            ->assertOk()
            ->assertJson(['status' => 'pending', 'playbackUrl' => null, 'previewUrl' => null]);
    });

    test('a failed clip reports its reason', function (): void {
        $clip = T::clip(['user_id' => $this->owner->id, 'status' => 'failed', 'failure_reason' => 'render_exceeds_bounds']);

        $this->actingAs($this->owner)->getJson(route('scarlett.clips.show', ['uuid' => $clip->uuid]))
            ->assertOk()
            ->assertJson(['status' => 'failed', 'failureReason' => 'render_exceeds_bounds']);
    });

    test('a guest gets 401 unless allow_guests is on', function (): void {
        $clip = readyClip(['visibility' => 'public']);

        $this->get(route('scarlett.clips.show', ['uuid' => $clip->uuid]))->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');

        config()->set('scarlett-player.clips.allow_guests', true);

        $this->get(route('scarlett.clips.show', ['uuid' => $clip->uuid]))
            ->assertOk()
            ->assertJson(['playbackUrl' => route('scarlett.clips.play', ['uuid' => $clip->uuid])]);
    });

    test('an unknown uuid is a 404', function (): void {
        $this->actingAs($this->owner)->getJson(route('scarlett.clips.show', ['uuid' => 'nope']))->assertNotFound();
    });
});

describe('play: signed-redirect delivery', function (): void {
    test('/play is a 404 until the clip is approved, then a 302 to a short-lived object URL', function (): void {
        $clip = readyClip(['user_id' => $this->owner->id]);
        $play = route('scarlett.clips.play', ['uuid' => $clip->uuid]);

        $this->get($play)->assertNotFound();

        $this->travelTo(now()->startOfSecond());
        $clip->approve();

        $response = $this->get($play)->assertRedirect();

        expect($response->headers->get('Location'))
            ->toStartWith("https://objects.example.test/clips/{$clip->uuid}.mp4?expires=")
            ->toEndWith((string) now()->addSeconds(300)->getTimestamp())
            ->and(Storage::disk('clips')->getVisibility($clip->path))->toBe('private');
    });

    test('play_ttl sets the redirect lifetime', function (): void {
        config()->set('scarlett-player.clips.play_ttl', 45);
        $this->travelTo(now()->startOfSecond());
        $clip = readyClip(['visibility' => 'public']);

        $location = $this->get(route('scarlett.clips.play', ['uuid' => $clip->uuid]))->headers->get('Location');

        expect($location)->toEndWith((string) now()->addSeconds(45)->getTimestamp());
    });

    test('/play needs no sign-in once public', function (): void {
        $clip = readyClip(['visibility' => 'public']);

        $this->get(route('scarlett.clips.play', ['uuid' => $clip->uuid]))->assertRedirect();
    });

    test('reject after render: /play is a 404 again and the object is still private', function (): void {
        $clip = readyClip(['visibility' => 'public']);
        $play = route('scarlett.clips.play', ['uuid' => $clip->uuid]);

        $this->get($play)->assertRedirect();

        $clip->reject();

        $this->get($play)->assertNotFound();
        Storage::disk('clips')->assertExists($clip->path);
        expect(Storage::disk('clips')->getVisibility((string) $clip->path))->toBe('private');
    });

    test('a pending or failed clip never plays', function (string $status): void {
        $clip = T::clip(['status' => $status, 'visibility' => 'public']);

        $this->get(route('scarlett.clips.play', ['uuid' => $clip->uuid]))->assertNotFound();
    })->with(['pending', 'processing', 'failed']);

    test('an unknown uuid is a 404', function (): void {
        $this->get(route('scarlett.clips.play', ['uuid' => 'nope']))->assertNotFound();
    });
});

describe('play: disk-public delivery', function (): void {
    beforeEach(function (): void {
        config()->set('scarlett-player.clips.public_delivery', 'disk-public');
    });

    test('approve flips the object public and the playback URL is the object URL', function (): void {
        $clip = readyClip(['user_id' => $this->owner->id]);

        expect(Storage::disk('clips')->getVisibility($clip->path))->toBe('private');

        $clip->approve();

        expect(Storage::disk('clips')->getVisibility($clip->path))->toBe('public')
            ->and(app(ClipUrlIssuer::class)->playbackUrl($clip))->toBe(Storage::disk('clips')->url($clip->path));
        $this->get(route('scarlett.clips.play', ['uuid' => $clip->uuid]))->assertRedirect(Storage::disk('clips')->url($clip->path));
    });

    test('reject after approve makes the object private again', function (): void {
        $clip = readyClip();
        $clip->approve();
        $clip->reject();

        expect(Storage::disk('clips')->getVisibility((string) $clip->path))->toBe('private');
        $this->get(route('scarlett.clips.play', ['uuid' => $clip->uuid]))->assertNotFound();
    });
});

describe('preview', function (): void {
    test('the submitter follows the preview URL to a short-lived object URL', function (): void {
        $clip = readyClip(['user_id' => $this->owner->id]);
        $url = (string) app(ClipUrlIssuer::class)->previewUrl($clip, $this->owner);

        $location = $this->get($url)->assertRedirect()->headers->get('Location');

        expect($location)->toStartWith("https://objects.example.test/clips/{$clip->uuid}.mp4?expires=");
    });

    test('the preview URL expires after preview_ttl', function (): void {
        config()->set('scarlett-player.clips.preview_ttl', 60);
        $clip = readyClip(['user_id' => $this->owner->id]);
        $url = (string) app(ClipUrlIssuer::class)->previewUrl($clip, $this->owner);

        $this->travel(61)->seconds();

        $this->get($url)->assertForbidden();
    });

    test('an unsigned or tampered preview URL is refused', function (): void {
        $clip = readyClip(['user_id' => $this->owner->id]);
        $other = T::user('other');
        $url = (string) app(ClipUrlIssuer::class)->previewUrl($clip, $this->owner);

        $this->get(route('scarlett.clips.preview', ['uuid' => $clip->uuid, 'viewer' => $this->owner->id]))->assertForbidden();
        $this->get(str_replace('viewer='.$this->owner->id, 'viewer='.$other->id, $url))->assertForbidden();
    });

    test('the policy is re-checked: a clip approved since the URL was issued no longer previews for the submitter', function (): void {
        $clip = readyClip(['user_id' => $this->owner->id]);
        $url = (string) app(ClipUrlIssuer::class)->previewUrl($clip, $this->owner);

        $clip->approve();

        $this->get($url)->assertForbidden();
    });

    test('a URL signed for a viewer who fails the policy is refused', function (): void {
        $clip = readyClip(['user_id' => $this->owner->id]);
        $stranger = T::user('stranger');
        $url = URL::temporarySignedRoute('scarlett.clips.preview', now()->addMinute(), ['uuid' => $clip->uuid, 'viewer' => $stranger->id]);

        $this->get($url)->assertForbidden();
    });

    test('previewUrl() is null for guests, strangers, public clips and unrendered clips', function (): void {
        $issuer = app(ClipUrlIssuer::class);

        expect($issuer->previewUrl(readyClip(['user_id' => $this->owner->id]), null))->toBeNull()
            ->and($issuer->previewUrl(readyClip(['user_id' => $this->owner->id]), T::user('x')))->toBeNull()
            ->and($issuer->previewUrl(readyClip(['user_id' => $this->owner->id, 'visibility' => 'public']), $this->owner))->toBeNull()
            ->and($issuer->previewUrl(T::clip(['user_id' => $this->owner->id]), $this->owner))->toBeNull();
    });
});

test('rendered assets are written private', function (): void {
    $clip = readyClip();

    expect(Storage::disk('clips')->getVisibility((string) $clip->path))->toBe('private');
});
