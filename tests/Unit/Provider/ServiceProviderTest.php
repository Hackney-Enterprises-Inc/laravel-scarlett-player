<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Contracts\BeaconStore;
use Hei\ScarlettPlayer\Contracts\ResolvesMedia;
use Hei\ScarlettPlayer\Doctor\CheckRegistry;
use Hei\ScarlettPlayer\Doctor\Checks\BeaconKeyCheck;
use Hei\ScarlettPlayer\Doctor\Checks\BeaconStoreCheck;
use Hei\ScarlettPlayer\Doctor\Checks\EmbedBundleCheck;
use Hei\ScarlettPlayer\Doctor\Checks\MediaMappingCheck;
use Hei\ScarlettPlayer\Doctor\Checks\QueueConnectionCheck;
use Hei\ScarlettPlayer\Generators\ClipGeneratorManager;
use Hei\ScarlettPlayer\Media\ConfigModelResolver;
use Hei\ScarlettPlayer\Player\PlayerConfigBuilder;
use Hei\ScarlettPlayer\ScarlettPlayer;
use Hei\ScarlettPlayer\ScarlettPlayerServiceProvider;
use Hei\ScarlettPlayer\Tests\Fixtures\Provider\ArrayResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;

it('merges the package config with the sketch defaults', function (): void {
    expect(config('scarlett-player.routes.prefix'))->toBe('api/scarlett')
        ->and(config('scarlett-player.routes.middleware.beacons'))->toBe(['api', 'throttle:scarlett-beacons'])
        ->and(config('scarlett-player.routes.middleware.clips'))->toBe(['web', 'auth', 'throttle:scarlett-clips'])
        ->and(config('scarlett-player.media.resolver'))->toBeNull()
        ->and(config('scarlett-player.media.key'))->toBe('uuid')
        ->and(config('scarlett-player.beacons.queue'))->toBe('scarlett-beacons')
        ->and(config('scarlett-player.beacons.throttle'))->toBe('600,1')
        ->and(config('scarlett-player.clips.queue'))->toBe('scarlett-clips')
        ->and(config('scarlett-player.clips.generators.local-ffmpeg.timeout'))->toBe(300)
        ->and(config('scarlett-player.player.player_version'))->toBe('1.22.0')
        ->and(config('scarlett-player.embed.unsigned_params'))->toBe(['startTime', 'shareUrl', 'autoplay', 'muted']);
});

it('binds ConfigModelResolver when media.resolver is null', function (): void {
    expect(app(ResolvesMedia::class))->toBeInstanceOf(ConfigModelResolver::class);
});

it('binds the resolver class named in media.resolver', function (): void {
    $this->withScarlettConfig(['media.resolver' => ArrayResolver::class]);

    expect(app(ResolvesMedia::class))->toBeInstanceOf(ArrayResolver::class)
        ->and(app(ResolvesMedia::class))->toBe(app(ResolvesMedia::class));
});

it('binds the facade root as a singleton with an alias', function (): void {
    expect(app(ScarlettPlayer::class))->toBe(app(ScarlettPlayer::class))
        ->and(app('scarlett-player'))->toBe(app(ScarlettPlayer::class));
});

it('binds the per-module placeholders', function (): void {
    // The modules replace the scaffold placeholders; only the contracts are fixed here.
    expect(app(BeaconStore::class))->toBeInstanceOf(BeaconStore::class)
        ->and(app(ClipGeneratorManager::class))->toBe(app(ClipGeneratorManager::class))
        ->and(app(ClipGeneratorManager::class)->getDefaultDriver())->toBe('local-ffmpeg');
});

it('builds a PlayerConfigBuilder only with a media parameter', function (): void {
    $source = ArrayResolver::source('video-1');

    expect(app()->make(PlayerConfigBuilder::class, ['media' => $source])->media())->toBe($source);

    app()->make(PlayerConfigBuilder::class);
})->throws(InvalidArgumentException::class, 'MediaSource');

it('publishes under the four scarlett tags', function (string $tag): void {
    expect(ServiceProvider::pathsToPublish(ScarlettPlayerServiceProvider::class, $tag))->not->toBeEmpty();
})->with(['scarlett-config', 'scarlett-migrations', 'scarlett-views', 'scarlett-js']);

it('registers the view namespace and the Blade component namespace', function (): void {
    expect(app('view')->getFinder()->getHints())->toHaveKey('scarlett')
        ->and(app(BladeCompiler::class)->getClassComponentNamespaces())
        ->toHaveKey('scarlett', 'Hei\\ScarlettPlayer\\View\\Components');
});

it('registers the scarlett-beacons limiter from beacons.throttle, keyed by ip', function (): void {
    $limit = RateLimiter::limiter('scarlett-beacons')(Request::create('/', 'POST', server: ['REMOTE_ADDR' => '10.0.0.9']));

    expect($limit)->toBeInstanceOf(Limit::class)
        ->and($limit->maxAttempts)->toBe(600)
        ->and($limit->decaySeconds)->toBe(60)
        ->and($limit->key)->toBe('10.0.0.9');
});

it('registers the scarlett-clips limiter from clips.throttle, keyed by user then ip', function (): void {
    $guest = Request::create('/', 'POST', server: ['REMOTE_ADDR' => '10.0.0.9']);
    $limit = RateLimiter::limiter('scarlett-clips')($guest);

    expect($limit->maxAttempts)->toBe(10)
        ->and($limit->decaySeconds)->toBe(60)
        ->and($limit->key)->toBe('10.0.0.9');

    $user = Mockery::mock(Authenticatable::class);
    $user->shouldReceive('getAuthIdentifier')->andReturn(42);
    $authed = Request::create('/', 'POST');
    $authed->setUserResolver(fn () => $user);

    expect(RateLimiter::limiter('scarlett-clips')($authed)->key)->toBe('42');
});

it('reads a throttle with no minutes as per minute', function (): void {
    $this->withScarlettConfig(['beacons.throttle' => '30', 'clips.throttle' => '5,10']);

    expect(RateLimiter::limiter('scarlett-beacons')(Request::create('/'))->decaySeconds)->toBe(60)
        ->and(RateLimiter::limiter('scarlett-beacons')(Request::create('/'))->maxAttempts)->toBe(30)
        ->and(RateLimiter::limiter('scarlett-clips')(Request::create('/'))->decaySeconds)->toBe(600);
});

it('fills the doctor registry with the shared checks first, then each module\'s', function (): void {
    $classes = app(CheckRegistry::class)->classes();

    expect(array_slice($classes, 0, 2))->toBe([MediaMappingCheck::class, QueueConnectionCheck::class])
        ->and($classes)->toContain(BeaconKeyCheck::class, BeaconStoreCheck::class, EmbedBundleCheck::class);
});
