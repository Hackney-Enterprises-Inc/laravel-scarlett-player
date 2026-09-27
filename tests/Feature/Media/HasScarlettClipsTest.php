<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Concerns\HasScarlettClips;
use Hei\ScarlettPlayer\Models\Clip;
use Hei\ScarlettPlayer\Tests\Fixtures\Models\MediaSchema;
use Hei\ScarlettPlayer\Tests\Fixtures\Models\ScarlettVideo;
use Hei\ScarlettPlayer\Tests\Fixtures\Models\UuidVideo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    MediaSchema::create();
});

test('clips() is a morphMany of Clip on clippable_type and clippable_id', function (): void {
    $video = ScarlettVideo::query()->create(['uuid' => 'c0ffee00-0000-4000-8000-00000000000a']);
    $relation = $video->clips();

    expect($relation)->toBeInstanceOf(MorphMany::class)
        ->and($relation->getRelated())->toBeInstanceOf(Clip::class)
        ->and($relation->getMorphType())->toBe('clippable_type')
        ->and($relation->getForeignKeyName())->toBe('clippable_id')
        ->and($relation->getMorphClass())->toBe(ScarlettVideo::class)
        ->and($relation->getParentKey())->toBe((string) $video->getKey());
});

test('clips() returns the clips cut from the model', function (): void {
    $this->usesMigrations();
    $video = ScarlettVideo::query()->create(['uuid' => 'c0ffee00-0000-4000-8000-00000000000d']);
    $other = ScarlettVideo::query()->create(['uuid' => 'c0ffee00-0000-4000-8000-00000000000e']);

    $clip = $video->clips()->create([
        'uuid' => 'aaaaaaaa-0000-4000-8000-000000000001',
        'media_id' => $video->uuid,
        'client_request_id' => 'req-1',
        'start_seconds' => 10,
        'end_seconds' => 20,
        'duration_seconds' => 10,
    ]);

    expect($video->clips()->pluck('id')->all())->toBe([$clip->id])
        ->and($other->clips()->count())->toBe(0)
        ->and($clip->fresh()?->clippable?->is($video))->toBeTrue();
});

test('scarlettMediaId() returns the route key', function (): void {
    $video = ScarlettVideo::query()->create(['uuid' => 'c0ffee00-0000-4000-8000-00000000000b']);

    expect($video->scarlettMediaId())->toBe('c0ffee00-0000-4000-8000-00000000000b');
});

test('scarlettMediaId() follows a model that keeps the default route key', function (): void {
    $video = new class extends Model
    {
        use HasScarlettClips;

        protected $table = 'scarlett_videos';

        protected $guarded = [];

        public $timestamps = false;
    };

    $video = $video->newQuery()->create(['uuid' => 'c0ffee00-0000-4000-8000-00000000000c']);

    expect($video->scarlettMediaId())->toBe((string) $video->getKey());
});

/*
 * Aggregates and existence queries, on both key types. On sqlite these prove the SQL is
 * valid and the counts right; the Postgres varchar = integer case is proven by running
 * this file in the MySQL and Postgres CI job.
 */
describe('relationship queries', function (): void {
    beforeEach(function (): void {
        $this->usesMigrations();
        MediaSchema::createUuidVideos();
    });

    /**
     * @return array{0: Model, 1: Model} A model with two clips, and one with none.
     */
    function clippedPair(string $class): array
    {
        $attributes = $class === ScarlettVideo::class ? fn () => ['uuid' => (string) Str::uuid()] : fn () => ['title' => 'x'];
        $with = $class::query()->create($attributes());
        $without = $class::query()->create($attributes());

        foreach ([1, 2] as $n) {
            $with->clips()->create([
                'uuid' => (string) Str::uuid(),
                'media_id' => 'm',
                'client_request_id' => $class.'-'.$n,
                'start_seconds' => 0,
                'end_seconds' => 10,
                'duration_seconds' => 10,
            ]);
        }

        return [$with, $without];
    }

    test('withCount(clips) counts per model', function (string $class): void {
        [$with, $without] = clippedPair($class);

        $counts = $class::query()->withCount('clips')->get()->pluck('clips_count', $with->getKeyName())->all();

        expect($counts[$with->getKey()])->toBe(2)
            ->and($counts[$without->getKey()])->toBe(0);
    })->with(['integer key' => ScarlettVideo::class, 'uuid key' => UuidVideo::class]);

    test('has(clips) and whereHas(clips) find only the clipped model', function (string $class): void {
        [$with] = clippedPair($class);

        expect($class::query()->has('clips')->pluck($with->getKeyName())->all())->toBe([$with->getKey()])
            ->and($class::query()->has('clips', '>=', 2)->count())->toBe(1)
            ->and($class::query()->whereHas('clips', fn ($q) => $q->where('client_request_id', $class.'-1'))->count())->toBe(1)
            ->and($class::query()->doesntHave('clips')->count())->toBe(1);
    })->with(['integer key' => ScarlettVideo::class, 'uuid key' => UuidVideo::class]);

    test('eager loading binds the parent keys as strings, never as raw integers', function (string $class): void {
        [$with, $without] = clippedPair($class);
        DB::enableQueryLog();

        $models = $class::query()->with('clips')->get()->keyBy($with->getKeyName());

        $eager = collect(DB::getQueryLog())->first(fn (array $q): bool => str_contains($q['query'], 'scarlett_clips'));

        expect($models[$with->getKey()]->clips)->toHaveCount(2)
            ->and($models[$without->getKey()]->clips)->toHaveCount(0)
            ->and($eager['query'])->toContain('in (?, ?)')
            ->and(array_filter($eager['bindings'], 'is_int'))->toBe([]);
    })->with(['integer key' => ScarlettVideo::class, 'uuid key' => UuidVideo::class]);

    test('lazy loading and create() use the key as a string', function (): void {
        [$with] = clippedPair(ScarlettVideo::class);

        expect($with->clips()->getParentKey())->toBe((string) $with->getKey())
            ->and($with->clips()->first()?->clippable_id)->toBe((string) $with->getKey());
    });
});
