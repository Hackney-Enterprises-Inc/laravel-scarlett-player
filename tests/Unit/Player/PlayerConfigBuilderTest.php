<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Exceptions\ClipPolicyMissingException;
use Hei\ScarlettPlayer\Exceptions\InvalidPlayerConfigException;
use Hei\ScarlettPlayer\Exceptions\ScarlettPlayerException;
use Hei\ScarlettPlayer\Exceptions\UnsupportedInEmbedMode;
use Hei\ScarlettPlayer\Facades\ScarlettPlayer;
use Hei\ScarlettPlayer\Models\Clip;
use Hei\ScarlettPlayer\Player\FeatureMatrix;
use Hei\ScarlettPlayer\Player\PlayerConfigBuilder;
use Hei\ScarlettPlayer\Policies\ClipPolicy;
use Hei\ScarlettPlayer\Tests\Fixtures\Provider\ArrayResolver;
use Hei\ScarlettPlayer\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/** A host policy that decides clip creation itself, which is what "published" means. */
class PlayerTestHostClipPolicy
{
    public function create(): bool
    {
        return true;
    }
}

/** Inherits the package default's create(), so it does not decide clip creation. */
class PlayerTestInheritingClipPolicy extends ClipPolicy {}

beforeEach(function (): void {
    ScarlettPlayer::fake()->withMedia(
        ArrayResolver::source('video-1'),
        ArrayResolver::source('paid-1', isProtected: true),
    );

    // The clips module owns routes/clips.php; until its store route exists, stand
    // one in under the same name so the builder has something to point at.
    if (! Route::has('scarlett.clips.store')) {
        Route::post('api/scarlett/clips', fn (): string => '')->name('scarlett.clips.store');
        Route::getRoutes()->refreshNameLookups();
    }
});

it('emits schema version 1 with the media source and defaults', function (): void {
    $config = ScarlettPlayer::for('video-1')->toArray();

    expect($config)->toMatchArray([
        'scarlettConfigVersion' => 1,
        'mode' => 'module',
        'playerVersion' => '1.17.0',   // the shipped pin
        'mediaId' => 'video-1',
        'source' => ['src' => 'https://media.example.test/video-1.m3u8', 'isLive' => false, 'duration' => 120.0],
        'poster' => null,
        'title' => null,
        'playback' => ['autoplay' => false, 'muted' => false, 'loop' => false, 'controls' => true, 'startTime' => null],
        'brand' => ['color' => null, 'textColor' => null],
        'analytics' => null,
        'clips' => null,
        'chapters' => null,
        'captions' => null,
        'share' => null,
    ])->and($config)->not->toHaveKey('embed');
});

it('applies playback, poster, title and brand settings', function (): void {
    $config = ScarlettPlayer::for('video-1')
        ->autoplay()->muted()->loop()->controls(false)->startTime(42)
        ->poster('https://img.example.test/p.jpg')->title('Main event')
        ->brandColor('#e50914')->brandTextColor('#ffffff')
        ->toArray();

    expect($config['playback'])->toBe(['autoplay' => true, 'muted' => true, 'loop' => true, 'controls' => false, 'startTime' => 42.0])
        ->and($config['poster'])->toBe('https://img.example.test/p.jpg')
        ->and($config['title'])->toBe('Main event')
        ->and($config['brand'])->toBe(['color' => '#e50914', 'textColor' => '#ffffff']);
});

it('treats a zero or negative start time and empty colours as unset', function (): void {
    $config = ScarlettPlayer::for('video-1')->startTime(0)->brandColor('')->toArray();

    expect($config['playback']['startTime'])->toBeNull()
        ->and($config['brand']['color'])->toBeNull()
        ->and(ScarlettPlayer::for('video-1')->startTime(-3)->toArray()['playback']['startTime'])->toBeNull();
});

it('wires analytics from the beacon route, the media id and the beacon key', function (): void {
    expect(ScarlettPlayer::for('video-1')->withAnalytics()->toArray()['analytics'])->toBe([
        'beaconUrl' => route('scarlett.beacons.store'),
        'videoId' => 'video-1',
        'apiKey' => TestCase::BEACON_KEY,
        'videoTitle' => null,
        'isLive' => false,
    ]);
});

