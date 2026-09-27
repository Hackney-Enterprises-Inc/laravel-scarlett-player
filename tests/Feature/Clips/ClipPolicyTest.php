<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Models\Clip;
use Hei\ScarlettPlayer\Policies\ClipPolicy;
use Hei\ScarlettPlayer\Tests\Feature\Clips\Support\ClipTestSupport as T;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;

beforeEach(function (): void {
    T::createUsers();
    $this->usesMigrations();
});

describe('hostPolicyPublished', function (): void {
    test('the package default is registered and does not count as published', function (): void {
        $gate = app(Gate::class);

        expect($gate->getPolicyFor(Clip::class))->toBeInstanceOf(ClipPolicy::class)
            ->and(ClipPolicy::hostPolicyPublished($gate))->toBeFalse();
    });

    test('a host subclass that writes create() counts as published', function (): void {
        $gate = app(Gate::class);
        $gate->policy(Clip::class, CreatingClipPolicy::class);

        expect(ClipPolicy::hostPolicyPublished($gate))->toBeTrue();
    });

    test('a host subclass that inherits create() does not count as published', function (): void {
        $gate = app(Gate::class);
        $gate->policy(Clip::class, HostClipPolicy::class);

        expect(ClipPolicy::hostPolicyPublished($gate))->toBeFalse();
    });

    test('an unrelated host policy with a create() counts as published', function (): void {
        $gate = app(Gate::class);
        $gate->policy(Clip::class, UnrelatedClipPolicy::class);

        expect(ClipPolicy::hostPolicyPublished($gate))->toBeTrue();
    });

    test('a host policy without create() does not count as published', function (): void {
        $gate = app(Gate::class);
        $gate->policy(Clip::class, NoCreateClipPolicy::class);

        expect(ClipPolicy::hostPolicyPublished($gate))->toBeFalse();
    });
});

describe('create', function (): void {
    test('a signed-in viewer may clip unprotected media', function (): void {
        expect(app(Gate::class)->forUser(T::user())->allows('create', [Clip::class, T::source()]))->toBeTrue();
    });

    test('a guest may not clip', function (): void {
        expect(app(Gate::class)->forUser(null)->allows('create', [Clip::class, T::source()]))->toBeFalse();
    });

    test('protected media is denied while no host policy is published', function (): void {
        expect(app(Gate::class)->forUser(T::user())->allows('create', [Clip::class, T::source(isProtected: true)]))->toBeFalse();
    });

    test('a subclass inheriting create() still denies protected media', function (): void {
        $gate = app(Gate::class);
        $gate->policy(Clip::class, HostClipPolicy::class);

        expect($gate->forUser(T::user())->allows('create', [Clip::class, T::source(isProtected: true)]))->toBeFalse();
    });

    test('a subclass overriding create() decides protected media', function (): void {
        $gate = app(Gate::class);
        $gate->policy(Clip::class, CreatingClipPolicy::class);

        expect($gate->forUser(T::user())->allows('create', [Clip::class, T::source(isProtected: true)]))->toBeTrue()
            ->and($gate->forUser(null)->allows('create', [Clip::class, T::source(isProtected: true)]))->toBeFalse();
    });
});

describe('view, preview, moderate', function (): void {
    test('view: the submitter, anyone for a public ready clip, guests only with allow_guests', function (): void {
        $owner = T::user('owner');
        $other = T::user('other');
        $pending = T::clip(['user_id' => $owner->id, 'status' => 'ready', 'disk' => 'clips', 'path' => 'clips/a.mp4']);
        $public = T::clip(['user_id' => $owner->id, 'status' => 'ready', 'visibility' => 'public', 'disk' => 'clips', 'path' => 'clips/b.mp4']);
        $gate = app(Gate::class);

        expect($gate->forUser($owner)->allows('view', $pending))->toBeTrue()
            ->and($gate->forUser($other)->allows('view', $pending))->toBeFalse()
            ->and($gate->forUser($other)->allows('view', $public))->toBeTrue()
            ->and($gate->forUser(null)->allows('view', $public))->toBeFalse();

        config()->set('scarlett-player.clips.allow_guests', true);

        expect($gate->forUser(null)->allows('view', $public))->toBeTrue()
            ->and($gate->forUser(null)->allows('view', $pending))->toBeFalse()
            ->and($gate->forUser(null)->allows('view', T::clip(['status' => 'pending'])))->toBeFalse()
            ->and($gate->forUser(null)->allows('view', T::clip(['status' => 'ready', 'visibility' => 'hidden', 'disk' => 'clips', 'path' => 'clips/c.mp4'])))->toBeFalse()
            ->and($gate->forUser($owner)->allows('view', $pending))->toBeTrue();
    });

    test('preview: the submitter while pending review, never a guest or another viewer', function (): void {
        $owner = T::user('owner');
        $clip = T::clip(['user_id' => $owner->id, 'status' => 'ready']);
        $hidden = T::clip(['user_id' => $owner->id, 'status' => 'ready', 'visibility' => 'hidden']);
        $gate = app(Gate::class);

        expect($gate->forUser($owner)->allows('preview', $clip))->toBeTrue()
            ->and($gate->forUser($owner)->allows('preview', $hidden))->toBeFalse()
            ->and($gate->forUser(T::user('other'))->allows('preview', $clip))->toBeFalse()
            ->and($gate->forUser(null)->allows('preview', $clip))->toBeFalse();
    });

    test('moderate: nobody by default; a host moderator previews and views anything', function (): void {
        $clip = T::clip(['status' => 'ready', 'visibility' => 'hidden']);
        $moderator = T::user('moderator');
        $gate = app(Gate::class);

        expect($gate->forUser($moderator)->allows('moderate', $clip))->toBeFalse();

        $gate->policy(Clip::class, HostClipPolicy::class);

        expect($gate->forUser($moderator)->allows('moderate', $clip))->toBeTrue()
            ->and($gate->forUser($moderator)->allows('preview', $clip))->toBeTrue()
            ->and($gate->forUser($moderator)->allows('view', $clip))->toBeTrue();
    });
});

class HostClipPolicy extends ClipPolicy
{
    public function moderate(?Authenticatable $user, ?Clip $clip = null): bool
    {
        return $user !== null && $user->getAttribute('name') === 'moderator';
    }
}

class CreatingClipPolicy extends ClipPolicy
{
    public function create(?Authenticatable $user, MediaSource $media): bool
    {
        return $user !== null;
    }
}

class NoCreateClipPolicy
{
    public function view(): bool
    {
        return true;
    }
}

class UnrelatedClipPolicy
{
    public function create(): bool
    {
        return false;
    }
}
