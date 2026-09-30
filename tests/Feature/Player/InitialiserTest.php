<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Facades\ScarlettPlayer;
use Hei\ScarlettPlayer\ScarlettPlayerServiceProvider;
use Hei\ScarlettPlayer\Tests\Fixtures\Provider\ArrayResolver;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Run resources/js/init.js on a host config through the Node harness, with every
 *
 * @scarlett-player/* import stubbed, and return what createPlayer() was given.
 *
 * @param  array<string, mixed>  $config
 * @return array<string, mixed>
 */
function runInitialiser(array $config): array
{
    $file = tempnam(sys_get_temp_dir(), 'scarlett-config-');
    file_put_contents($file, json_encode($config, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));

    $command = sprintf(
        'node %s %s %s 2>&1',
        escapeshellarg(__DIR__.'/../../Fixtures/embed/init/harness.mjs'),
        escapeshellarg(__DIR__.'/../../../resources/js/init.js'),
        escapeshellarg($file),
    );

    $output = (string) shell_exec($command);
    unlink($file);

    /** @var array<string, mixed> */
    return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Run init.js in the fake page harness for one scenario.
 *
 * @param  array<string, mixed>  $config
 * @return array{state: string|null, created: int, plugins: list<array{factory: string, config: array<string, mixed>}>}
 */
function runPage(array $config, string $scenario): array
{
    $file = tempnam(sys_get_temp_dir(), 'scarlett-config-');
    file_put_contents($file, json_encode($config, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));

    $output = (string) shell_exec(sprintf(
        'node %s %s %s %s 2>&1',
        escapeshellarg(__DIR__.'/../../Fixtures/embed/init/page-harness.mjs'),
        escapeshellarg(__DIR__.'/../../../resources/js/init.js'),
        escapeshellarg($file),
        escapeshellarg($scenario),
    ));
    unlink($file);

    /** @var array{state: string|null, created: int, plugins: list<array{factory: string, config: array<string, mixed>}>} */
    return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    if (! binaryOnPath('node')) {
        $this->markTestSkipped('node is not on PATH; the initialiser harness needs it.');
    }

    ScarlettPlayer::fake()->withMedia(ArrayResolver::source('video-1'));

    if (! Route::has('scarlett.clips.store')) {
        Route::post('api/scarlett/clips', fn (): string => '')->name('scarlett.clips.store');
        Route::getRoutes()->refreshNameLookups();
    }
});

it('publishes the initialiser under scarlett-js', function (): void {
    $paths = ServiceProvider::pathsToPublish(ScarlettPlayerServiceProvider::class, 'scarlett-js');

    expect(array_keys($paths)[0])->toEndWith('resources/js/init.js')
        ->and(array_keys($paths)[0])->toBeFile()
        ->and(array_values($paths)[0])->toEndWith('js/vendor/scarlett-player/init.js');
});

it('turns a full module config into core options and plugins', function (): void {
    $result = runInitialiser(ScarlettPlayer::for('video-1')
        ->autoplay()->muted()->poster('https://img.example.test/p.jpg')->title('Main event')
        ->brandColor('#e50914')
        ->withAnalytics()->withClips()
        ->withChapters([['time' => 0, 'label' => 'Intro']])
        ->withCaptions([['language' => 'en', 'label' => 'English', 'src' => 'https://cdn.example.test/en.vtt']])
        ->withShare('https://host.test/watch/1', embed: false)
        ->toArray());

    expect($result['ok'])->toBeTrue()
        ->and($result['version'])->toBe(1);

    $options = $result['options'];
    $plugins = collect($options['plugins'])->keyBy('factory');

    expect($options)->toMatchArray([
        'container' => 'container',
        'src' => 'https://media.example.test/video-1.m3u8',
        'poster' => 'https://img.example.test/p.jpg',
        'autoplay' => true,
        'muted' => true,
        'loop' => false,
    ])->and(collect($options['plugins'])->pluck('factory')->all())
        ->toBe(['hls', 'native', 'captions', 'chapters', 'analytics', 'clips', 'share', 'ui'])
        ->and($plugins['analytics']['config'])->toBe([
            'beaconUrl' => route('scarlett.beacons.store'),
            'videoId' => 'video-1',
            'isLive' => false,
            'apiKey' => 'test-beacon-key',
            'videoTitle' => 'Main event',
            'anonymous' => false,
            'respectDoNotTrack' => false,
        ])
        ->and($plugins['clips']['config'])->toBe([
            'mediaId' => 'video-1',
            'minDuration' => 5,
            'maxDuration' => 60,
            'endpoint' => [
                'url' => route('scarlett.clips.store', absolute: false),
                'headers' => ['called' => ['X-CSRF-TOKEN' => 'csrf-from-meta']],
            ],
        ])
        ->and($plugins['chapters']['config'])->toBe(['chapters' => [['time' => 0, 'label' => 'Intro']]])
        ->and($plugins['captions']['config']['sources'][0]['language'])->toBe('en')
        ->and($plugins['share']['config'])->toBe(['url' => 'https://host.test/watch/1', 'title' => 'Main event'])
        ->and($plugins['ui']['config']['theme'])->toBe(['accentColor' => '#e50914', 'accentTextColor' => 'tone(#e50914)'])
        ->and($plugins['ui']['config']['controls'])->toContain('clip', 'share');
});

it('builds only the providers and the ui for a bare config', function (): void {
    $result = runInitialiser(ScarlettPlayer::for('video-1')->toArray());

    expect(collect($result['options']['plugins'])->pluck('factory')->all())->toBe(['hls', 'native', 'ui'])
        ->and($result['options']['plugins'][2]['config'])->toBe([]);
});