it('carries isLive from the MediaSource into the analytics block', function (bool $live): void {
    ScarlettPlayer::fake()->withMedia(new MediaSource(
        id: 'stream-1',
        playbackUrl: 'https://media.example.test/stream-1.m3u8',
        isLive: $live,
        isProtected: false,
        duration: $live ? null : 60.0,
    ));

    expect(ScarlettPlayer::for('stream-1')->withAnalytics()->toArray()['analytics']['isLive'])->toBe($live);
})->with(['live' => true, 'vod' => false]);

it('emits no isLive data attribute in embed mode, where the embed documents none', function (): void {
    $attributes = ScarlettPlayer::for('video-1')->mode('embed')->withAnalytics()->toDataAttributes();

    expect(array_filter(array_keys($attributes), fn (string $name): bool => str_contains($name, 'live')))->toBe([])
        ->and(FeatureMatrix::supports('analytics_live', 'embed', '1.16.2'))->toBeFalse();
});

it('sends no api key when none is configured', function (): void {
    config()->set('scarlett-player.beacons.key', '');

    expect(ScarlettPlayer::for('video-1')->withAnalytics()->toArray()['analytics']['apiKey'])->toBeNull();
});

it('refuses analytics when the beacon route is off', function (): void {
    $this->withScarlettConfig(['routes.beacons' => false]);
    ScarlettPlayer::fake()->withMedia(ArrayResolver::source('video-1'));

    ScarlettPlayer::for('video-1')->withAnalytics();
})->throws(InvalidPlayerConfigException::class, 'scarlett.beacons.store');

it('wires clips from the clip route with csrf and the configured bounds', function (): void {
    expect(ScarlettPlayer::for('video-1')->withClips()->toArray()['clips'])->toBe([
        'endpoint' => ['url' => route('scarlett.clips.store', absolute: false), 'csrf' => true],
        'mediaId' => 'video-1',
        'minDuration' => 5,
        'maxDuration' => 60,
    ]);
});

it('refuses clips on protected media with no Clip policy', function (): void {
    ScarlettPlayer::for('paid-1')->withClips();
})->throws(ClipPolicyMissingException::class, 'paid-1');

it('refuses clips on protected media under the package default policy', function (): void {
    expect(Gate::getPolicyFor(Clip::class))->toBeInstanceOf(ClipPolicy::class);

    ScarlettPlayer::for('paid-1')->withClips();
})->throws(ClipPolicyMissingException::class);

it('allows clips on protected media once a host policy with create() is registered', function (): void {
    Gate::policy(Clip::class, PlayerTestHostClipPolicy::class);

    expect(ScarlettPlayer::for('paid-1')->withClips()->toArray()['clips']['mediaId'])->toBe('paid-1');
});

it('refuses clips on protected media under a host subclass that inherits create()', function (): void {
    Gate::policy(Clip::class, PlayerTestInheritingClipPolicy::class);

    ScarlettPlayer::for('paid-1')->withClips();
})->throws(ClipPolicyMissingException::class);

it('emits the clips endpoint as a path and the beacon URL as an absolute URL', function (): void {
    $config = ScarlettPlayer::for('video-1')->withClips()->withAnalytics()->toArray();

    expect($config['clips']['endpoint']['url'])->toBe('/api/scarlett/clips')
        ->and($config['analytics']['beaconUrl'])->toStartWith('http://localhost/');
});

it('emits chapters in the player Chapter shape, or takes a chapters file URL', function (): void {
    $config = ScarlettPlayer::for('video-1')->withChapters([
        ['time' => 0, 'label' => 'Intro'],
        ['time' => '90.5', 'label' => 'Round 1', 'endTime' => 300, 'subtitle' => 'Main card', 'ignored' => true],
        ['time' => 400, 'label' => 'Round 2', 'end' => 500, 'thumbnail' => 'https://img.example.test/r2.jpg'],
    ])->toArray();

    expect($config['chapters'])->toBe(['chapters' => [
        ['time' => 0.0, 'label' => 'Intro'],
        ['time' => 90.5, 'label' => 'Round 1', 'endTime' => 300.0, 'subtitle' => 'Main card'],
        // 'end' is an input alias; the wire name is endTime.
        ['time' => 400.0, 'label' => 'Round 2', 'endTime' => 500.0, 'thumbnail' => 'https://img.example.test/r2.jpg'],
    ]])->and(ScarlettPlayer::for('video-1')->withChapters('https://cdn.example.test/ch.vtt')->toArray()['chapters'])
        ->toBe(['src' => 'https://cdn.example.test/ch.vtt']);
});

