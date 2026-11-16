<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Exceptions\InvalidPartnerException;
use Hei\ScarlettPlayer\Facades\ScarlettPlayer;
use Hei\ScarlettPlayer\Models\Partner;
use Hei\ScarlettPlayer\Partners\PartnerParams;
use Hei\ScarlettPlayer\Tests\Fixtures\Provider\ArrayResolver;
use Hei\ScarlettPlayer\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;

/*
 * The partner gate on the embed page (HEI-LSP-24 WP2). A partner URL carries scp_key and
 * is signed RELATIVE; the page enforces the row (revoked, expired, unknown), the signature
 * and, for protected media, an expiry, and frames only the partner's own origins.
 *
 * The URLs here are signed with the framework directly, not with the package minter, so a
 * test can say exactly what the gate sees.
 */

const PARTNER_ORIGIN = 'https://partner.example';

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
        ArrayResolver::source('video-2'),
        ArrayResolver::source('bad id/with slash'),
        ArrayResolver::source('paid-1', isProtected: true),
    );
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function partnerRow(array $attributes = []): Partner
{
    return Partner::create($attributes + [
        'ref' => 'acme',
        'name' => 'Acme',
        'origins' => [PARTNER_ORIGIN],
    ]);
}

/**
 * A relative-signed partner page path, the way the minter signs it.
 *
 * @param  array<string, mixed>  $extra
 */
function partnerPath(Partner|string $partner, string $media = 'video-1', ?int $expires = null, array $extra = []): string
{
    $key = $partner instanceof Partner ? $partner->key_id : $partner;

    // $expires is an absolute unix timestamp here; the framework reads a bare int as seconds from now.
    $until = $expires === null ? null : Carbon::createFromTimestamp($expires);

    return URL::signedRoute('scarlett.embed.show', [$media, PartnerParams::KEY => $key, ...$extra], $until, absolute: false);
}

function partnerCsp(TestResponse $response): string
{
    return (string) $response->headers->get('Content-Security-Policy');
}

