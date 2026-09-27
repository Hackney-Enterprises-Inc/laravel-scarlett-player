<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Contracts\ResolvesMedia;
use Hei\ScarlettPlayer\Contracts\ScarlettMedia;
use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Exceptions\MediaNotFoundException;
use Hei\ScarlettPlayer\Facades\ScarlettPlayer;
use Hei\ScarlettPlayer\Player\PlayerConfigBuilder;
use Hei\ScarlettPlayer\ScarlettPlayer as ScarlettPlayerRoot;
use Hei\ScarlettPlayer\Testing\FakeScarlett;
use Hei\ScarlettPlayer\Tests\Fixtures\Provider\ArrayResolver;
use Illuminate\Database\Eloquent\Model;

beforeEach(function (): void {
    $this->withScarlettConfig(['media.resolver' => ArrayResolver::class]);

    $this->resolver = app(ResolvesMedia::class);
    $this->resolver->add(ArrayResolver::source('video-1'));
});

it('resolves a mediaId through the bound resolver', function (): void {
    expect(app(ScarlettPlayerRoot::class)->resolve('video-1')->id)->toBe('video-1')
        ->and($this->resolver->calls)->toBe(['video-1']);
});

it('throws MediaNotFoundException carrying the id when the resolver returns null', function (): void {
    try {
        app(ScarlettPlayerRoot::class)->resolve('missing');
        $this->fail('Expected MediaNotFoundException.');
    } catch (MediaNotFoundException $e) {
        expect($e->mediaId)->toBe('missing');
    }
});

it('builds the player config for a mediaId', function (): void {
    $builder = app(ScarlettPlayerRoot::class)->for('video-1');

    expect($builder)->toBeInstanceOf(PlayerConfigBuilder::class)
        ->and($builder->media()->id)->toBe('video-1');
});

it('builds from a ScarlettMedia model without calling the resolver', function (): void {
    $model = new class extends Model implements ScarlettMedia
    {
        public function toScarlettMediaSource(): MediaSource
        {
            return ArrayResolver::source('from-model', isProtected: true);
        }

        public function scarlettMediaId(): string
        {
            return 'from-model';
        }
    };

    expect(app(ScarlettPlayerRoot::class)->for($model)->media()->isProtected)->toBeTrue()
        ->and($this->resolver->calls)->toBe([]);
});

it('resolves a plain model by its media.key attribute', function (): void {
    $model = (new class extends Model {})->forceFill(['id' => 7, 'uuid' => 'video-1']);

    expect(app(ScarlettPlayerRoot::class)->for($model)->media()->id)->toBe('video-1')
        ->and($this->resolver->calls)->toBe(['video-1']);
});

it('falls back to the route key when media.key is empty', function (): void {
    config()->set('scarlett-player.media.key', null);
    $this->resolver->add(ArrayResolver::source('7'));
    $model = (new class extends Model {})->forceFill(['id' => 7]);

    expect(app(ScarlettPlayerRoot::class)->for($model)->media()->id)->toBe('7');
});

it('throws for a model that resolves to nothing', function (): void {
    app(ScarlettPlayerRoot::class)->for((new class extends Model {})->forceFill(['uuid' => 'nope']));
})->throws(MediaNotFoundException::class);

it('forwards resolve() and for() through the facade', function (): void {
    expect(ScarlettPlayer::resolve('video-1')->id)->toBe('video-1')
        ->and(ScarlettPlayer::for('video-1')->media()->id)->toBe('video-1');
});

it('swaps the facade root for a FakeScarlett', function (): void {
    $fake = ScarlettPlayer::fake();

    expect($fake)->toBeInstanceOf(FakeScarlett::class)
        ->and(ScarlettPlayer::getFacadeRoot())->toBe($fake);

    ScarlettPlayer::resolve('video-1');

    $fake->assertResolved('video-1');
});
