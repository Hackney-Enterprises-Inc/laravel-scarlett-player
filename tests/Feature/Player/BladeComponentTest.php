<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Exceptions\UnsupportedInEmbedMode;
use Hei\ScarlettPlayer\Facades\ScarlettPlayer;
use Hei\ScarlettPlayer\Tests\Fixtures\Provider\ArrayResolver;
use Illuminate\View\ViewException;

/** The published embed README, the only source of data-* attribute names (see its PROVENANCE.md). */
const EMBED_README = __DIR__.'/../../Fixtures/embed/attributes/1.17.0/README.md';

beforeEach(function (): void {
    ScarlettPlayer::fake()->withMedia(ArrayResolver::source('video-1'));
    config()->set('scarlett-player.player.cdn_url', 'https://cdn.example.test/scarlett-player');
});

/**
 * The host config the component wrote into the page.
 *
 * @return array<string, mixed>
 */
function renderedConfig(string $html): array
{
    preg_match('#<script type="application/json" id="[^"]+" data-scarlett-config>(.*?)</script>#s', $html, $match);

    /** @var array<string, mixed> */
    return json_decode($match[1] ?? 'null', true, 512, JSON_THROW_ON_ERROR);
}

it('renders module mode as a container, the host config and the initialiser call', function (): void {
    $html = (string) $this->blade('<x-scarlett-player media="video-1" autoplay muted class="aspect-video" player-id="p1" />');

    expect($html)->toContain('<div id="p1" class="aspect-video" data-scarlett-host="p1-config"></div>')
        ->and($html)->toContain('window.ScarlettPlayerHost ? window.ScarlettPlayerHost.initAll(document, window.scarlettPlayerOptions || {}) : (window.scarlettPlayerPending = true);')
        ->and($html)->not->toContain('data-src=');

    expect(renderedConfig($html))->toMatchArray([
        'scarlettConfigVersion' => 1,
        'mode' => 'module',
        'mediaId' => 'video-1',
    ])->and(renderedConfig($html)['playback']['autoplay'])->toBeTrue()
        ->and(renderedConfig($html)['playback']['muted'])->toBeTrue();
});

it('keeps a closing script tag in the config from breaking out of the element', function (): void {
    $html = (string) $this->blade('<x-scarlett-player media="video-1" title="</script><script>alert(1)</script>" />');

    expect($html)->not->toContain('</script><script>alert(1)')
        ->and(renderedConfig($html)['title'])->toBe('</script><script>alert(1)</script>');
});

it('passes every builder option through the component attributes', function (): void {
    $html = (string) $this->blade(
        '<x-scarlett-player media="video-1" analytics :chapters="$chapters" :captions="$captions" loop :controls="false" :start-time="30" poster="https://img.example.test/p.jpg" brand-color="#e50914" brand-text-color="#fff" share-url="https://host.test/watch/1" :share-embed="false" />',
        [
            'chapters' => [['time' => 0, 'label' => 'Intro']],
            'captions' => [['language' => 'en', 'label' => 'English', 'src' => 'https://cdn.example.test/en.vtt']],
        ],
    );

    $config = renderedConfig($html);

    expect($config['analytics']['videoId'])->toBe('video-1')
        ->and($config['chapters']['chapters'][0]['label'])->toBe('Intro')
        ->and($config['captions']['sources'][0]['language'])->toBe('en')
        ->and($config['playback'])->toMatchArray(['loop' => true, 'controls' => false, 'startTime' => 30.0])
        ->and($config['poster'])->toBe('https://img.example.test/p.jpg')
        ->and($config['brand'])->toBe(['color' => '#e50914', 'textColor' => '#fff'])
        ->and($config['share'])->toMatchArray(['url' => 'https://host.test/watch/1', 'embedBaseUrl' => null]);
});

it('adds a CSP nonce to the inline script when given', function (): void {
    expect((string) $this->blade('<x-scarlett-player media="video-1" nonce="abc123" />'))
        ->toContain('<script type="module"  nonce="abc123" >');
});

