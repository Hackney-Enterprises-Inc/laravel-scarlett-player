<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Exceptions\IncompleteMediaMappingException;
use Hei\ScarlettPlayer\Exceptions\MediaNotFoundException;
use Hei\ScarlettPlayer\Exceptions\ScarlettPlayerException;

test('the base exception is a RuntimeException', function (): void {
    expect(new ScarlettPlayerException('x'))->toBeInstanceOf(RuntimeException::class);
});

test('MediaNotFoundException carries the media id', function (): void {
    $e = new MediaNotFoundException('vid-9');

    expect($e)->toBeInstanceOf(ScarlettPlayerException::class)
        ->and($e->mediaId)->toBe('vid-9')
        ->and($e->getMessage())->toContain('vid-9');
});

test('IncompleteMediaMappingException names the missing keys', function (): void {
    $e = IncompleteMediaMappingException::missingAttributes('App\\Models\\Video', ['is_protected']);

    expect($e)->toBeInstanceOf(ScarlettPlayerException::class)
        ->and($e->missing)->toBe(['media.attributes.is_protected'])
        ->and($e->getMessage())->toContain('media.attributes.is_protected')
        ->and($e->getMessage())->toContain('ScarlettMedia');
});

test('each named constructor records what is missing', function (): void {
    expect(IncompleteMediaMappingException::modelNotConfigured()->missing)->toBe(['media.model'])
        ->and(IncompleteMediaMappingException::modelClassMissing('Nope')->missing)->toBe(['media.model'])
        ->and(IncompleteMediaMappingException::modelClassMissing('Nope')->getMessage())->toContain('Nope')
        ->and(IncompleteMediaMappingException::missingValue('V', 'id-1', 'is_ppv', 'is_protected')->missing)
        ->toBe(['media.attributes.is_protected']);
});
