<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Facades\ScarlettPlayer;
use Hei\ScarlettPlayer\Tests\Fixtures\Provider\ArrayResolver;

/*
 * The three Embed named tests. The iframes come from the shipped share plugin
 * (tests/Fixtures/embed/share/1.17.0/iframes.json, see its PROVENANCE.md): a signed
 * embedUrl() with startTime and shareUrl appended by URL.searchParams.
 */

beforeEach(function (): void {
    config()->set('scarlett-player.player.cdn_url', 'https://cdn.example.test/scarlett-player');
    config()->set('scarlett-player.embed.allowed_domains', ['host.test']);
    // The fixture base was minted without expiry (see its PROVENANCE.md).
    config()->set('scarlett-player.embed.signed_ttl', null);

    ScarlettPlayer::fake()->withMedia(
        ArrayResolver::source('paid-1', isProtected: true),
        ArrayResolver::source('paid-2', isProtected: true),
    );

    $this->iframes = $this->fixtureJson('embed/share/1.17.0/iframes.json');
});

it('was built on the embedUrl() this app issues', function (): void {
    expect($this->iframes['embedBaseUrl'])->toBe(ScarlettPlayer::embedUrl('paid-1'))
        ->and($this->iframes['cases']['share-built']['src'])->toStartWith($this->iframes['embedBaseUrl'].'&');
});

it('serves a share-built iframe through the signed route', function (): void {
    $src = $this->iframes['cases']['share-built']['src'];

    $this->get($src)
        ->assertOk()
        ->assertSee('data-start-time="83"', false)
        ->assertSee('data-share-url="https://host.test/watch/paid-1"', false)
        // The sheet's own embed snippet reuses this page's signed URL, not a fresh one.
        ->assertSee('data-embed-base-url="'.e($this->iframes['embedBaseUrl']).'"', false);
});

it('answers 403 when the uuid in a share-built iframe is tampered with', function (): void {
    $src = str_replace('/v/paid-1?', '/v/paid-2?', $this->iframes['cases']['share-built']['src']);

    $this->get($src)->assertForbidden();
});

it('drops an off-domain shareUrl and still serves the page', function (): void {
    $response = $this->get($this->iframes['cases']['off-domain-share-url']['src']);

    $response->assertOk()
        ->assertSee('data-start-time="12"', false)
        ->assertDontSee('data-share-url', false)
        ->assertDontSee('evil.example.net', false);
});

it('accepts a subdomain of an allowed domain as shareUrl', function (): void {
    $this->get(ScarlettPlayer::embedUrl('paid-1').'&shareUrl='.urlencode('https://www.host.test/watch'))
        ->assertOk()
        ->assertSee('data-share-url="https://www.host.test/watch"', false);
});