it('emits only keys the player Chapter and CaptionSource interfaces declare', function (): void {
    $config = ScarlettPlayer::for('video-1')
        ->withChapters([['time' => 0, 'label' => 'A', 'end' => 5, 'subtitle' => 's', 'thumbnail' => 't', 'extra' => 1]])
        ->withCaptions([['language' => 'en', 'label' => 'English', 'src' => 'https://c.test/en.vtt', 'kind' => 'captions', 'default' => true, 'extra' => 1]])
        ->toArray();

    // @scarlett-player/core Chapter and @scarlett-player/captions CaptionSource, 1.17.0 (unchanged since 1.16.2).
    $chapterKeys = ['time', 'label', 'endTime', 'subtitle', 'thumbnail', 'metadata'];
    $captionKeys = ['language', 'label', 'src', 'kind', 'default'];

    expect(array_diff(array_keys($config['chapters']['chapters'][0]), $chapterKeys))->toBe([])
        ->and(array_diff(array_keys($config['captions']['sources'][0]), $captionKeys))->toBe([]);
});

it('rejects a malformed chapter', function (): void {
    ScarlettPlayer::for('video-1')->withChapters([['label' => 'No time']]);
})->throws(InvalidPlayerConfigException::class, 'chapter entry [0]');

it('normalises caption tracks', function (): void {
    $config = ScarlettPlayer::for('video-1')->withCaptions([
        ['language' => 'en', 'label' => 'English', 'src' => 'https://cdn.example.test/en.vtt', 'kind' => 'captions', 'default' => true],
        ['language' => 'es', 'label' => 'Espanol', 'src' => 'https://cdn.example.test/es.vtt', 'kind' => 'bogus'],
    ])->toArray();

    expect($config['captions'])->toBe(['sources' => [
        ['language' => 'en', 'label' => 'English', 'src' => 'https://cdn.example.test/en.vtt', 'kind' => 'captions', 'default' => true],
        ['language' => 'es', 'label' => 'Espanol', 'src' => 'https://cdn.example.test/es.vtt'],
    ]]);
});

it('rejects a caption track without a src', function (): void {
    ScarlettPlayer::for('video-1')->withCaptions([['language' => 'en', 'label' => 'English']]);
})->throws(InvalidPlayerConfigException::class, 'needs a string src');

it('adds share with the embed URL as the embed base, or without it', function (): void {
    expect(ScarlettPlayer::for('video-1')->title('T')->withShare('https://host.test/watch/1')->toArray()['share'])->toBe([
        'url' => 'https://host.test/watch/1',
        'title' => 'T',
        'embedBaseUrl' => ScarlettPlayer::embedUrl('video-1'),
    ])->and(ScarlettPlayer::for('video-1')->withShare('https://host.test/watch/1', embed: false)->toArray()['share']['embedBaseUrl'])
        ->toBeNull();
});

it('throws UnsupportedInEmbedMode for clips, chapters and captions in embed mode', function (Closure $enable): void {
    $enable(ScarlettPlayer::for('video-1')->mode('embed'));
})->with([
    'clips' => [fn (PlayerConfigBuilder $b) => $b->withClips()],
    'chapters' => [fn (PlayerConfigBuilder $b) => $b->withChapters([])],
    'captions' => [fn (PlayerConfigBuilder $b) => $b->withCaptions([])],
])->throws(UnsupportedInEmbedMode::class, "->mode('module')");

it('throws when switching to embed after enabling an unsupported feature', function (): void {
    ScarlettPlayer::for('video-1')->withClips()->mode('embed');
})->throws(UnsupportedInEmbedMode::class, 'clips');

it('names the feature and version on UnsupportedInEmbedMode', function (): void {
    $e = new UnsupportedInEmbedMode('chapters', '1.16.2');

    expect($e)->toBeInstanceOf(ScarlettPlayerException::class)
        ->and($e->feature)->toBe('chapters')
        ->and($e->playerVersion)->toBe('1.16.2')
        ->and($e->getMessage())->toContain('module mode');
});

it('defaults to player.mode and rejects an unknown mode', function (): void {
    config()->set('scarlett-player.player.mode', 'embed');

    expect(ScarlettPlayer::for('video-1')->currentMode())->toBe('embed');

    ScarlettPlayer::for('video-1')->mode('iframe');
})->throws(InvalidPlayerConfigException::class, 'iframe');