it('serves a valid partner URL with the partner origins as frame-ancestors and no-store', function (): void {
    $partner = partnerRow();

    $response = $this->get(partnerPath($partner));

    $response->assertOk()->assertHeader('Content-Security-Policy', "frame-ancestors 'self' https://partner.example");
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

it('frames only the partner origins even when embed.allowed_domains is set', function (): void {
    config()->set('scarlett-player.embed.allowed_domains', ['host.test']);
    $partner = partnerRow(['origins' => ['https://a.example', 'https://*.b.example:8443']]);

    $this->get(partnerPath($partner))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'self' https://a.example https://*.b.example:8443");
});

it('refuses a valid signature for an scp_key that matches no partner', function (): void {
    $this->get(partnerPath(str_repeat('a', 32)))->assertForbidden();
});

it('refuses a revoked partner, on GET and on HEAD', function (): void {
    $partner = partnerRow(['revoked_at' => now()->subMinute()]);

    $this->get(partnerPath($partner))->assertForbidden();
    $this->call('HEAD', partnerPath($partner))->assertForbidden();
});

it('refuses a partner whose expires_at is past or is exactly now, and serves one in the future', function (): void {
    $now = Carbon::parse('2026-06-01 12:00:00');
    Carbon::setTestNow($now);

    $past = partnerRow(['ref' => 'past', 'expires_at' => $now->copy()->subSecond()]);
    $atNow = partnerRow(['ref' => 'atnow', 'expires_at' => $now->copy()]);
    $future = partnerRow(['ref' => 'future', 'expires_at' => $now->copy()->addSecond()]);

    $this->get(partnerPath($past))->assertForbidden();
    $this->get(partnerPath($atNow))->assertForbidden();
    $this->get(partnerPath($future))->assertOk();
});

it('refuses scp_key without a signature on unprotected media, with always_sign on or off', function (bool $alwaysSign): void {
    config()->set('scarlett-player.embed.always_sign', $alwaysSign);
    $partner = partnerRow();

    $this->get('/v/video-1?scp_key='.$partner->key_id)->assertForbidden();
})->with([true, false]);

it('refuses scp_key that is not satisfiable by an absolute signature alone', function (): void {
    $partner = partnerRow();

    // Signed over the ABSOLUTE url, scp_key included: valid for the old check, not relative.
    $absolute = URL::signedRoute('scarlett.embed.show', ['video-1', PartnerParams::KEY => $partner->key_id]);

    $this->get($absolute)->assertForbidden();

    // Same with always_sign off: the partner branch has no fallback to the absolute check.
    config()->set('scarlett-player.embed.always_sign', false);
    $this->get($absolute)->assertForbidden();
});

it('does not turn a stripped scp_key into a served page when the signature is stripped too', function (): void {
    partnerRow();

    // The documented lock: with always_sign on, an unsigned non-partner URL is refused.
    $this->get('/v/video-1')->assertForbidden();
});

it('refuses tampered partner URLs', function (string $case): void {
    $one = partnerRow(['ref' => 'one']);
    $two = partnerRow(['ref' => 'two']);
    $path = partnerPath($one, 'video-1', time() + 3600);

    $tampered = match ($case) {
        'swapped scp_key' => str_replace($one->key_id, $two->key_id, $path),
        'media path' => str_replace('/v/video-1', '/v/video-2', $path),
        'expires' => (string) preg_replace('/expires=\d+/', 'expires='.(time() + 7200), $path),
        'extra parameter' => $path.'&extra=1',
        'dropped expires' => (string) preg_replace('/expires=\d+&/', '', $path),
        'signature of another url' => (string) preg_replace('/signature=[a-f0-9]+/', 'signature='.str_repeat('0', 64), $path),
    };

    $this->get($tampered)->assertForbidden();
})->with(['swapped scp_key', 'media path', 'expires', 'extra parameter', 'dropped expires', 'signature of another url']);

it('refuses a malformed scp_key however it is signed', function (string $value): void {
    $signed = URL::signedRoute('scarlett.embed.show', ['video-1', PartnerParams::KEY => $value], absolute: false);

    $this->get($signed)->assertForbidden();
})->with(['', 'ABCDEF0123456789ABCDEF0123456789', 'short', str_repeat('a', 33), "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n"]);

// The repeat fails the signature and the array fails the key shape; the count guard
// itself is tested in tests/Unit/Partners/PartnerQueryGuardsTest.php.
it('refuses an array or repeated scp_key', function (): void {
    $partner = partnerRow();
    $signed = URL::signedRoute('scarlett.embed.show', ['video-1', PartnerParams::KEY => [$partner->key_id]], absolute: false);

    $this->get($signed)->assertForbidden();
    $this->get(partnerPath($partner).'&scp_key='.$partner->key_id)->assertForbidden();
});

it('refuses a URL whose expires is past and serves one that is in the future', function (): void {
    $partner = partnerRow();

    $this->get(partnerPath($partner, 'video-1', time() - 10))->assertForbidden();
    $this->get(partnerPath($partner, 'video-1', time() + 3600))->assertOk();
});

it('accepts a URL whose expires is exactly now', function (): void {
    $now = Carbon::parse('2026-06-01 12:00:00');
    Carbon::setTestNow($now);
    $partner = partnerRow();

    $this->get(partnerPath($partner, 'video-1', $now->getTimestamp()))->assertOk();
});

it('refuses the old URL when the same ref is deleted and recreated', function (): void {
    $old = partnerRow();
    $oldPath = partnerPath($old);

    $old->delete();
    $new = partnerRow();

    expect($new->key_id)->not->toBe($old->key_id);
    $this->get($oldPath)->assertForbidden();
    $this->get(partnerPath($new))->assertOk();
});

it('refuses the old URL after rotate and after a revoke then restore with rotate', function (): void {
    $partner = partnerRow();
    $first = partnerPath($partner);
    $this->get($first)->assertOk();

    $partner->rotate();
    $second = partnerPath($partner);
    $this->get($first)->assertForbidden();
    $this->get($second)->assertOk();

    $partner->forceFill(['revoked_at' => now()])->save();
    $this->get($second)->assertForbidden();

    // Restore clears the revoke and rotates: the snippet the revoke killed stays dead.
    $partner->forceFill(['revoked_at' => null])->save();
    $partner->rotate();
    $this->get($second)->assertForbidden();
    $this->get(partnerPath($partner))->assertOk();
});

it('answers frame-ancestors none for stored origins that are not valid, and never a 500', function (mixed $origins): void {
    $partner = partnerRow(['origins' => $origins]);

    $this->get(partnerPath($partner))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
})->with([
    'injected directive' => [['https://a.test; script-src *']],
    'bare star' => [['*']],
    'whitespace' => [['https://a.test https://b.test']],
    'leading space' => [[' https://a.test']],
    'CRLF' => [["https://a.test\r\nX-Evil: 1"]],
    'trailing newline' => [["https://a.test\n"]],
    'non-string members' => [[1, null, true, ['https://a.test'], 1.5]],
    'http for a public host' => [['http://a.test']],
    'path' => [['https://a.test/path']],
    'scheme only' => [['https://']],
    'empty list' => [[]],
    'empty string' => [['']],
]);

it('keeps the valid origins of a stored list and drops the rest', function (): void {
    $partner = partnerRow(['origins' => ['https://a.test; script-src *', 'https://good.example', '*', 'HTTPS://Other.Example:8443']]);

    $this->get(partnerPath($partner))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'self' https://good.example https://other.example:8443");
});

it('fails closed to none when the stored origins JSON is malformed at the top level', function (string $raw): void {
    $partner = partnerRow();
    DB::table('scarlett_partners')->where('id', $partner->id)->update(['origins' => $raw]);

    $response = $this->get(partnerPath($partner));

    $response->assertOk()->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
})->with([
    'string' => ['"https://partner.example"'],
    'number' => ['42'],
    'null' => ['null'],
    'object' => ['{"a":"https://partner.example"}'],
    'numeric keyed object' => ['{"0":"https://partner.example"}'],
    'sequential numeric keyed object' => ['{"0":"https://a.example","1":"https://b.example"}'],
    'empty object' => ['{}'],
    'array of a number' => ['[7]'],
]);

// MySQL and PostgreSQL reject invalid JSON at write time, so that state cannot exist there.
it('fails closed to none when the stored origins text is not JSON at all', function (string $raw): void {
    $partner = partnerRow();
    DB::table('scarlett_partners')->where('id', $partner->id)->update(['origins' => $raw]);

    $response = $this->get(partnerPath($partner));

    $response->assertOk()->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
})->with([
    'broken json' => ['{not json'],
    'empty' => [''],
])->skip(fn (): bool => DB::connection()->getDriverName() !== 'sqlite', 'MySQL and PostgreSQL reject invalid JSON at write time, so that state cannot exist there.');

it('never falls back to the global list or a star when the origins are bad', function (): void {
    config()->set('scarlett-player.embed.allowed_domains', ['host.test']);
    $partner = partnerRow(['origins' => []]);

    $csp = partnerCsp($this->get(partnerPath($partner)));

    expect($csp)->toBe("frame-ancestors 'none'")->not->toContain('host.test')->not->toContain('*');
});

it('adds no share embed target to a partner page even when shareUrl is present', function (): void {
    $partner = partnerRow();

    $this->get(partnerPath($partner).'&shareUrl='.urlencode('https://partner.example/watch'))
        ->assertOk()
        ->assertDontSee('data-embed-base-url', false)
        ->assertDontSee('data-share-url', false);
});

it('ignores scp_* listed in embed.unsigned_params', function (): void {
    config()->set('scarlett-player.embed.unsigned_params', ['scp_key', 'scp_ref', 'scp_media', 'startTime']);
    $one = partnerRow(['ref' => 'one']);
    $two = partnerRow(['ref' => 'two']);

    $this->get(partnerPath($one))->assertOk();
    $this->get(str_replace($one->key_id, $two->key_id, partnerPath($one)))->assertForbidden();
});

it('still ignores the unsigned presentation parameters on a partner URL', function (): void {
    $partner = partnerRow();

    $this->get(partnerPath($partner).'&startTime=30&autoplay=1')->assertOk();
});

it('requires an expires within 90 days for protected media', function (): void {
    $partner = partnerRow();

    $this->get(partnerPath($partner, 'paid-1'))->assertForbidden();
    $this->get(partnerPath($partner, 'paid-1', time() + PartnerParams::MAX_EXPIRES + 3600))->assertForbidden();
    $this->get(partnerPath($partner, 'paid-1', time() + 86400))->assertOk();
});

it('refuses a URL issued for unprotected media once that media becomes protected', function (): void {
    $partner = partnerRow();
    $path = partnerPath($partner, 'video-1');

    $this->get($path)->assertOk();

    ScarlettPlayer::fake()->withMedia(ArrayResolver::source('video-1', isProtected: true));

    $this->get($path)->assertForbidden();
});

it('answers 404 for unknown media only behind a valid partner signature', function (): void {
    $partner = partnerRow();

    $this->get(partnerPath($partner, 'nope'))->assertNotFound();
    $this->get(partnerPath(str_repeat('b', 32), 'nope'))->assertForbidden();
});

it('validates a minted URL when app.url is another scheme and host than the request', function (): void {
    config()->set('app.url', 'https://elsewhere.example/sub');
    $partner = partnerRow();
    $path = partnerPath($partner);

    $this->get('http://localhost'.$path)->assertOk();

    URL::forceRootUrl('https://proxy.example');
    URL::forceScheme('https');
    $this->get('http://localhost'.$path)->assertOk();
});

it('signs the path the request will see, whatever root or sub-path the generator is forced to', function (): void {
    $partner = partnerRow();

    // A proxy that strips its prefix: public URLs carry /sub, the app sees /v/video-1.
    URL::forceRootUrl('https://proxy.example/sub');

    $url = ScarlettPlayer::partnerEmbedUrl($partner->ref, 'video-1', root: 'https://host.example/sub');

    expect($url)->toStartWith('https://host.example/sub/v/video-1?scp_key='.$partner->key_id.'&signature=');

    // What the proxy forwards: the prefix is gone. (The test client would otherwise add the forced root.)
    URL::forceRootUrl(null);
    $this->get(substr($url, strlen('https://host.example/sub')))->assertOk();
});

it('accepts a URL signed with the previous APP_KEY while it is retained and refuses it once removed', function (): void {
    $partner = partnerRow();
    $path = partnerPath($partner);
    $old = (string) config('app.key');

    config()->set('app.key', 'base64:'.base64_encode(str_repeat('n', 32)));

    $this->get($path)->assertForbidden();

    config()->set('app.previous_keys', [$old]);
    $this->get($path)->assertOk();

    config()->set('app.previous_keys', []);
    $this->get($path)->assertForbidden();
});

it('leaves a non-partner embed exactly as before', function (): void {
    config()->set('scarlett-player.embed.always_sign', false);

    $response = $this->get('/v/video-1');

    $response->assertOk()->assertHeader('Content-Security-Policy', 'frame-ancestors *');
    expect((string) $response->headers->get('Cache-Control'))->not->toContain('no-store');

    $signed = URL::signedRoute('scarlett.embed.show', ['video-1']);
    $this->get($signed)->assertOk()->assertHeader('Content-Security-Policy', 'frame-ancestors *');
});

/**
 * The beacon proof printed on a partner page: the attribute value, decoded.
 */
function partnerPageBeaconUrl(TestResponse $response): string
{
    preg_match('/data-analytics-beacon-url="([^"]*)"/', $response->getContent(), $match);

    return html_entity_decode($match[1] ?? '', ENT_QUOTES);
}

it('prints a relative-signed beacon proof with the ref, the media and a 24 hour expiry', function (): void {
    $now = Carbon::parse('2026-06-01 12:00:00');
    Carbon::setTestNow($now);
    $partner = partnerRow();

    $url = partnerPageBeaconUrl($this->get(partnerPath($partner)));

    expect($url)->not->toBe('');

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($query[PartnerParams::REF])->toBe('acme')
        ->and($query[PartnerParams::MEDIA])->toBe('video-1')
        ->and((int) $query['expires'])->toBe($now->getTimestamp() + PartnerParams::BEACON_TTL)
        ->and(array_keys($query))->toBe(['expires', PartnerParams::MEDIA, PartnerParams::REF, 'signature']);

    // The verifier's view: the same request with the plugin's api_key appended.
    $request = Request::create($url.'&api_key=test-beacon-key');

    expect($request->hasValidRelativeSignatureWhileIgnoring(PartnerParams::BEACON_IGNORED))->toBeTrue();

    // Anything else appended, or another media, breaks it.
    expect(Request::create($url.'&x=1')->hasValidRelativeSignatureWhileIgnoring(PartnerParams::BEACON_IGNORED))->toBeFalse()
        ->and(Request::create(str_replace('scp_media=video-1', 'scp_media=video-2', $url))->hasValidRelativeSignatureWhileIgnoring(PartnerParams::BEACON_IGNORED))->toBeFalse();
});

it('keeps the key out of the beacon proof and prints it only as the plugin api key', function (): void {
    $partner = partnerRow();

    $response = $this->get(partnerPath($partner));

    // (The page's own canonical and og:url carry its signed URL, as for every embed.)
    expect(partnerPageBeaconUrl($response))->not->toContain('api_key')->not->toContain($partner->key_id)
        ->and($response->getContent())->toContain('data-analytics-api-key="'.TestCase::BEACON_KEY.'"');
});

it('roots the beacon proof in the URL generator, not in the request', function (): void {
    $partner = partnerRow();
    $path = partnerPath($partner);
    URL::forceScheme('https');

    $url = partnerPageBeaconUrl($this->get('http://localhost'.$path));

    expect($url)->toStartWith('https://localhost/');

    URL::forceRootUrl('https://proxy.example/sub');
    $url = partnerPageBeaconUrl($this->get('http://localhost'.$path));

    expect($url)->toStartWith('https://proxy.example/sub/')
        // The signature is relative to the route path, so a root or sub-path never moves it.
        ->and(Request::create($url)->hasValidRelativeSignatureWhileIgnoring(PartnerParams::BEACON_IGNORED))->toBeFalse()
        ->and(Request::create(str_replace('/sub', '', $url))->hasValidRelativeSignatureWhileIgnoring(PartnerParams::BEACON_IGNORED))->toBeTrue();
});

it('leaves the beacon URL of a non-partner embed unsigned', function (): void {
    config()->set('scarlett-player.embed.always_sign', false);

    $url = partnerPageBeaconUrl($this->get('/v/video-1'));

    expect($url)->not->toBe('')->not->toContain('signature')->not->toContain('scp_');
});

it('prints no beacon proof when the beacons are off', function (): void {
    config()->set('scarlett-player.beacons.enabled', false);
    $partner = partnerRow();

    $this->get(partnerPath($partner))->assertOk()->assertDontSee('data-analytics-beacon-url', false);
});

it('mints a URL and an iframe through the package that the gate accepts', function (): void {
    $partner = partnerRow();

    $url = ScarlettPlayer::partnerEmbedUrl($partner->ref, 'video-1', root: 'https://host.example');

    expect($url)->toStartWith('https://host.example/v/video-1?scp_key='.$partner->key_id.'&signature=');

    $this->get(substr($url, strlen('https://host.example')))->assertOk();

    $code = ScarlettPlayer::partnerEmbedCode($partner->ref, 'video-1', root: 'https://host.example', width: 800, height: 450);

    expect($code)->toStartWith('<iframe')->toContain('width="800"')->toContain('height="450"')
        ->toContain('allow="autoplay; fullscreen; picture-in-picture"')
        ->toContain(e($url));
});

it('refuses to mint for the cases the plan lists', function (): void {
    $partner = partnerRow();
    $mint = fn (string $ref = 'acme', string $media = 'video-1', mixed $expires = null) => fn () => ScarlettPlayer::partnerEmbedUrl($ref, $media, $expires, root: 'https://host.example');

    expect($mint('nope'))->toThrow(InvalidPartnerException::class)
        ->and($mint('acme', 'bad id/with slash'))->toThrow(InvalidPartnerException::class)
        ->and($mint('acme', 'paid-1'))->toThrow(InvalidPartnerException::class)
        ->and($mint('acme', 'video-1', PartnerParams::MAX_EXPIRES + 3600))->toThrow(InvalidPartnerException::class)
        ->and($mint('acme', 'video-1', -5))->toThrow(InvalidPartnerException::class);

    $partner->forceFill(['revoked_at' => now()])->save();
    expect($mint())->toThrow(InvalidPartnerException::class);

    $partner->forceFill(['revoked_at' => null, 'expires_at' => now()->subDay()])->save();
    expect($mint())->toThrow(InvalidPartnerException::class);

    $partner->forceFill(['expires_at' => null])->save();
    config()->set('scarlett-player.embed.always_sign', false);
    expect($mint())->toThrow(InvalidPartnerException::class);
});

it('mints protected media with an expiry inside the cap and the gate serves it', function (): void {
    $partner = partnerRow();

    $url = ScarlettPlayer::partnerEmbedUrl($partner->ref, 'paid-1', 86400, root: 'https://host.example');

    expect($url)->toContain('expires=');
    $this->get(substr($url, strlen('https://host.example')))->assertOk();
});

it('falls back to app.url and then the current request root when no root is given', function (): void {
    $partner = partnerRow();

    config()->set('app.url', 'https://configured.example');
    expect(ScarlettPlayer::partnerEmbedUrl($partner->ref, 'video-1'))->toStartWith('https://configured.example/v/video-1?');

    config()->set('app.url', '');
    expect(ScarlettPlayer::partnerEmbedUrl($partner->ref, 'video-1'))->toStartWith(url('/').'/v/video-1?');
});
