<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Partners\PartnerParams;
use Hei\ScarlettPlayer\Player\EmbedConfig;

it('fixes the contract names and lifetimes the plan gives', function (): void {
    expect(PartnerParams::KEY)->toBe('scp_key')
        ->and(PartnerParams::REF)->toBe('scp_ref')
        ->and(PartnerParams::MEDIA)->toBe('scp_media')
        ->and(PartnerParams::SIGNED)->toBe(['scp_key', 'scp_ref', 'scp_media'])
        ->and(PartnerParams::BEACON_TTL)->toBe(24 * 60 * 60)
        ->and(PartnerParams::MAX_EXPIRES)->toBe(90 * 24 * 60 * 60)
        ->and(PartnerParams::BEACON_IGNORED)->toBe(['api_key'])
        ->and(PartnerParams::MEDIA_ID)->toBe('^[A-Za-z0-9._-]{1,191}$')
        ->and(PartnerParams::KEY_ID)->toBe('^[a-f0-9]{32}$')
        ->and(PartnerParams::REF_PATTERN)->toBe('^[a-z0-9][a-z0-9-]{0,62}$');
});

it('validates key ids', function (mixed $value, bool $valid): void {
    expect(PartnerParams::isKeyId($value))->toBe($valid);
})->with([
    'hex' => [str_repeat('a', 32), true],
    'mixed hex' => ['0123456789abcdef0123456789abcdef', true],
    'uppercase' => [str_repeat('A', 32), false],
    'short' => [str_repeat('a', 31), false],
    'long' => [str_repeat('a', 33), false],
    'non hex' => [str_repeat('g', 32), false],
    'trailing newline' => [str_repeat('a', 32)."\n", false],
    'empty' => ['', false],
    'array' => [[str_repeat('a', 32)], false],
    'null' => [null, false],
]);

it('validates refs', function (mixed $value, bool $valid): void {
    expect(PartnerParams::isRef($value))->toBe($valid);
})->with([
    'slug' => ['acme-tv', true],
    'digit first' => ['9lives', true],
    'one char' => ['a', true],
    // The plan's pattern allows 1 + 62 characters (the column is 64 wide).
    'longest' => [str_repeat('a', 63), true],
    'too long' => [str_repeat('a', 64), false],
    'uppercase' => ['Acme', false],
    'hyphen first' => ['-acme', false],
    'underscore' => ['acme_tv', false],
    'dot' => ['acme.tv', false],
    'space' => ['acme tv', false],
    'trailing newline' => ["acme\n", false],
    'empty' => ['', false],
    'int' => [7, false],
]);

it('validates the media ids a partner snippet may carry', function (mixed $value, bool $valid): void {
    expect(PartnerParams::isMediaId($value))->toBe($valid);
})->with([
    'uuid' => ['018f1c6e-0000-7000-8000-000000000001', true],
    'dots underscores' => ['clip_1.final-cut', true],
    'longest' => [str_repeat('a', 191), true],
    'too long' => [str_repeat('a', 192), false],
    'space' => ['a b', false],
    'plus' => ['a+b', false],
    'percent' => ['a%20b', false],
    'tilde' => ['a~b', false],
    'asterisk' => ['a*b', false],
    'slash' => ['a/b', false],
    'non ascii' => ['caf'."\u{e9}", false],
    'trailing newline' => ["abc\n", false],
    'empty' => ['', false],
    'null' => [null, false],
]);

it('mints unique 32 character lowercase hex key ids', function (): void {
    $ids = array_map(fn () => PartnerParams::newKeyId(), range(1, 50));

    expect(array_unique($ids))->toHaveCount(50);

    foreach ($ids as $id) {
        expect(PartnerParams::isKeyId($id))->toBeTrue();
    }
});

it('keeps every partner parameter, plus expires and signature, always signed', function (): void {
    expect(EmbedConfig::ALWAYS_SIGNED)->toBe(['expires', 'signature', 'scp_key', 'scp_ref', 'scp_media'])
        ->and(array_diff(PartnerParams::SIGNED, EmbedConfig::ALWAYS_SIGNED))->toBe([]);
});
