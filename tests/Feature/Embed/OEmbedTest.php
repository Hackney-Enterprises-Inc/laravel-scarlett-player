<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Facades\ScarlettPlayer;
use Hei\ScarlettPlayer\Tests\Fixtures\Provider\ArrayResolver;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->withScarlettConfig(['media.resolver' => ArrayResolver::class]);
    config()->set('app.name', 'Scarlett Test');

    ScarlettPlayer::fake()->withMedia(
        new MediaSource(
            id: 'video-1',
            playbackUrl: 'https://media.example.test/video-1.m3u8',
            isLive: false,
            isProtected: false,
            duration: 60.0,
            title: 'Fight night',
            poster: 'https://img.example.test/fight.jpg',
        ),
        ArrayResolver::source('paid-1', isProtected: true),
    );
});

function oembed(string $url, array $query = []): string
{
    return route('scarlett.oembed.show', ['url' => $url, ...$query]);
}

it('registers the oEmbed endpoint under the prefix with the api group', function (): void {
    $route = Route::getRoutes()->getByName('scarlett.oembed.show');

    expect($route->uri())->toBe('api/scarlett/oembed')
        ->and($route->gatherMiddleware())->toBe(['api']);
});

it('answers a video oEmbed whose html is embedCode()', function (): void {
    $this->getJson(oembed('http://localhost/v/video-1'))
        ->assertOk()
        ->assertExactJson([
            'version' => '1.0',
            'type' => 'video',
            'provider_name' => 'Scarlett Test',
            'provider_url' => 'http://localhost',
            'width' => 640,
            'height' => 360,
            'title' => 'Fight night',
            'thumbnail_url' => 'https://img.example.test/fight.jpg',
            'html' => ScarlettPlayer::embedCode('video-1'),
        ]);
});

it('accepts a share-built embed URL with presentation parameters', function (): void {
    $this->getJson(oembed('http://localhost/v/video-1?startTime=30&shareUrl='.urlencode('https://host.test/w')))
        ->assertOk()
        ->assertJsonPath('html', ScarlettPlayer::embedCode('video-1'));
});

it('scales to maxwidth and maxheight', function (): void {
    $this->getJson(oembed('http://localhost/v/video-1', ['maxwidth' => 320]))
        ->assertJsonPath('width', 320)->assertJsonPath('height', 180);

    $this->getJson(oembed('http://localhost/v/video-1', ['maxheight' => 180]))
        ->assertJsonPath('width', 320)->assertJsonPath('height', 180);
});

it('answers 404 for a URL that is not this app\'s embed page, or unknown media', function (string $url): void {
    $this->getJson(oembed($url))->assertNotFound();
})->with([
    'another host' => 'https://elsewhere.test/v/video-1',
    'another route' => 'http://localhost/api/scarlett/oembed',
    'unknown media' => 'http://localhost/v/nope',
    'not a URL' => 'nothing',
]);

it('answers 404 without a url and 501 for a non-json format', function (): void {
    $this->getJson(route('scarlett.oembed.show'))->assertNotFound();
    $this->getJson(oembed('http://localhost/v/video-1', ['format' => 'xml']))->assertStatus(501);
});

it('answers 401 for protected media without a valid signature', function (string $url): void {
    $this->getJson(oembed($url))->assertUnauthorized();
})->with([
    'unsigned' => 'http://localhost/v/paid-1',
    'forged' => 'http://localhost/v/paid-1?signature=forged',
]);

it('keeps the expiry of a signed URL in the snippet it returns', function (): void {
    $this->freezeSecond();
    $url = ScarlettPlayer::embedUrl('paid-1', now()->addMinutes(10));

    $html = $this->getJson(oembed($url.'&startTime=5'))->assertOk()->json('html');

    parse_str((string) parse_url(html_entity_decode(explode('"', $html)[1]), PHP_URL_QUERY), $query);

    expect($query['expires'])->toBe((string) now()->addMinutes(10)->getTimestamp())
        ->and($html)->toBe(ScarlettPlayer::embedCode('paid-1', now()->addMinutes(10)));
});

it('signs the snippet for protected media given a signed URL without expiry', function (): void {
    $this->getJson(oembed(ScarlettPlayer::embedUrl('paid-1')))
        ->assertOk()
        ->assertJsonPath('html', ScarlettPlayer::embedCode('paid-1'));
});
