<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Exceptions\IncompleteMediaMappingException;
use Hei\ScarlettPlayer\Media\ConfigModelResolver;
use Hei\ScarlettPlayer\Tests\Fixtures\Models\MediaSchema;
use Hei\ScarlettPlayer\Tests\Fixtures\Models\ScarlettVideo;
use Hei\ScarlettPlayer\Tests\Fixtures\Models\Video;
use Illuminate\Support\Facades\DB;

/**
 * @param  array<string, string|null>  $attributes
 */
function mapMedia(string $model, array $attributes = [], ?string $key = 'uuid'): ConfigModelResolver
{
    config()->set('scarlett-player.media.model', $model);
    config()->set('scarlett-player.media.key', $key);
    config()->set('scarlett-player.media.attributes', $attributes);

    return app(ConfigModelResolver::class);
}

/**
 * @return array<string, string|null>
 */
function fullMap(): array
{
    return [
        'playback_url' => 'hls_url',
        'is_live' => 'is_live',
        'is_protected' => 'is_ppv',
        'duration' => 'duration_seconds',
        'source_disk' => 'storage_disk',
        'source_path' => 'mezzanine_path',
        'title' => 'title',
        'poster' => 'poster_url',
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makeVideo(array $overrides = []): Video
{
    return Video::query()->create(array_merge([
        'uuid' => '0b9f7a3e-2d4c-4f51-9a8e-1c2b3d4e5f60',
        'slug' => 'opening-night',
        'hls_url' => 'https://cdn.example.test/opening-night.m3u8',
        'is_live' => false,
        'is_ppv' => true,
        'duration_seconds' => 5400.5,
        'storage_disk' => 's3-mezzanine',
        'mezzanine_path' => 'videos/opening-night.mp4',
        'title' => 'Opening Night',
        'poster_url' => 'https://cdn.example.test/opening-night.jpg',
    ], $overrides));
}

beforeEach(function (): void {
    MediaSchema::create();
});

describe('attribute map route', function (): void {
    test('it resolves every mapped column', function (): void {
        $video = makeVideo();

        $source = mapMedia(Video::class, fullMap())->resolve($video->uuid);

        expect($source)->toBeInstanceOf(MediaSource::class)
            ->id->toBe($video->uuid)
            ->playbackUrl->toBe('https://cdn.example.test/opening-night.m3u8')
            ->isLive->toBeFalse()
            ->isProtected->toBeTrue()
            ->duration->toBe(5400.5)
            ->sourceDisk->toBe('s3-mezzanine')
            ->sourcePath->toBe('videos/opening-night.mp4')
            ->title->toBe('Opening Night')
            ->poster->toBe('https://cdn.example.test/opening-night.jpg')
            ->meta->toBe([]);

        expect($source?->model)->toBeInstanceOf(Video::class)
            ->and($source?->model?->getKey())->toBe($video->getKey());
    });

    test('a disk: literal is used as the disk name, not read as a column', function (): void {
        $video = makeVideo();

        $source = mapMedia(Video::class, [...fullMap(), 'source_disk' => 'disk:mezzanine'])->resolve($video->uuid);

        expect($source?->sourceDisk)->toBe('mezzanine');
    });

    test('an empty disk: literal resolves no disk', function (): void {
        $video = makeVideo();

        expect(mapMedia(Video::class, [...fullMap(), 'source_disk' => 'disk:'])->resolve($video->uuid)?->sourceDisk)->toBeNull();
    });

    test('unmapped optional attributes resolve as null', function (): void {
        $video = makeVideo();

        $source = mapMedia(Video::class, [
            'playback_url' => 'hls_url',
            'is_live' => 'is_live',
            'is_protected' => 'is_ppv',
            'duration' => null,
            'source_disk' => null,
        ])->resolve($video->uuid);

        expect($source)->not->toBeNull()
            ->duration->toBeNull()
            ->sourceDisk->toBeNull()
            ->sourcePath->toBeNull()
            ->title->toBeNull()
            ->poster->toBeNull();
    });

    test('an unprotected, live record resolves with both flags as stored', function (): void {
        $video = makeVideo(['is_live' => true, 'is_ppv' => false, 'duration_seconds' => null]);

        $source = mapMedia(Video::class, fullMap())->resolve($video->uuid);

        expect($source)->isLive->toBeTrue()->isProtected->toBeFalse()->duration->toBeNull();
    });

    test('string flags are read strictly', function (): void {
        $video = makeVideo(['ppv_flag' => 'yes']);

        expect(mapMedia(Video::class, [...fullMap(), 'is_protected' => 'ppv_flag'])->resolve($video->uuid)?->isProtected)->toBeTrue();
    });

    test('media.key selects the lookup column', function (): void {
        $video = makeVideo();

        expect(mapMedia(Video::class, fullMap(), 'slug')->resolve('opening-night')?->model?->getKey())->toBe($video->getKey());
    });

    test('a null media.key falls back to the model route key', function (): void {
        $video = makeVideo();

        expect(mapMedia(Video::class, fullMap(), null)->resolve((string) $video->getKey())?->id)->toBe((string) $video->getKey());
    });
});

describe('fail closed', function (): void {
    test('a missing is_protected mapping throws and never resolves as unprotected', function (): void {
        $video = makeVideo(['is_ppv' => false]);
        $map = fullMap();
        unset($map['is_protected']);

        try {
            mapMedia(Video::class, $map)->resolve($video->uuid);
            $this->fail('An incomplete mapping resolved.');
        } catch (IncompleteMediaMappingException $e) {
            expect($e->missing)->toBe(['media.attributes.is_protected'])
                ->and($e->getMessage())->toContain('is_protected');
        }
    });

    test('a null is_protected mapping throws like a missing one', function (): void {
        $video = makeVideo();

        mapMedia(Video::class, [...fullMap(), 'is_protected' => null])->resolve($video->uuid);
    })->throws(IncompleteMediaMappingException::class, 'media.attributes.is_protected');

    test('a missing required mapping throws naming it', function (string $key): void {
        $video = makeVideo();
        $map = fullMap();
        unset($map[$key]);

        try {
            mapMedia(Video::class, $map)->resolve($video->uuid);
            $this->fail('An incomplete mapping resolved.');
        } catch (IncompleteMediaMappingException $e) {
            expect($e->missing)->toBe(["media.attributes.{$key}"]);
        }
    })->with(['playback_url', 'is_live', 'is_protected']);

    test('every missing required key is named at once', function (): void {
        $video = makeVideo();

        try {
            mapMedia(Video::class, ['title' => 'title'])->resolve($video->uuid);
            $this->fail('An incomplete mapping resolved.');
        } catch (IncompleteMediaMappingException $e) {
            expect($e->missing)->toBe([
                'media.attributes.playback_url',
                'media.attributes.is_live',
                'media.attributes.is_protected',
            ]);
        }
    });

    test('an incomplete mapping throws even for an unknown id', function (): void {
        mapMedia(Video::class, ['playback_url' => 'hls_url'])->resolve('no-such-id');
    })->throws(IncompleteMediaMappingException::class);

    test('a record with a null protection flag throws instead of assuming unprotected', function (): void {
        $video = makeVideo(['is_ppv' => null]);

        mapMedia(Video::class, fullMap())->resolve($video->uuid);
    })->throws(IncompleteMediaMappingException::class, 'is_ppv');

    test('a record with an unreadable protection flag throws', function (): void {
        $video = makeVideo(['ppv_flag' => 'maybe']);

        mapMedia(Video::class, [...fullMap(), 'is_protected' => 'ppv_flag'])->resolve($video->uuid);
    })->throws(IncompleteMediaMappingException::class, 'ppv_flag');

    test('a record with an empty-string protection flag throws instead of reading it as false', function (string $blank): void {
        $video = makeVideo(['ppv_flag' => $blank]);

        mapMedia(Video::class, [...fullMap(), 'is_protected' => 'ppv_flag'])->resolve($video->uuid);
    })->with(['empty' => '', 'spaces' => '  '])
        ->throws(IncompleteMediaMappingException::class, 'ppv_flag');

    test('a record with a null live flag throws', function (): void {
        $video = makeVideo(['is_live' => null]);

        mapMedia(Video::class, fullMap())->resolve($video->uuid);
    })->throws(IncompleteMediaMappingException::class, 'is_live');

    test('a record with no playback url throws', function (): void {
        $video = makeVideo(['hls_url' => null]);

        mapMedia(Video::class, fullMap())->resolve($video->uuid);
    })->throws(IncompleteMediaMappingException::class, 'hls_url');

    test('no configured model throws', function (): void {
        config()->set('scarlett-player.media.model', null);

        app(ConfigModelResolver::class)->resolve('x');
    })->throws(IncompleteMediaMappingException::class, 'media.model');

    test('a model class that is not an Eloquent model throws', function (): void {
        mapMedia(stdClass::class, fullMap())->resolve('x');
    })->throws(IncompleteMediaMappingException::class, 'stdClass');
});

describe('ScarlettMedia route', function (): void {
    test('a model implementing ScarlettMedia resolves through toScarlettMediaSource', function (): void {
        ScarlettVideo::query()->create(['uuid' => 'c0ffee00-0000-4000-8000-000000000001', 'is_ppv' => false]);

        // No attribute map at all: the contract is the whole mapping.
        $source = mapMedia(ScarlettVideo::class)->resolve('c0ffee00-0000-4000-8000-000000000001');

        expect($source)->not->toBeNull()
            ->id->toBe('c0ffee00-0000-4000-8000-000000000001')
            ->playbackUrl->toBe('https://cdn.example.test/signed/c0ffee00-0000-4000-8000-000000000001.m3u8')
            ->isProtected->toBeTrue()
            ->title->toBe('From the model')
            ->meta->toBe(['route' => 'contract']);
    });

    test('the contract wins over an attribute map', function (): void {
        ScarlettVideo::query()->create(['uuid' => 'c0ffee00-0000-4000-8000-000000000002', 'hls_url' => 'https://cdn.example.test/column.m3u8', 'is_live' => false, 'is_ppv' => false]);

        $source = mapMedia(ScarlettVideo::class, fullMap())->resolve('c0ffee00-0000-4000-8000-000000000002');

        expect($source?->playbackUrl)->toContain('/signed/')
            ->and($source?->isProtected)->toBeTrue();
    });
});

describe('unknown ids', function (): void {
    test('an unknown id returns null on the attribute map route', function (): void {
        makeVideo();

        expect(mapMedia(Video::class, fullMap())->resolve('no-such-id'))->toBeNull();
    });

    test('an unknown id returns null on the ScarlettMedia route', function (): void {
        expect(mapMedia(ScarlettVideo::class)->resolve('no-such-id'))->toBeNull();
    });

    // On Postgres the uuid column rejects 'no-such-id' outright (SQLSTATE 22P02); inside
    // a host transaction that failed statement would abort the whole transaction.
    test('a malformed id inside a host transaction returns null and leaves it usable', function (): void {
        $video = makeVideo();

        DB::transaction(function () use ($video): void {
            expect(mapMedia(Video::class, fullMap())->resolve('no-such-id'))->toBeNull()
                ->and(Video::query()->whereKey($video->getKey())->exists())->toBeTrue();
        });
    });
});

describe('assertMappingComplete', function (): void {
    test('it passes for a ScarlettMedia model with no attribute map', function (): void {
        mapMedia(ScarlettVideo::class)->assertMappingComplete();

        expect(true)->toBeTrue();
    });

    test('it passes for a complete attribute map without any record', function (): void {
        mapMedia(Video::class, ['playback_url' => 'hls_url', 'is_live' => 'is_live', 'is_protected' => 'is_ppv'])->assertMappingComplete();

        expect(Video::query()->count())->toBe(0);
    });

    test('it fails naming a missing required attribute', function (string $key): void {
        $map = fullMap();
        unset($map[$key]);

        mapMedia(Video::class, $map)->assertMappingComplete();
    })->with(['playback_url', 'is_live', 'is_protected'])
        ->throws(IncompleteMediaMappingException::class);

    test('it fails when no model is configured', function (): void {
        config()->set('scarlett-player.media.model', null);

        app(ConfigModelResolver::class)->assertMappingComplete();
    })->throws(IncompleteMediaMappingException::class, 'media.model');

    test('it fails when the model class does not exist', function (): void {
        mapMedia('App\\Models\\DoesNotExist', fullMap())->assertMappingComplete();
    })->throws(IncompleteMediaMappingException::class, 'App\\Models\\DoesNotExist');

    test('it fails when the attribute map is not an array', function (): void {
        config()->set('scarlett-player.media.model', Video::class);
        config()->set('scarlett-player.media.attributes', 'hls_url');

        app(ConfigModelResolver::class)->assertMappingComplete();
    })->throws(IncompleteMediaMappingException::class, 'media.attributes.is_protected');
});