it('renders embed mode as data attributes and the pinned bundle', function (): void {
    $html = (string) $this->blade('<x-scarlett-player media="video-1" mode="embed" autoplay brand-color="#e50914" analytics player-id="e1" />');

    expect($html)->toContain('id="e1"')
        ->and($html)->toContain('data-scarlett-player=""')
        ->and($html)->toContain('data-src="https://media.example.test/video-1.m3u8"')
        ->and($html)->toContain('data-autoplay="true"')
        ->and($html)->toContain('data-brand-color="#e50914"')
        ->and($html)->toContain('data-analytics-video-id="video-1"')
        ->and($html)->toContain('<script src="https://cdn.example.test/scarlett-player/v'.config('scarlett-player.player.player_version').'/embed.js" type="module"></script>')
        ->and($html)->not->toContain('data-scarlett-config');
});

it('emits only data-* names the embed README documents', function (): void {
    $readme = (string) file_get_contents(EMBED_README);
    $html = (string) $this->blade(
        '<x-scarlett-player media="video-1" mode="embed" autoplay muted loop :controls="false" :start-time="5" title="T" poster="https://img.example.test/p.jpg" brand-color="#111" brand-text-color="#fff" analytics share-url="https://host.test/watch/1" />'
    );

    preg_match_all('/\s(data-[a-z-]+)="/', $html, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach (array_unique($matches[1]) as $name) {
        expect($readme)->toContain("`{$name}`");
    }
});

it('loads the embed bundle once for several embed players', function (): void {
    $html = (string) $this->blade('<x-scarlett-player media="video-1" mode="embed" /><x-scarlett-player media="video-1" mode="embed" />');

    expect(substr_count($html, '/embed.js'))->toBe(1)
        ->and(substr_count($html, 'data-scarlett-player=""'))->toBe(2);
});

it('answers to x-scarlett::player as well', function (): void {
    expect((string) $this->blade('<x-scarlett::player media="video-1" player-id="ns" />'))
        ->toContain('data-scarlett-host="ns-config"');
});

it('throws UnsupportedInEmbedMode for clips in embed mode before player 1.17.0', function (): void {
    config()->set('scarlett-player.player.player_version', '1.16.3');

    try {
        $this->blade('<x-scarlett-player media="video-1" mode="embed" clips />');
        $this->fail('Expected the render to throw.');
    } catch (ViewException $e) {
        expect($e->getPrevious())->toBeInstanceOf(UnsupportedInEmbedMode::class);
    }
});

it('gives each player a distinct id by default', function (): void {
    $html = (string) $this->blade('<x-scarlett-player media="video-1" /><x-scarlett-player media="video-1" />');

    preg_match_all('/data-scarlett-host="([^"]+)"/', $html, $matches);

    expect($matches[1])->toHaveCount(2)
        ->and($matches[1][0])->not->toBe($matches[1][1]);
});

it('marks a manual player and emits no start call for it', function (): void {
    $html = (string) $this->blade('<x-scarlett-player media="video-1" manual player-id="m1" />');

    expect($html)->toContain('data-scarlett-host="m1-config" data-scarlett-manual></div>')
        ->and($html)->toContain('data-scarlett-config')
        ->and($html)->not->toContain('initAll');
});

it('loads a UMD embed bundle as a classic script, with the nonce', function (): void {
    config()->set('scarlett-player.player.embed_bundle', '{cdn_url}/latest/embed.umd.cjs');

    expect((string) $this->blade('<x-scarlett-player media="video-1" mode="embed" nonce="n1" />'))
        ->toContain('<script src="https://cdn.example.test/scarlett-player/latest/embed.umd.cjs" nonce="n1"></script>');
});

it('adds the nonce to a module embed bundle too', function (): void {
    expect((string) $this->blade('<x-scarlett-player media="video-1" mode="embed" nonce="n1" />'))
        ->toContain('<script src="https://cdn.example.test/scarlett-player/v'.config('scarlett-player.player.player_version').'/embed.js" type="module" nonce="n1"></script>');
});

it('passes the heartbeat-interval attribute to the analytics config in milliseconds', function (string $attribute, int $ms): void {
    $config = renderedConfig((string) $this->blade("<x-scarlett-player media=\"video-1\" analytics {$attribute} />"));

    expect($config['analytics']['heartbeatInterval'])->toBe($ms);
})->with([
    'plain attribute (a string)' => ['heartbeat-interval="5"', 5000],
    'bound number' => [':heartbeat-interval="2.5"', 2500],
]);

it('takes the configured heartbeat interval without the attribute', function (): void {
    config()->set('scarlett-player.player.heartbeat_interval', '15');

    $config = renderedConfig((string) $this->blade('<x-scarlett-player media="video-1" analytics />'));

    expect($config['analytics']['heartbeatInterval'])->toBe(15000);
});

it('refuses a heartbeat-interval attribute that is not a number above zero', function (): void {
    $this->blade('<x-scarlett-player media="video-1" analytics heartbeat-interval="soon" />');
})->throws(ViewException::class, 'heartbeat interval');

it('throws UnsupportedInEmbedMode for a heartbeat-interval attribute in embed mode', function (): void {
    try {
        $this->blade('<x-scarlett-player media="video-1" mode="embed" analytics heartbeat-interval="5" />');
    } catch (ViewException $e) {
        throw $e->getPrevious() ?? $e;
    }
})->throws(UnsupportedInEmbedMode::class, 'heartbeat');

it('loads the chapters and clips addons after the bundle, once each, in embed mode', function (): void {
    $html = (string) $this->blade(
        '<x-scarlett-player media="video-1" mode="embed" :captions="$captions" player-id="e1" />'.
        '<x-scarlett-player media="video-1" mode="embed" clips :chapters="$chapters" player-id="e2" />'.
        '<x-scarlett-player media="video-1" mode="embed" clips :chapters="$chapters" player-id="e3" />',
        [
            'captions' => [['language' => 'en', 'label' => 'English', 'src' => 'https://cdn.example.test/en.vtt']],
            'chapters' => [['time' => 0, 'label' => 'Intro']],
        ],
    );
    $base = 'https://cdn.example.test/scarlett-player/v'.config('scarlett-player.player.player_version');

    preg_match_all('#<script src="([^"]+)"#', $html, $scripts);

    expect($scripts[1])->toBe([
        "{$base}/embed.js",
        "{$base}/embed.addon.chapters.js",
        "{$base}/embed.addon.clips.js",
    ])
        ->and($html)->toContain('<script src="'.$base.'/embed.addon.clips.js" type="module"></script>')
        ->and($html)->toContain('data-clips-csrf="meta"')
        ->and($html)->toContain('data-captions="[')
        ->and($html)->toContain('data-chapters="[');

    foreach (['data-clips-endpoint', 'data-clips-csrf', 'data-clips-media-id', 'data-captions', 'data-chapters'] as $name) {
        expect((string) file_get_contents(EMBED_README))->toContain("`{$name}`");
    }
});

it('loads no addon for an embed player with captions only', function (): void {
    $html = (string) $this->blade('<x-scarlett-player media="video-1" mode="embed" :captions="$captions" />', [
        'captions' => [['language' => 'en', 'label' => 'English', 'src' => 'https://cdn.example.test/en.vtt']],
    ]);

    expect($html)->not->toContain('embed.addon.');
});

it('loads the UMD addons as classic scripts beside a UMD bundle', function (): void {
    config()->set('scarlett-player.player.embed_bundle', '{cdn_url}/v{player_version}/embed.umd.cjs');

    $html = (string) $this->blade('<x-scarlett-player media="video-1" mode="embed" clips nonce="n0nce" />');

    expect($html)->toContain('embed.addon.clips.umd.cjs" nonce="n0nce"></script>')
        ->and($html)->not->toContain('type="module"');
});