it('leaves the ui plugin out when controls are off', function (): void {
    $result = runInitialiser(ScarlettPlayer::for('video-1')->controls(false)->toArray());

    expect(collect($result['options']['plugins'])->pluck('factory')->all())->toBe(['hls', 'native']);
});

it('refuses a config from another schema version or from embed mode', function (): void {
    $config = ScarlettPlayer::for('video-1')->toArray();

    expect(runInitialiser([...$config, 'scarlettConfigVersion' => 2]))->toMatchArray(['ok' => false])
        ->and(runInitialiser([...$config, 'scarlettConfigVersion' => 2])['error'])->toContain('version 2 is not supported')
        ->and(runInitialiser([...$config, 'mode' => 'embed'])['error'])->toContain('module-mode config');
});

it('carries window.scarlettPlayerOptions into the clips endpoint and the analytics headers', function (string $scenario): void {
    $result = runPage(ScarlettPlayer::for('video-1')->withClips()->withAnalytics()->toArray(), $scenario);
    $plugins = collect($result['plugins'])->keyBy('factory');

    expect($result['created'])->toBe(1)
        ->and($result['state'])->toBe('true')
        ->and($plugins['clips']['config']['endpoint'])->toBe([
            'url' => route('scarlett.clips.store', absolute: false),
            'credentials' => 'include',
            // The host's header is added to the CSRF header, not swapped for it.
            'headers' => ['called' => ['X-CSRF-TOKEN' => 'csrf-from-meta', 'Authorization' => 'Bearer host-token']],
        ])
        ->and($plugins['analytics']['config']['headers'])->toBe(['called' => ['X-Tenant' => 'acme']]);
})->with([
    'host bundle loaded before the component' => 'inline',
    'component rendered before the host bundle' => 'pending',
]);

it('starts nothing on import alone', function (): void {
    expect(runPage(ScarlettPlayer::for('video-1')->toArray(), 'no-autorun'))
        ->toMatchArray(['state' => null, 'created' => 0]);
});

it('leaves a data-scarlett-manual container to the host', function (): void {
    expect(runPage(ScarlettPlayer::for('video-1')->toArray(), 'manual'))
        ->toMatchArray(['state' => null, 'created' => 0]);
});

it('lets a host remove the X-CSRF-TOKEN header with a null value', function (): void {
    $result = runPage(ScarlettPlayer::for('video-1')->withClips()->toArray(), 'remove-csrf');
    $clips = collect($result['plugins'])->firstWhere('factory', 'clips');

    expect($clips['config']['endpoint']['headers'])->toBe(['called' => ['X-XSRF-TOKEN' => 'from-cookie']]);
});

it('passes isLive from the MediaSource into the analytics plugin config', function (bool $live): void {
    ScarlettPlayer::fake()->withMedia(new MediaSource(
        id: 'stream-1',
        playbackUrl: 'https://media.example.test/stream-1.m3u8',
        isLive: $live,
        isProtected: false,
        duration: $live ? null : 60.0,
    ));

    $result = runInitialiser(ScarlettPlayer::for('stream-1')->withAnalytics()->toArray());
    $analytics = collect($result['options']['plugins'])->firstWhere('factory', 'analytics');

    expect($analytics['config']['isLive'])->toBe($live);
})->with(['live' => true, 'vod' => false]);

it('passes the heartbeat interval into the analytics plugin in milliseconds', function (): void {
    config()->set('scarlett-player.player.heartbeat_interval', '5');

    $result = runInitialiser(ScarlettPlayer::for('video-1')->withAnalytics()->toArray());
    $analytics = collect($result['options']['plugins'])->firstWhere('factory', 'analytics');

    expect($analytics['config']['heartbeatInterval'])->toBe(5000);
});

it('sends no heartbeat interval to the plugin when none is configured', function (): void {
    $result = runInitialiser(ScarlettPlayer::for('video-1')->withAnalytics()->toArray());
    $analytics = collect($result['options']['plugins'])->firstWhere('factory', 'analytics');

    expect($analytics['config'])->not->toHaveKey('heartbeatInterval');
});

it('lets a page-wide analytics option beat the configured heartbeat interval', function (): void {
    $result = runPage(ScarlettPlayer::for('video-1')->withAnalytics()->heartbeatInterval(5)->toArray(), 'heartbeat');
    $analytics = collect($result['plugins'])->firstWhere('factory', 'analytics');

    expect($result['created'])->toBe(1)
        ->and($analytics['config']['heartbeatInterval'])->toBe(30000);
});

it('passes privacy defaults from the PHP builder into the module analytics plugin', function (): void {
    $result = runInitialiser(ScarlettPlayer::for('video-1')->analyticsPrivacy(true, true)->withAnalytics()->toArray());
    $analytics = collect($result['options']['plugins'])->firstWhere('factory', 'analytics');
    expect($analytics['config'])->toMatchArray(['anonymous' => true, 'respectDoNotTrack' => true])
        ->and($analytics['config'])->not->toHaveKey('batch');
});

it('forwards per-page privacy overrides and the beforeSend callback without serialization', function (): void {
    $result = runPage(ScarlettPlayer::for('video-1')->withAnalytics()->analyticsPrivacy(true, false)->toArray(), 'privacy');
    $analytics = collect($result['plugins'])->firstWhere('factory', 'analytics')['config'];
    expect($analytics)->toMatchArray(['anonymous' => false, 'respectDoNotTrack' => true, 'playerInitTime' => 1234])
        ->and($analytics['beforeSend'])->toBe(['kept' => ['event' => 'heartbeat', 'redacted' => true], 'dropped' => null]);
});
