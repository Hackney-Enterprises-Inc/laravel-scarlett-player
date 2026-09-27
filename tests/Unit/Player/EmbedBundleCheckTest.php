<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Doctor\Checks\EmbedBundleCheck;
use Hei\ScarlettPlayer\Doctor\CheckStatus;

it('only warns on a bare install, where just the embed page would need the bundle', function (): void {
    $result = app(EmbedBundleCheck::class)->run();

    expect($result->status)->toBe(CheckStatus::Warn)
        ->and($result->message)->toContain('SCARLETT_CDN_URL');
});

it('fails when player.mode is embed and no CDN URL is set', function (): void {
    config()->set('scarlett-player.player.mode', 'embed');

    expect(app(EmbedBundleCheck::class)->run()->status)->toBe(CheckStatus::Fail);
});

it('passes naming the rendered bundle', function (): void {
    config()->set('scarlett-player.player.mode', 'embed');
    config()->set('scarlett-player.player.cdn_url', 'https://cdn.example.test/sp/');

    $result = app(EmbedBundleCheck::class)->run();

    expect($result->status)->toBe(CheckStatus::Pass)
        ->and($result->message)->toContain('https://cdn.example.test/sp/v'.config('scarlett-player.player.player_version').'/embed.js');
});

it('passes a full embed_bundle URL without a CDN URL', function (): void {
    config()->set('scarlett-player.player.mode', 'embed');
    config()->set('scarlett-player.player.embed_bundle', 'https://static.example.test/embed.js');

    expect(app(EmbedBundleCheck::class)->run()->status)->toBe(CheckStatus::Pass);
});

it('passes when nothing renders the embed bundle', function (): void {
    config()->set('scarlett-player.routes.embed', false);

    expect(app(EmbedBundleCheck::class)->run()->status)->toBe(CheckStatus::Pass);
});
