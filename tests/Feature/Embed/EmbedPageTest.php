<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Facades\ScarlettPlayer;
use Hei\ScarlettPlayer\Http\Middleware\ValidateEmbedSignature;
use Hei\ScarlettPlayer\Tests\Fixtures\Provider\ArrayResolver;
use Hei\ScarlettPlayer\Tests\TestCase;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->withScarlettConfig(['media.resolver' => ArrayResolver::class]);
    config()->set('scarlett-player.player.cdn_url', 'https://cdn.example.test/scarlett-player');

    ScarlettPlayer::fake()->withMedia(
        ArrayResolver::source('video-1'),
        ArrayResolver::source('paid-1', isProtected: true),
        new MediaSource(
            id: 'branded',
            playbackUrl: 'https://media.example.test/branded.m3u8',
            isLive: false,
            isProtected: false,
            duration: 60.0,
            title: 'Fight night',
            poster: 'https://img.example.test/fight.jpg',
            meta: ['brand_color' => '#e50914', 'brand_text_color' => '#ffffff'],
        ),
        new MediaSource(
            id: 'live-1',
            playbackUrl: 'https://media.example.test/live.m3u8',
            isLive: true,
            isProtected: false,
            duration: null,
        ),
    );
});

it('registers the embed page at embed.route outside the prefix, with the signature middleware', function (): void {
    $route = Route::getRoutes()->getByName('scarlett.embed.show');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('v/{uuid}')
        ->and($route->gatherMiddleware())->toBe(['web', ValidateEmbedSignature::class]);
});

it('serves unprotected media without a signature, in the embed bundle', function (): void {
    $response = $this->get('/v/video-1');

    $response->assertOk()
        ->assertSee('data-src="https://media.example.test/video-1.m3u8"', false)
        ->assertSee('<script src="https://cdn.example.test/scarlett-player/v'.config('scarlett-player.player.player_version').'/embed.js" type="module"></script>', false)
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Content-Security-Policy', 'frame-ancestors *');
});

it('carries Open Graph tags and the brand colour from the media meta', function (): void {
    $this->get('/v/branded')
        ->assertOk()
        ->assertSee('<meta property="og:title" content="Fight night">', false)
        ->assertSee('<meta property="og:image" content="https://img.example.test/fight.jpg">', false)
        ->assertSee('<meta property="og:type" content="video.other">', false)
        ->assertSee('<meta name="theme-color" content="#e50914">', false)
        ->assertSee('data-brand-color="#e50914"', false)
        ->assertSee('data-brand-text-color="#ffffff"', false);
});

it('builds frame-ancestors from embed.allowed_domains, subdomains included', function (): void {
    config()->set('scarlett-player.embed.allowed_domains', ['host.test', '.partner.example']);

    $this->get('/v/video-1')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'self' host.test *.host.test partner.example *.partner.example");
});

it('honours autoplay, muted and a valid startTime from the query', function (): void {
    $this->get('/v/video-1?autoplay=1&startTime=42')
        ->assertOk()
        ->assertSee('data-autoplay="true"', false)
        ->assertSee('data-muted="true"', false)
        ->assertSee('data-start-time="42"', false);
});

it('drops a startTime that is negative or not a number', function (string $value): void {
    $this->get('/v/video-1?startTime='.urlencode($value))
        ->assertOk()
        ->assertDontSee('data-start-time', false);
})->with(['-5', 'abc', '1e999x']);

it('ignores startTime on live media', function (): void {
    $this->get('/v/live-1?startTime=30')->assertOk()->assertDontSee('data-start-time', false);
});

it('drops a shareUrl that is not an absolute http(s) URL, even with no allowed domains', function (string $value): void {
    $this->get('/v/video-1?shareUrl='.urlencode($value))
        ->assertOk()
        ->assertDontSee('data-share-url', false)
        ->assertDontSee(e($value), false);
})->with(['javascript:alert(1)', '/relative/page', 'ftp://host.test/file']);

it('adds share with this page as the embed base when shareUrl is valid', function (): void {
    $this->get('/v/video-1?shareUrl='.urlencode('https://anywhere.test/watch'))
        ->assertOk()
        ->assertSee('data-share-url="https://anywhere.test/watch"', false)
        ->assertSee('data-embed-base-url="http://localhost/v/video-1"', false);
});

it('answers 404 for unknown media', function (): void {
    $this->get('/v/nope')->assertNotFound();
});

it('requires a signature for protected media', function (): void {
    $this->get('/v/paid-1')->assertForbidden();
});

it('requires a signature for every embed when always_sign is on', function (): void {
    config()->set('scarlett-player.embed.always_sign', true);

    $this->get('/v/video-1')->assertForbidden();
    $this->get(ScarlettPlayer::embedUrl('video-1'))->assertOk();
});

it('checks a signature that is present even on unprotected media', function (): void {
    $this->get('/v/video-1?signature=forged')->assertForbidden();
});

it('rejects a signed URL with a signed parameter added', function (): void {
    $this->get(ScarlettPlayer::embedUrl('paid-1').'&poster=https://evil.example.net/x.jpg')->assertForbidden();
});

it('rejects an expired signed URL and accepts it before expiry', function (): void {
    $url = ScarlettPlayer::embedUrl('paid-1', now()->addMinutes(5));

    $this->get($url)->assertOk();

    $this->travel(6)->minutes();

    $this->get($url)->assertForbidden();
});

