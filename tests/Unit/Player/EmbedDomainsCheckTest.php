<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Doctor\CheckRegistry;
use Hei\ScarlettPlayer\Doctor\Checks\EmbedDomainsCheck;
use Hei\ScarlettPlayer\Doctor\CheckStatus;

it('fails with the exception message for an entry that is not a host', function (): void {
    config()->set('scarlett-player.embed.allowed_domains', ['example.com', 'not a host']);

    $result = app(EmbedDomainsCheck::class)->run();

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->message)->toContain('[not a host]')
        ->and($result->message)->toContain('bare host names');
});

it('passes naming the normalised hosts', function (): void {
    config()->set('scarlett-player.embed.allowed_domains', ['https://Example.com/', '*.partner.example']);

    $result = app(EmbedDomainsCheck::class)->run();

    expect($result->status)->toBe(CheckStatus::Pass)
        ->and($result->message)->toBe('embed.allowed_domains: example.com, partner.example');
});

it('passes an empty list, saying any site may frame the page', function (): void {
    expect(app(EmbedDomainsCheck::class)->run()->message)->toContain('any site may frame');
});

it('is registered with the player doctor checks', function (): void {
    expect(app(CheckRegistry::class)->classes())->toContain(EmbedDomainsCheck::class);
});
