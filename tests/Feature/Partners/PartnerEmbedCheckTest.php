<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Doctor\CheckResult;
use Hei\ScarlettPlayer\Doctor\Checks\PartnerEmbedCheck;
use Hei\ScarlettPlayer\Doctor\CheckStatus;
use Hei\ScarlettPlayer\Facades\ScarlettPlayer;
use Hei\ScarlettPlayer\Models\Partner;
use Hei\ScarlettPlayer\Tests\Fixtures\Provider\ArrayResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;

/*
 * The doctor check for the partner lock's deployment requirements. The warning text that
 * `scarlett:partners:snippet` prints for an empty embed.allowed_domains lives in WP5.
 */

function partnerCheck(): CheckResult
{
    return app(PartnerEmbedCheck::class)->run();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function checkPartner(array $attributes = []): Partner
{
    return Partner::create($attributes + ['ref' => 'acme', 'name' => 'Acme', 'origins' => ['https://partner.example']]);
}

beforeEach(function (): void {
    $this->withScarlettConfig([
        'media.resolver' => ArrayResolver::class,
        'embed.always_sign' => true,
        'embed.allowed_domains' => ['host.test'],
    ]);
    config()->set('scarlett-player.player.cdn_url', 'https://cdn.example.test/scarlett-player');
});

it('passes with no scarlett_partners table, without an exception', function (): void {
    $result = partnerCheck();

    expect($result->status)->toBe(CheckStatus::Pass)->and($result->message)->toContain('No scarlett_partners table');
});

it('warns instead of throwing when the database connection fails', function (): void {
    Schema::shouldReceive('hasTable')
        ->with('scarlett_partners')
        ->andThrow(new RuntimeException('SQLSTATE[HY000] [2002] Connection refused'));

    $result = partnerCheck();

    expect($result->status)->toBe(CheckStatus::Warn)
        ->and($result->message)->toContain('could not inspect partner embeds', 'Connection refused');
});

it('passes with the table and no partners, whatever the embed config', function (): void {
    $this->usesMigrations();
    config()->set('scarlett-player.embed.always_sign', false);
    config()->set('scarlett-player.embed.allowed_domains', []);

    expect(partnerCheck()->status)->toBe(CheckStatus::Pass);
});

it('passes with a partner, always_sign on and allowed_domains set', function (): void {
    $this->usesMigrations();
    checkPartner();

    expect(partnerCheck()->status)->toBe(CheckStatus::Pass);
});

it('fails when always_sign is false and a partner exists', function (): void {
    $this->usesMigrations();
    config()->set('scarlett-player.embed.always_sign', false);
    checkPartner();

    $result = partnerCheck();

    expect($result->status)->toBe(CheckStatus::Fail)->and($result->message)->toContain('embed.always_sign');
});

it('warns when always_sign is on, allowed_domains is empty and a partner exists', function (): void {
    $this->usesMigrations();
    config()->set('scarlett-player.embed.allowed_domains', []);
    checkPartner();

    $result = partnerCheck();

    expect($result->status)->toBe(CheckStatus::Warn)
        ->and($result->message)->toContain('embed.allowed_domains')->toContain('per deployment');
});

it('documents the accepted limit: with empty allowed_domains ordinary signed embeds are still framed by *', function (): void {
    $this->usesMigrations();
    config()->set('scarlett-player.embed.allowed_domains', []);
    checkPartner();
    ScarlettPlayer::fake()->withMedia(ArrayResolver::source('video-1'));

    // The doctor warns about exactly this, and the page does what the warning says.
    expect(partnerCheck()->status)->toBe(CheckStatus::Warn);

    $this->get(URL::signedRoute('scarlett.embed.show', ['video-1']))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', 'frame-ancestors *');
});

it('fails on invalid or empty stored origins', function (mixed $origins): void {
    $this->usesMigrations();
    checkPartner(['origins' => $origins]);

    $result = partnerCheck();

    expect($result->status)->toBe(CheckStatus::Fail)->and($result->message)->toContain('[acme]');
})->with([
    'empty list' => [[]],
    'injected directive' => [['https://a.test; script-src *']],
    'bare star' => [['*']],
    'one bad among good' => [['https://good.example', 'http://public.example']],
    'non-string member' => [['https://good.example', 5]],
    'whitespace' => [['https://a.test https://b.test']],
]);

it('fails on a stored origins value that is not a JSON list', function (string $raw): void {
    $this->usesMigrations();
    $partner = checkPartner();
    DB::table('scarlett_partners')->where('id', $partner->id)->update(['origins' => $raw]);

    expect(partnerCheck()->status)->toBe(CheckStatus::Fail);
})->with(['"a string"', '42', 'null', '{"a":"https://partner.example"}', '{"0":"https://partner.example"}', '{"0":"https://a.example","1":"https://b.example"}', '{}', '[7]']);

// MySQL and PostgreSQL reject invalid JSON at write time, so that state cannot exist there.
it('fails on stored origins text that is not JSON at all', function (string $raw): void {
    $this->usesMigrations();
    $partner = checkPartner();
    DB::table('scarlett_partners')->where('id', $partner->id)->update(['origins' => $raw]);

    expect(partnerCheck()->status)->toBe(CheckStatus::Fail);
})->with(['{broken', ''])->skip(fn (): bool => DB::connection()->getDriverName() !== 'sqlite', 'MySQL and PostgreSQL reject invalid JSON at write time, so that state cannot exist there.');

it('reports every failing partner and keeps a hard failure over the warning', function (): void {
    $this->usesMigrations();
    config()->set('scarlett-player.embed.allowed_domains', []);
    checkPartner(['ref' => 'good']);
    checkPartner(['ref' => 'bad-one', 'origins' => []]);
    checkPartner(['ref' => 'bad-two', 'origins' => ['*']]);

    $result = partnerCheck();

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->message)->toContain('[bad-one]')->toContain('[bad-two]')->not->toContain('[good]');
});

it('fails when embed.allowed_domains is malformed, instead of throwing', function (): void {
    $this->usesMigrations();
    config()->set('scarlett-player.embed.allowed_domains', ['not a host!']);
    checkPartner();

    expect(partnerCheck()->status)->toBe(CheckStatus::Fail);
});
