<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Tests\Fixtures\Models\Video;

test('isLive and isProtected have no default', function (string $name): void {
    $parameter = collect((new ReflectionClass(MediaSource::class))->getConstructor()?->getParameters() ?? [])
        ->first(fn (ReflectionParameter $p): bool => $p->getName() === $name);

    expect($parameter)->not->toBeNull()
        ->and($parameter->isDefaultValueAvailable())->toBeFalse()
        ->and($parameter->isOptional())->toBeFalse()
        ->and((string) $parameter->getType())->toBe('bool');
})->with(['isLive', 'isProtected']);

test('it cannot be constructed without isProtected', function (): void {
    // @phpstan-ignore-next-line argument.missing (the missing argument is the point)
    new MediaSource(id: 'a', playbackUrl: 'https://cdn.example.test/a.m3u8', isLive: false, duration: 10.0);
})->throws(ArgumentCountError::class);

test('it cannot be constructed without isLive', function (): void {
    // @phpstan-ignore-next-line argument.missing
    new MediaSource(id: 'a', playbackUrl: 'https://cdn.example.test/a.m3u8', isProtected: false, duration: 10.0);
})->throws(ArgumentCountError::class);

test('it is final and readonly', function (): void {
    $class = new ReflectionClass(MediaSource::class);

    expect($class->isFinal())->toBeTrue()
        ->and($class->isReadOnly())->toBeTrue();
});

test('optional fields default to null and meta to an empty array', function (): void {
    $source = new MediaSource(id: 'a', playbackUrl: 'https://cdn.example.test/a.m3u8', isLive: true, isProtected: false, duration: null);

    expect($source->sourceDisk)->toBeNull()
        ->and($source->sourcePath)->toBeNull()
        ->and($source->title)->toBeNull()
        ->and($source->poster)->toBeNull()
        ->and($source->model)->toBeNull()
        ->and($source->meta)->toBe([]);
});

test('it carries every field it is given', function (): void {
    $model = new Video;

    $source = new MediaSource(
        id: 'vid-1',
        playbackUrl: 'https://cdn.example.test/vid-1.m3u8',
        isLive: false,
        isProtected: true,
        duration: 61.5,
        sourceDisk: 'mezzanine',
        sourcePath: 'videos/vid-1.mp4',
        title: 'Title',
        poster: 'https://cdn.example.test/vid-1.jpg',
        model: $model,
        meta: ['tenant' => 7],
    );

    expect($source)
        ->id->toBe('vid-1')
        ->playbackUrl->toBe('https://cdn.example.test/vid-1.m3u8')
        ->isLive->toBeFalse()
        ->isProtected->toBeTrue()
        ->duration->toBe(61.5)
        ->sourceDisk->toBe('mezzanine')
        ->sourcePath->toBe('videos/vid-1.mp4')
        ->title->toBe('Title')
        ->poster->toBe('https://cdn.example.test/vid-1.jpg')
        ->model->toBe($model)
        ->meta->toBe(['tenant' => 7]);
});