it('adds the pinned embed bundle in embed mode', function (): void {
    config()->set('scarlett-player.player.cdn_url', 'https://cdn.example.test/scarlett-player/');

    expect(ScarlettPlayer::for('video-1')->mode('embed')->toArray()['embed'])
        ->toBe(['bundleUrl' => 'https://cdn.example.test/scarlett-player/v'.config('scarlett-player.player.player_version').'/embed.js']);
});

it('renders embed_bundle for a CDN on /latest/ or another file name', function (): void {
    config()->set('scarlett-player.player.cdn_url', 'https://cdn.example.test/sp');
    config()->set('scarlett-player.player.embed_bundle', '{cdn_url}/latest/embed.video.umd.cjs');

    expect(ScarlettPlayer::for('video-1')->embedBundleUrl())->toBe('https://cdn.example.test/sp/latest/embed.video.umd.cjs');

    config()->set('scarlett-player.player.embed_bundle', 'https://static.example.test/{player_version}/e.js');
    config()->set('scarlett-player.player.cdn_url', null);

    expect(ScarlettPlayer::for('video-1')->embedBundleUrl())->toBe('https://static.example.test/'.config('scarlett-player.player.player_version').'/e.js');
});

it('ships the versioned layout as the embed_bundle default', function (): void {
    expect(config('scarlett-player.player.embed_bundle'))->toBe('{cdn_url}/v{player_version}/embed.js')
        ->and(PlayerConfigBuilder::DEFAULT_EMBED_BUNDLE)->toBe('{cdn_url}/v{player_version}/embed.js');
});

it('loads the ES module build as a module and the UMD build as a classic script', function (): void {
    config()->set('scarlett-player.player.cdn_url', 'https://cdn.example.test/sp');

    expect(ScarlettPlayer::for('video-1')->embedBundleIsModule())->toBeTrue();

    config()->set('scarlett-player.player.embed_bundle', '{cdn_url}/latest/embed.umd.cjs');

    expect(ScarlettPlayer::for('video-1')->embedBundleIsModule())->toBeFalse();

    config()->set('scarlett-player.player.embed_bundle', 'https://static.example.test/embed.umd.cjs?v=2');

    expect(ScarlettPlayer::for('video-1')->embedBundleIsModule())->toBeFalse();
});

it('refuses the embed bundle without a CDN URL', function (): void {
    ScarlettPlayer::for('video-1')->embedBundleUrl();
})->throws(InvalidPlayerConfigException::class, 'SCARLETT_CDN_URL');

it('maps to embed data attributes using only README names', function (): void {
    $attributes = ScarlettPlayer::for('video-1')->mode('embed')
        ->autoplay()->muted()->controls(false)->startTime(12.5)->title('Main event')
        ->brandColor('#e50914')->withAnalytics()->withShare('https://host.test/watch/1')
        ->toDataAttributes();

    expect($attributes)->toBe([
        'data-scarlett-player' => '',
        'data-src' => 'https://media.example.test/video-1.m3u8',
        'data-title' => 'Main event',
        'data-autoplay' => 'true',
        'data-muted' => 'true',
        'data-controls' => 'false',
        'data-start-time' => '12.5',
        'data-brand-color' => '#e50914',
        'data-share-url' => 'https://host.test/watch/1',
        'data-embed-base-url' => ScarlettPlayer::embedUrl('video-1'),
        'data-analytics-beacon-url' => route('scarlett.beacons.store'),
        'data-analytics-video-id' => 'video-1',
        'data-analytics-api-key' => TestCase::BEACON_KEY,
    ]);

    // The published embed README for the pinned player (tests/Fixtures/embed/attributes/).
    $readme = (string) file_get_contents(__DIR__.'/../../Fixtures/embed/attributes/1.17.0/README.md');

    foreach (array_keys($attributes) as $name) {
        expect($readme)->toContain("`{$name}`");
    }
});

it('serialises to JSON with the same content', function (): void {
    $builder = ScarlettPlayer::for('video-1')->autoplay();

    expect(json_decode($builder->toJson(), true))->toBe($builder->toArray())
        ->and($builder->jsonSerialize())->toBe($builder->toArray())
        ->and($builder->media()->id)->toBe('video-1');
});
