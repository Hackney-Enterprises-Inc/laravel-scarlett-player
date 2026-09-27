<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Enums\ClipStatus;
use Hei\ScarlettPlayer\Enums\ClipVisibility;
use Hei\ScarlettPlayer\Models\Clip;
use Hei\ScarlettPlayer\Tests\Feature\Clips\Support\ClipTestSupport as T;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    T::createUsers();
    $this->usesMigrations();
});

test('the migration creates scarlett_clips with every planned column', function (): void {
    expect(Schema::hasColumns('scarlett_clips', [
        'id', 'uuid', 'clippable_type', 'clippable_id', 'media_id', 'client_request_id', 'user_id',
        'title', 'start_seconds', 'end_seconds', 'duration_seconds', 'captured_at', 'status',
        'visibility', 'dispatched_at', 'processing_started_at', 'rendered_at', 'verified_at',
        'attempts', 'disk', 'path', 'size_bytes', 'failure_reason', 'approved_at', 'approved_by',
        'rejected_at', 'rejected_by', 'created_at', 'updated_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('scarlett_clips', ['start_date', 'end_date']))->toBeFalse();
});

test('client_request_id is unique', function (): void {
    T::clip(['client_request_id' => 'same']);
    T::clip(['client_request_id' => 'same']);
})->throws(UniqueConstraintViolationException::class);

test('uuid is unique', function (): void {
    T::clip(['uuid' => 'aaaaaaaa-0000-4000-8000-000000000001']);
    T::clip(['uuid' => 'aaaaaaaa-0000-4000-8000-000000000001']);
})->throws(UniqueConstraintViolationException::class);

test('a new clip is pending, pending_review, with no attempts', function (): void {
    $clip = Clip::query()->create([
        'uuid' => 'aaaaaaaa-0000-4000-8000-000000000002',
        'media_id' => 'vid-1',
        'client_request_id' => 'req-2',
        'start_seconds' => 1,
        'end_seconds' => 11,
        'duration_seconds' => 10,
    ])->fresh();

    expect($clip?->status)->toBe(ClipStatus::Pending)
        ->and($clip?->visibility)->toBe(ClipVisibility::PendingReview)
        ->and($clip?->attempts)->toBe(0);
});

test('seconds cast to floats and dates to Carbon', function (): void {
    $clip = T::clip(['captured_at' => '2026-09-07 18:04:11'])->fresh();

    expect($clip?->start_seconds)->toBe(120.5)
        ->and($clip?->end_seconds)->toBe(150.5)
        ->and($clip?->duration_seconds)->toBe(30.0)
        ->and($clip?->captured_at?->toDateTimeString())->toBe('2026-09-07 18:04:11');
});

test('the route key is the uuid', function (): void {
    $clip = T::clip();

    expect($clip->getRouteKeyName())->toBe('uuid')
        ->and($clip->getRouteKey())->toBe($clip->uuid);
});

test('user() belongs to the app user model', function (): void {
    $user = T::user();
    $clip = T::clip(['user_id' => $user->id]);

    expect($clip->user?->is($user))->toBeTrue();
});

test('isOwnedBy() matches the submitter and never a guest', function (): void {
    $owner = T::user('owner');
    $other = T::user('other');
    $clip = T::clip(['user_id' => $owner->id]);
    $anonymous = T::clip(['user_id' => null]);

    expect($clip->isOwnedBy($owner))->toBeTrue()
        ->and($clip->isOwnedBy($other))->toBeFalse()
        ->and($clip->isOwnedBy(null))->toBeFalse()
        ->and($anonymous->isOwnedBy(null))->toBeFalse();
});

test('isPubliclyPlayable() needs ready, public and a stored asset', function (array $attributes, bool $expected): void {
    expect(T::clip($attributes)->isPubliclyPlayable())->toBe($expected);
})->with([
    'ready public stored' => [['status' => 'ready', 'visibility' => 'public', 'disk' => 'clips', 'path' => 'clips/a.mp4'], true],
    'ready pending review' => [['status' => 'ready', 'visibility' => 'pending_review', 'disk' => 'clips', 'path' => 'clips/a.mp4'], false],
    'ready hidden' => [['status' => 'ready', 'visibility' => 'hidden', 'disk' => 'clips', 'path' => 'clips/a.mp4'], false],
    'public but pending' => [['status' => 'pending', 'visibility' => 'public'], false],
    'ready public without asset' => [['status' => 'ready', 'visibility' => 'public'], false],
]);

test('withStatus() scopes by status', function (): void {
    T::clip(['status' => 'ready']);
    T::clip(['status' => 'pending']);

    expect(Clip::query()->withStatus(ClipStatus::Ready)->count())->toBe(1);
});

test('the status enum names the terminal and renderable states', function (): void {
    expect(ClipStatus::renderable())->toBe([ClipStatus::Pending, ClipStatus::Processing])
        ->and(ClipStatus::Ready->isTerminal())->toBeTrue()
        ->and(ClipStatus::Failed->isTerminal())->toBeTrue()
        ->and(ClipStatus::Rejected->isTerminal())->toBeTrue()
        ->and(ClipStatus::Pending->isTerminal())->toBeFalse()
        ->and(ClipStatus::Processing->isTerminal())->toBeFalse();
});
