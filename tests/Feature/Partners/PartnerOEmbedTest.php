<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Facades\ScarlettPlayer;
use Hei\ScarlettPlayer\Models\Partner;
use Hei\ScarlettPlayer\Partners\PartnerParams;
use Hei\ScarlettPlayer\Tests\Fixtures\Provider\ArrayResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;

/*
 * oEmbed for partner URLs (HEI-LSP-24 WP4). A URL carrying scp_key is verified through the
 * relative-signature partner gate and re-emitted as the iframe src, rebuilt from the app
 * root, the matched route path and the signed query pairs. It never mints or extends an
 * expiry, never echoes the input URL, and drops unsigned presentation parameters.
 */

beforeEach(function (): void {
    $this->withScarlettConfig([
        'media.resolver' => ArrayResolver::class,
        'embed.always_sign' => true,
        'embed.allowed_domains' => [],
    ]);
    $this->usesMigrations();
    config()->set('scarlett-player.player.cdn_url', 'https://cdn.example.test/scarlett-player');

    ScarlettPlayer::fake()->withMedia(
        ArrayResolver::source('video-1'),
        ArrayResolver::source('paid-1', isProtected: true),
    );
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function oembedPartner(array $attributes = []): Partner
{
    return Partner::create($attributes + [
        'ref' => 'acme',
        'name' => 'Acme',
        'origins' => ['https://partner.example'],
    ]);
}

/**
 * A relative-signed partner page path, as the minter signs it. $expires is an absolute timestamp.
 *
 * @param  array<string, mixed>  $extra
 */
function oembedPartnerPath(Partner|string $partner, string $media = 'video-1', ?int $expires = null, array $extra = []): string
{
    $key = $partner instanceof Partner ? $partner->key_id : $partner;
    $until = $expires === null ? null : Carbon::createFromTimestamp($expires);

    return URL::signedRoute('scarlett.embed.show', [$media, PartnerParams::KEY => $key, ...$extra], $until, absolute: false);
}

function oembedLookup(string $url): TestResponse
{
    return test()->getJson(route('scarlett.oembed.show', ['url' => $url]));
}

/**
 * The iframe src of an oEmbed response, decoded from its html.
 */
function oembedSrc(TestResponse $response): string
{
    preg_match('/ src="([^"]*)"/', (string) $response->json('html'), $m);

    return html_entity_decode($m[1] ?? '');
}

/**
 * @return array<string, mixed>
 */
function oembedSrcQuery(string $src): array
{
    parse_str((string) parse_url($src, PHP_URL_QUERY), $query);

    return $query;
}

it('re-emits a valid partner URL with the same scp_key, signature and expires and no unsigned params', function (): void {
    $partner = oembedPartner();
    $expires = Carbon::now()->addDays(10)->getTimestamp();
    $path = oembedPartnerPath($partner, 'video-1', $expires);

    // The share plugin appends these after signing.
    $response = oembedLookup('http://localhost'.$path.'&startTime=30&shareUrl='.urlencode('https://host.test/w'));
    $response->assertOk();

    $given = [];
    parse_str((string) parse_url($path, PHP_URL_QUERY), $given);

    $src = oembedSrc($response);
    $query = oembedSrcQuery($src);

    expect($query)->toHaveKeys([PartnerParams::KEY, 'expires', 'signature'])
        ->and($query[PartnerParams::KEY])->toBe($partner->key_id)
        ->and($query['expires'])->toBe($given['expires'])
        ->and($query['signature'])->toBe($given['signature'])
        ->and($query)->not->toHaveKeys(['startTime', 'shareUrl'])
        ->and(parse_url($src, PHP_URL_PATH))->toBe('/v/video-1');

    // The re-emitted URL is itself a valid partner page URL.
    $this->get(substr($src, strlen('https://localhost')))->assertOk();
});

it('adds no expiry to a URL that has none and never extends one', function (): void {
    $partner = oembedPartner();

    $bare = oembedSrcQuery(oembedSrc(oembedLookup('http://localhost'.oembedPartnerPath($partner))));
    expect($bare)->not->toHaveKey('expires');

    $soon = Carbon::now()->addHour()->getTimestamp();
    $query = oembedSrcQuery(oembedSrc(oembedLookup('http://localhost'.oembedPartnerPath($partner, 'video-1', $soon))));
    expect($query['expires'])->toBe((string) $soon);
});

it('refuses with 403 when the partner is revoked, unknown or past expires_at', function (string $case): void {
    $partner = oembedPartner();
    $path = oembedPartnerPath($partner);

    match ($case) {
        'revoked' => $partner->forceFill(['revoked_at' => Carbon::now()->subMinute()])->save(),
        'unknown' => $path = oembedPartnerPath(str_repeat('a', 32)),
        'expired' => $partner->forceFill(['expires_at' => Carbon::now()->subMinute()])->save(),
    };

    oembedLookup('http://localhost'.$path)->assertForbidden();
})->with(['revoked', 'unknown', 'expired']);

it('refuses protected media without an expiry with 403', function (): void {
    $partner = oembedPartner();

    oembedLookup('http://localhost'.oembedPartnerPath($partner, 'paid-1'))->assertForbidden();
});

it('answers 401 for a tampered signature, scp_key, media path or expires', function (string $case): void {
    $partner = oembedPartner();
    $other = oembedPartner(['ref' => 'other']);
    $expires = Carbon::now()->addDay()->getTimestamp();
    $path = oembedPartnerPath($partner, 'video-1', $expires);

    $url = match ($case) {
        'signature' => preg_replace('/signature=[0-9a-f]{3}/', 'signature=000', $path),
        'scp_key' => str_replace($partner->key_id, $other->key_id, $path),
        'media' => str_replace('/v/video-1', '/v/paid-1', $path),
        'expires' => str_replace('expires='.$expires, 'expires='.($expires + 100), $path),
    };

    oembedLookup('http://localhost'.$url)->assertUnauthorized();
})->with(['signature', 'scp_key', 'media', 'expires']);

it('answers 401 for an expired URL', function (): void {
    $partner = oembedPartner();
    $path = oembedPartnerPath($partner, 'video-1', Carbon::now()->addMinute()->getTimestamp());

    Carbon::setTestNow(Carbon::now()->addHour());

    oembedLookup('http://localhost'.$path)->assertUnauthorized();
});

it('answers 401 for an unsigned scp_key on unprotected media with always_sign off', function (): void {
    config()->set('scarlett-player.embed.always_sign', false);
    $partner = oembedPartner();

    oembedLookup('http://localhost/v/video-1?scp_key='.$partner->key_id)->assertUnauthorized();
});

it('never accepts an scp_key URL on an absolute signature alone', function (): void {
    $partner = oembedPartner();
    $absolute = URL::signedRoute('scarlett.embed.show', ['video-1', PartnerParams::KEY => $partner->key_id]);

    oembedLookup($absolute)->assertUnauthorized();
});

it('keeps the same-host rule for partner URLs', function (): void {
    $partner = oembedPartner();

    oembedLookup('https://elsewhere.test'.oembedPartnerPath($partner))->assertNotFound();
});

it('builds an https src on the app root and never echoes the input scheme or fragment', function (string $prefix, string $suffix): void {
    $partner = oembedPartner();
    $response = oembedLookup($prefix.oembedPartnerPath($partner).$suffix);

    $response->assertOk();
    $src = oembedSrc($response);

    expect($src)->toStartWith('https://localhost/v/video-1?')
        ->and($src)->not->toContain('#')
        ->and($src)->not->toContain('javascript');
})->with([
    'http scheme' => ['http://localhost', ''],
    'https scheme' => ['https://localhost', ''],
    'fragment' => ['http://localhost', '#frag"><script>alert(1)</script>'],
]);

it('answers 404 for a scheme that is not http or https', function (string $scheme): void {
    $partner = oembedPartner();

    oembedLookup($scheme.'://localhost'.oembedPartnerPath($partner))->assertNotFound();
})->with(['javascript', 'data', 'ftp']);
