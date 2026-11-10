<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Exceptions\InvalidEmbedConfigException;
use Hei\ScarlettPlayer\Player\EmbedConfig;

it('normalises allowed_domains to bare lowercase hosts', function (): void {
    config()->set('scarlett-player.embed.allowed_domains', [
        'https://Example.com/', 'example.com:8443', '*.partner.example', '.other.test', 'http://sub.site.test/path?x=1', '',
    ]);

    expect(app(EmbedConfig::class)->allowedDomains())->toBe(['example.com', 'partner.example', 'other.test', 'sub.site.test']);
});

it('throws a named exception for an entry that is not a host', function (string $entry): void {
    config()->set('scarlett-player.embed.allowed_domains', [$entry]);

    app(EmbedConfig::class)->allowedDomains();
})->with(['not a host', 'exa_mple.com', 'https://', '*'])->throws(InvalidEmbedConfigException::class, 'bare host names');

it('allows a host, its subdomains, and every host when the list is empty', function (): void {
    $config = app(EmbedConfig::class);

    expect($config->allowsHost('anything.test'))->toBeTrue();

    config()->set('scarlett-player.embed.allowed_domains', ['host.test']);

    expect($config->allowsHost('host.test'))->toBeTrue()
        ->and($config->allowsHost('WWW.HOST.TEST'))->toBeTrue()
        ->and($config->allowsHost('evilhost.test'))->toBeFalse()
        ->and($config->allowsHost('host.test.evil'))->toBeFalse();
});

it('never treats expires or signature as unsigned', function (): void {
    config()->set('scarlett-player.embed.unsigned_params', ['startTime', 'expires', 'signature', 'shareUrl', 7]);

    expect(app(EmbedConfig::class)->unsignedParams())->toBe(['startTime', 'shareUrl']);
});

it('never lets a partner parameter into unsigned_params', function (): void {
    config()->set('scarlett-player.embed.unsigned_params', ['startTime', 'scp_key', 'scp_ref', 'scp_media', 'shareUrl']);

    expect(app(EmbedConfig::class)->unsignedParams())->toBe(['startTime', 'shareUrl']);
});

it('exposes the shared host charset as a public static check', function (string $host, bool $valid): void {
    expect(EmbedConfig::isHost($host))->toBe($valid);
})->with([
    'host' => ['partner.example', true],
    'single label' => ['localhost', true],
    'ip' => ['127.0.0.1', true],
    'hyphen inside' => ['a-b.example', true],
    'uppercase' => ['Partner.example', false],
    'underscore' => ['a_b.example', false],
    'leading hyphen' => ['-a.example', false],
    'trailing dot' => ['a.example.', false],
    'wildcard' => ['*.example', false],
    'port' => ['a.example:80', false],
    'empty' => ['', false],
    'trailing newline' => ["a.example\n", false],
    'too long' => [str_repeat('a.', 127).'a', false],
]);

it('reads signed_ttl as positive seconds or null', function (mixed $value, ?int $expected): void {
    config()->set('scarlett-player.embed.signed_ttl', $value);

    expect(app(EmbedConfig::class)->signedTtl())->toBe($expected);
})->with([
    'default day' => [86400, 86400],
    'null' => [null, null],
    'zero' => [0, null],
    'string' => ['600', 600],
]);

it('ships a one-day signed_ttl', function (): void {
    expect(config('scarlett-player.embed.signed_ttl'))->toBe(86400);
});