it('drops a shareUrl that parse_url and a browser would read differently', function (string $value): void {
    config()->set('scarlett-player.embed.allowed_domains', ['host.test']);

    $this->get('/v/video-1?shareUrl='.rawurlencode($value))
        ->assertOk()
        ->assertDontSee('data-share-url', false)
        ->assertDontSee('evil.test', false);
})->with([
    'backslash before userinfo' => 'https://evil.test\@host.test/',
    'literal backslash-t' => 'https://evil.test\t.host.test/',
    'real tab' => "https://evil.test\t.host.test/",
    'space' => 'https://evil.test .host.test/',
    'control character' => "https://evil.test\x01.host.test/",
]);

it('drops a shareUrl carrying userinfo, even on an allowed host', function (string $value): void {
    config()->set('scarlett-player.embed.allowed_domains', ['host.test']);

    $this->get('/v/video-1?shareUrl='.rawurlencode($value))
        ->assertOk()
        ->assertDontSee('data-share-url', false);
})->with([
    'user' => 'https://evil@host.test/watch',
    'user and password' => 'https://evil:pw@host.test/watch',
]);

it('accepts a startTime of plain seconds only', function (string $value, ?string $expected): void {
    $response = $this->get('/v/video-1?startTime='.rawurlencode($value))->assertOk();

    $expected === null
        ? $response->assertDontSee('data-start-time', false)
        : $response->assertSee('data-start-time="'.$expected.'"', false);
})->with([
    'whole' => ['42', '42'],
    'decimal' => ['42.5', '42.5'],
    'sign' => ['+5', null],
    'exponent' => ['1e9', null],
    'hex' => ['0x1A', null],
    'trailing dot' => ['5.', null],
]);

it('still 403s an expired signature that carries a valid startTime and shareUrl', function (): void {
    $url = ScarlettPlayer::embedUrl('paid-1', now()->addMinutes(5));

    $this->travel(6)->minutes();

    $this->get($url.'&startTime=30&shareUrl='.rawurlencode('https://host.test/watch'))->assertForbidden();
});

it('never lets expires or signature into unsigned_params', function (): void {
    config()->set('scarlett-player.embed.unsigned_params', ['startTime', 'shareUrl', 'expires', 'signature']);

    $url = ScarlettPlayer::embedUrl('paid-1', now()->addMinutes(5));
    $stripped = preg_replace('/expires=\d+&/', '', $url);

    expect($stripped)->not->toContain('expires=');

    $this->get($stripped)->assertForbidden();
    $this->get($url)->assertOk();
});

it('normalises allowed_domains into bare hosts for the CSP and the shareUrl check', function (): void {
    config()->set('scarlett-player.embed.allowed_domains', ['https://Host.test:443/path', '*.partner.example']);

    $this->get('/v/video-1?shareUrl='.rawurlencode('https://www.partner.example/w'))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'self' host.test *.host.test partner.example *.partner.example")
        ->assertSee('data-share-url="https://www.partner.example/w"', false);
});

it('beacons from the embed page when the ingest and its key are configured', function (): void {
    $this->get('/v/video-1')
        ->assertOk()
        ->assertSee('data-analytics-beacon-url="'.route('scarlett.beacons.store').'"', false)
        ->assertSee('data-analytics-video-id="video-1"', false)
        ->assertSee('data-analytics-api-key="'.TestCase::BEACON_KEY.'"', false);
});

it('renders the embed page with a configured heartbeat interval, which embed mode leaves out', function (): void {
    config()->set('scarlett-player.player.heartbeat_interval', 5);

    $response = $this->get('/v/video-1')->assertOk()->assertSee('data-analytics-video-id="video-1"', false);

    // The page builds through withAnalytics() in embed mode, where the bundle has no
    // attribute for the interval: nothing is emitted and the player default applies.
    expect($response->getContent())->not->toContain('heartbeat');
});

it('keeps clips off the embed page, which has no session or csrf meta tag of the host', function (): void {
    $content = (string) $this->get('/v/video-1')->assertOk()->getContent();

    expect($content)->not->toContain('data-clips-')
        ->and($content)->not->toContain('embed.addon.');
});

it('leaves analytics off the embed page without a key, or with beacons off', function (string $key, mixed $value): void {
    config()->set("scarlett-player.{$key}", $value);

    $this->get('/v/video-1')->assertOk()->assertDontSee('data-analytics-', false);
})->with([
    'no key' => ['beacons.key', ''],
    'beacons disabled' => ['beacons.enabled', false],
]);

/**
 * @return array<string, array{string, bool}>
 */
function shareUrlPayloads(): array
{
    $rows = [];

    foreach (file(__DIR__.'/../../Fixtures/embed/share-url-payloads.tsv', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        [$verdict, $payload] = explode("\t", $line, 2);
        $rows[$payload] = [$payload, $verdict === 'keep'];
    }

    return $rows;
}

it('keeps only plain-ASCII shareUrls on an allowed domain from the fuzzed payload set', function (string $payload, bool $keep): void {
    config()->set('scarlett-player.embed.allowed_domains', ['allowed.test']);

    $response = $this->get('/v/video-1?shareUrl='.rawurlencode($payload))->assertOk();

    $keep
        ? $response->assertSee('data-share-url="'.e($payload).'"', false)
        : $response->assertDontSee('data-share-url', false);
})->with(shareUrlPayloads());

it('uses the configured privacy flags on the package embed page', function (): void {
    config()->set('scarlett-player.player.player_version', '1.20.0');
    config()->set('scarlett-player.player.analytics_anonymous', true);
    config()->set('scarlett-player.player.analytics_respect_do_not_track', true);
    $this->get('/v/video-1')->assertOk()
        ->assertSee('data-analytics-anonymous="true"', false)
        ->assertSee('data-analytics-respect-dnt="true"', false)
        ->assertDontSee('data-analytics-batch', false);
});
