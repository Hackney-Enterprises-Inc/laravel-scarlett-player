<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Facades\ScarlettPlayer;
use Hei\ScarlettPlayer\Tests\Fixtures\Provider\ArrayResolver;

beforeEach(function (): void {
    ScarlettPlayer::fake()->withMedia(
        ArrayResolver::source('video-1'),
        ArrayResolver::source('paid-1', isProtected: true),
    );
});

it('returns an unsigned URL for unprotected media', function (): void {
    expect(ScarlettPlayer::embedUrl('video-1'))->toBe('http://localhost/v/video-1');
});

it('signs the URL for protected media with the default embed.signed_ttl expiry', function (): void {
    $this->freezeSecond();

    expect(ScarlettPlayer::embedUrl('paid-1'))
        ->toMatch('#^http://localhost/v/paid-1\?expires='.now()->addDay()->getTimestamp().'&signature=[a-f0-9]{64}$#');
});

it('signs without expiry when embed.signed_ttl is null', function (): void {
    config()->set('scarlett-player.embed.signed_ttl', null);

    expect(ScarlettPlayer::embedUrl('paid-1'))->toMatch('#^http://localhost/v/paid-1\?signature=[a-f0-9]{64}$#');
});

it('prefers an explicit expiry over embed.signed_ttl', function (): void {
    $this->freezeSecond();

    expect(ScarlettPlayer::embedUrl('paid-1', now()->addMinutes(5)))
        ->toContain('?expires='.now()->addMinutes(5)->getTimestamp().'&');
});

it('signs every URL when always_sign is on', function (): void {
    config()->set('scarlett-player.embed.always_sign', true);

    expect(ScarlettPlayer::embedUrl('video-1'))->toContain('&signature=')
        ->and(ScarlettPlayer::embedUrl('video-1'))->toContain('?expires=');
});

it('signs with an expiry when one is given, even for unprotected media', function (): void {
    expect(ScarlettPlayer::embedUrl('video-1', now()->addHour()))->toMatch('#\?expires=\d+&signature=#');
});

it('accepts a MediaSource or a ScarlettMedia model as well as an id', function (): void {
    $source = ArrayResolver::source('direct');

    expect(ScarlettPlayer::embedUrl($source))->toBe('http://localhost/v/direct');
});

it('follows a changed embed.route', function (): void {
    $this->withScarlettConfig(['embed.route' => '/watch/embed/{video}']);
    ScarlettPlayer::fake()->withMedia(ArrayResolver::source('video-1'));

    expect(ScarlettPlayer::embedUrl('video-1'))->toBe('http://localhost/watch/embed/video-1');
});

it('wraps embedUrl() in the iframe snippet the share plugin uses', function (): void {
    expect(ScarlettPlayer::embedCode('video-1'))->toBe(
        '<iframe src="http://localhost/v/video-1" width="640" height="360" frameborder="0" '.
        'allow="autoplay; fullscreen; picture-in-picture" allowfullscreen loading="lazy"></iframe>'
    );
});

it('escapes the src and title and takes custom dimensions', function (): void {
    $source = new MediaSource(
        id: 'a"b',
        playbackUrl: 'https://media.example.test/x.m3u8',
        isLive: false,
        isProtected: true,
        duration: 10.0,
        title: 'Tom & "Jerry"',
    );

    $code = ScarlettPlayer::embedCode($source, width: '100%', height: 480);

    expect($code)->toContain('src="http://localhost/v/a%22b?expires=')
        ->and($code)->toContain('width="100%" height="480"')
        ->and($code)->toContain('title="Tom &amp; &quot;Jerry&quot;"');
});
