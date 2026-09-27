<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Contracts\ResolvesMedia;
use Hei\ScarlettPlayer\Exceptions\MediaNotFoundException;
use Hei\ScarlettPlayer\Facades\ScarlettPlayer;
use Hei\ScarlettPlayer\Tests\Fixtures\Provider\ArrayResolver;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(function (): void {
    $this->withScarlettConfig(['media.resolver' => ArrayResolver::class]);

    $this->resolver = app(ResolvesMedia::class);
    $this->fake = ScarlettPlayer::fake();
});

it('answers registered media without calling the resolver', function (): void {
    $source = ArrayResolver::source('video-1');

    expect($this->fake->withMedia($source)->resolve('video-1'))->toBe($source)
        ->and($this->resolver->calls)->toBe([]);
});

it('falls back to the bound resolver for other ids', function (): void {
    $this->resolver->add(ArrayResolver::source('video-2'));

    expect($this->fake->resolve('video-2')->id)->toBe('video-2')
        ->and($this->resolver->calls)->toBe(['video-2']);
});

it('records every resolve, including ids that were not found', function (): void {
    $this->fake->withMedia(ArrayResolver::source('video-1'));
    $this->fake->resolve('video-1');

    expect(fn () => $this->fake->resolve('missing'))->toThrow(MediaNotFoundException::class)
        ->and($this->fake->resolved())->toBe(['video-1', 'missing']);
});

it('builds the player config from registered media', function (): void {
    $this->fake->withMedia(ArrayResolver::source('video-1'));

    expect(ScarlettPlayer::for('video-1')->media()->id)->toBe('video-1');

    $this->fake->assertResolved('video-1');
});

it('asserts what was and was not resolved', function (): void {
    $this->fake->assertNothingResolved();

    expect(fn () => $this->fake->assertResolved('video-1'))->toThrow(AssertionFailedError::class);

    $this->fake->withMedia(ArrayResolver::source('video-1'))->resolve('video-1');

    expect($this->fake->assertResolved('video-1'))->toBe($this->fake)
        ->and(fn () => $this->fake->assertNothingResolved())->toThrow(AssertionFailedError::class);
});

it('starts with empty ledgers that fail their assertions', function (): void {
    expect($this->fake->recordedBeacons())->toBe([])
        ->and($this->fake->recordedClips())->toBe([])
        ->and(fn () => $this->fake->assertBeaconRecorded())->toThrow(AssertionFailedError::class, 'No Scarlett beacon was recorded.')
        ->and(fn () => $this->fake->assertClipRequested())->toThrow(AssertionFailedError::class, 'No Scarlett clip was requested.');
});

it('records beacons and matches them with a callback', function (): void {
    $this->fake->recordBeacon(['event' => 'viewStart', 'viewId' => 'v1']);

    expect($this->fake->recordedBeacons())->toBe([['event' => 'viewStart', 'viewId' => 'v1']])
        ->and($this->fake->assertBeaconRecorded())->toBe($this->fake)
        ->and($this->fake->assertBeaconRecorded(fn (array $b): bool => $b['event'] === 'viewStart'))->toBe($this->fake)
        ->and(fn () => $this->fake->assertBeaconRecorded(fn (array $b): bool => $b['event'] === 'viewEnd'))
        ->toThrow(AssertionFailedError::class, 'No recorded Scarlett beacon matched the callback.');
});

it('records clip requests and matches them with a callback', function (): void {
    $this->fake->recordClip(['mediaId' => 'video-1', 'startTime' => 9, 'endTime' => 39]);

    expect($this->fake->recordedClips())->toHaveCount(1)
        ->and($this->fake->assertClipRequested())->toBe($this->fake)
        ->and($this->fake->assertClipRequested(fn (array $c): bool => $c['mediaId'] === 'video-1'))->toBe($this->fake)
        ->and(fn () => $this->fake->assertClipRequested(fn (array $c): bool => $c['mediaId'] === 'other'))
        ->toThrow(AssertionFailedError::class, 'No requested Scarlett clip matched the callback.');
});
