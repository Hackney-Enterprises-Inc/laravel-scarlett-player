<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Player;

use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Exceptions\ClipPolicyMissingException;
use Hei\ScarlettPlayer\Exceptions\InvalidPlayerConfigException;
use Hei\ScarlettPlayer\Exceptions\UnsupportedInEmbedMode;
use Hei\ScarlettPlayer\Models\Clip;
use Hei\ScarlettPlayer\Policies\ClipPolicy;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Routing\Router;
use JsonSerializable;

/**
 * Builds the host player configuration for one media source: the versioned,
 * serialisable schema (scarlettConfigVersion 1) that toArray() returns, the Blade
 * component writes into the page, and resources/js/init.js turns into plugins.
 *
 * beaconUrl, the clips endpoint and mediaId come from the package's own routes and
 * the host's MediaSource, never from the host's hand. What each mode may carry is
 * FeatureMatrix, enforced here: an unsupported feature throws at build time.
 *
 * @implements Arrayable<string, mixed>
 */
class PlayerConfigBuilder implements Arrayable, JsonSerializable
{
    /** The host config schema version toArray() emits. */
    public const SCHEMA_VERSION = 1;

    /** The player CDN layout: versioned directories, the ES module build. */
    public const DEFAULT_EMBED_BUNDLE = '{cdn_url}/v{player_version}/embed.js';

    protected string $mode;

    protected bool $autoplay = false;

    protected bool $muted = false;

    protected bool $loop = false;

    protected bool $controls = true;

    protected ?float $startTime = null;

    protected ?string $poster;

    protected ?string $title;

    protected ?string $brandColor = null;

    protected ?string $brandTextColor = null;

    /** @var array<string, mixed>|null */
    protected ?array $analytics = null;

    /** Seconds, set by heartbeatInterval(); null reads player.heartbeat_interval. */
    protected ?float $heartbeatInterval = null;

    /** @var array<string, mixed>|null */
    protected ?array $clips = null;

    /** @var array<string, mixed>|null */
    protected ?array $chapters = null;

    /** @var array<string, mixed>|null */
    protected ?array $captions = null;

    /** @var array<string, mixed>|null */
    protected ?array $share = null;

    public function __construct(
        protected readonly MediaSource $media,
        protected readonly Repository $config,
        protected readonly UrlGenerator $url,
        protected readonly Router $router,
        protected readonly Gate $gate,
        protected readonly EmbedUrlGenerator $embeds,
    ) {
        $this->mode = $this->validMode((string) $config->get('scarlett-player.player.mode', FeatureMatrix::MODULE));
        $this->poster = $media->poster;
        $this->title = $media->title;
    }

    /**
     * The media source this builder configures.
     */
    public function media(): MediaSource
    {
        return $this->media;
    }

    /**
     * module (the host bundles @scarlett-player/* and the initialiser) or embed (the
     * data-* attributes and the embed bundle).
     *
     * @throws UnsupportedInEmbedMode when a feature already enabled cannot go to embed.
     */
    public function mode(string $mode): static
    {
        $this->mode = $this->validMode($mode);

        foreach (['clips' => $this->clips, 'chapters' => $this->chapters, 'captions' => $this->captions, 'analytics_heartbeat' => $this->heartbeatInterval] as $feature => $value) {
            if ($value !== null) {
                $this->assertSupported($feature);
            }
        }

        return $this;
    }

    public function currentMode(): string
    {
        return $this->mode;
    }

    public function autoplay(bool $autoplay = true): static
    {
        $this->autoplay = $autoplay;

        return $this;
    }

    public function muted(bool $muted = true): static
    {
        $this->muted = $muted;

        return $this;
    }

    public function loop(bool $loop = true): static
    {
        $this->loop = $loop;

        return $this;
    }

    public function controls(bool $controls = true): static
    {
        $this->controls = $controls;

        return $this;
    }

    /**
     * Start position in seconds. Null or zero starts at the beginning.
     */
    public function startTime(int|float|null $seconds): static
    {
        $this->startTime = $seconds === null || $seconds <= 0 ? null : (float) $seconds;

        return $this;
    }

    /**
     * Override the poster the MediaSource supplied.
     */
    public function poster(?string $poster): static
    {
        $this->poster = $poster;

        return $this;
    }

    /**
     * Override the title the MediaSource supplied.
     */
    public function title(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Accent colour, any CSS colour value.
     */
    public function brandColor(?string $color): static
    {
        $this->brandColor = $color === '' ? null : $color;

        return $this;
    }

    /**
     * Accent for text; the player derives a readable one from the brand colour when unset.
     */
    public function brandTextColor(?string $color): static
    {
        $this->brandTextColor = $color === '' ? null : $color;

        return $this;
    }

    /**
     * Wire the analytics plugin to this package's beacon route, the media id and the
     * configured beacon key.
     *
     * @throws InvalidPlayerConfigException when the beacon route is not registered.
     */
    public function withAnalytics(): static
    {
        $this->assertSupported('analytics');

        $key = $this->config->get('scarlett-player.beacons.key');

        $this->analytics = [
            'beaconUrl' => $this->routeUrl('scarlett.beacons.store', 'analytics', 'routes.beacons'),
            'videoId' => $this->media->id,
            'apiKey' => is_string($key) && $key !== '' ? $key : null,
            'videoTitle' => $this->title,
            'isLive' => $this->media->isLive,
        ];

        return $this;
    }

    /**
     * Seconds between the analytics plugin's heartbeats for this player, overriding
     * player.heartbeat_interval; null goes back to the config value. Emitted in the
     * analytics block, in milliseconds, whenever analytics is on, in either call order.
     *
     * @throws UnsupportedInEmbedMode in embed mode: the embed bundle has no attribute for it.
     * @throws InvalidPlayerConfigException for a value that is not above zero.
     */
    public function heartbeatInterval(int|float|null $seconds): static
    {
        if ($seconds !== null) {
            $this->assertSupported('analytics_heartbeat');
        }

        $this->heartbeatInterval = self::heartbeatSeconds($seconds);

        return $this;
    }

    /**
     * A heartbeat interval in seconds from config, an attribute or the setter: a number,
     * or a numeric string (an env value). Null and '' mean the player default.
     *
     * @throws InvalidPlayerConfigException for anything that is not a number above zero.
     */
    public static function heartbeatSeconds(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $seconds = is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))
            ? (float) $value
            : null;

        // Under a millisecond rounds to 0, which the player reads as its default.
        if ($seconds === null || ! is_finite($seconds) || round($seconds * 1000) < 1) {
            throw InvalidPlayerConfigException::heartbeatInterval($value);
        }

        return $seconds;
    }

    /**
     * Wire the clips plugin to this package's clip route. csrf true tells the
     * initialiser to attach X-CSRF-TOKEN from the page's meta tag on every request.
     *
     * @throws UnsupportedInEmbedMode in embed mode on a player whose embed bundle cannot carry it (FeatureMatrix).
     * @throws ClipPolicyMissingException for protected media with no Clip policy.
     * @throws InvalidPlayerConfigException when the clips route is not registered.
     */
    public function withClips(): static
    {
        $this->assertSupported('clips');

        if ($this->media->isProtected && ! $this->hostClipPolicyRegistered()) {
            throw ClipPolicyMissingException::forProtectedMedia($this->media->id, Clip::class);
        }

        $this->clips = [
            'endpoint' => [
                // A path, not an absolute URL: the plugin posts with credentials
                // same-origin, so an endpoint on APP_URL sends no session cookie from a
                // page on another host (a tenant domain, www against the apex).
                'url' => $this->routeUrl('scarlett.clips.store', 'clips', 'routes.clips', absolute: false),
                'csrf' => true,
            ],
            'mediaId' => $this->media->id,
            'minDuration' => $this->number('scarlett-player.clips.min_duration'),
            'maxDuration' => $this->number('scarlett-player.clips.max_duration'),
        ];

        return $this;
    }

    /**
     * Chapters as a list of ['time' => seconds, 'label' => string, 'endTime' => ?seconds,
     * 'subtitle' => ?string, 'thumbnail' => ?url], or the URL of a WebVTT chapters file.
     *
     * The names are the player's Chapter contract (@scarlett-player/core Chapter, read by
     * the chapters plugin's normaliseChapters()): endTime is honoured, and a chapter
     * without one runs until the next chapter starts. 'end' is accepted as an input
     * alias for endTime and emitted as endTime.
     *
     * @param  iterable<array-key, mixed>|string  $chapters
     *
     * @throws UnsupportedInEmbedMode in embed mode on a player whose embed bundle cannot carry it (FeatureMatrix).
     * @throws InvalidPlayerConfigException for a malformed chapter.
     */
    public function withChapters(iterable|string $chapters): static
    {
        $this->assertSupported('chapters');

        if (is_string($chapters)) {
            $this->chapters = ['src' => $chapters];

            return $this;
        }

        $list = [];

        foreach ($chapters as $index => $chapter) {
            if (! is_array($chapter) || ! is_numeric($chapter['time'] ?? null) || ! is_string($chapter['label'] ?? null)) {
                throw InvalidPlayerConfigException::invalidEntry('chapter', $index, 'needs a numeric time and a string label');
            }

            $entry = ['time' => (float) $chapter['time'], 'label' => $chapter['label']];
            $end = $chapter['endTime'] ?? $chapter['end'] ?? null;

            if (is_numeric($end)) {
                $entry['endTime'] = (float) $end;
            }

            foreach (['subtitle', 'thumbnail'] as $key) {
                if (is_string($chapter[$key] ?? null) && $chapter[$key] !== '') {
                    $entry[$key] = $chapter[$key];
                }
            }

            $list[] = $entry;
        }

        $this->chapters = ['chapters' => $list];

        return $this;
    }

    /**
     * WebVTT caption tracks as ['language', 'label', 'src', 'kind' => ?, 'default' => ?].
     *
     * @param  iterable<array-key, mixed>  $tracks
     *
     * @throws UnsupportedInEmbedMode in embed mode on a player whose embed bundle cannot carry it (FeatureMatrix).
     * @throws InvalidPlayerConfigException for a malformed track.
     */
    public function withCaptions(iterable $tracks): static
    {
        $this->assertSupported('captions');

        $sources = [];

        foreach ($tracks as $index => $track) {
            if (! is_array($track)) {
                throw InvalidPlayerConfigException::invalidEntry('caption track', $index, 'must be an array');
            }

            foreach (['language', 'label', 'src'] as $key) {
                if (! is_string($track[$key] ?? null) || $track[$key] === '') {
                    throw InvalidPlayerConfigException::invalidEntry('caption track', $index, "needs a string {$key}");
                }
            }

            $source = ['language' => $track['language'], 'label' => $track['label'], 'src' => $track['src']];

            if (in_array($track['kind'] ?? null, ['subtitles', 'captions'], true)) {
                $source['kind'] = $track['kind'];
            }

            if (($track['default'] ?? false) === true) {
                $source['default'] = true;
            }

            $sources[] = $source;
        }

        $this->captions = ['sources' => $sources];

        return $this;
    }

    /**
     * Add the share button for this page URL. With $embed true, the share sheet also
     * offers the iframe snippet, built on embedUrl() for this media; a string is used
     * as the embed base URL as given; false leaves the snippet out.
     */
    public function withShare(string $url, bool|string $embed = true): static
    {
        $this->assertSupported('share');

        $this->share = [
            'url' => $url,
            'title' => $this->title,
            'embedBaseUrl' => match (true) {
                is_string($embed) => $embed,
                $embed => $this->embeds->embedUrl($this->media),
                default => null,
            },
        ];

        return $this;
    }

    /**
     * The host config, schema version 1.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $config = [
            'scarlettConfigVersion' => self::SCHEMA_VERSION,
            'mode' => $this->mode,
            'playerVersion' => $this->playerVersion(),
            'mediaId' => $this->media->id,
            'source' => [
                'src' => $this->media->playbackUrl,
                'isLive' => $this->media->isLive,
                'duration' => $this->media->duration,
            ],
            'poster' => $this->poster,
            'title' => $this->title,
            'playback' => [
                'autoplay' => $this->autoplay,
                'muted' => $this->muted,
                'loop' => $this->loop,
                'controls' => $this->controls,
                'startTime' => $this->startTime,
            ],
            'brand' => [
                'color' => $this->brandColor,
                'textColor' => $this->brandTextColor,
            ],
            'analytics' => $this->analyticsConfig(),
            'clips' => $this->clips,
            'chapters' => $this->chapters,
            'captions' => $this->captions,
            'share' => $this->share,
        ];

        if ($this->mode === FeatureMatrix::EMBED) {
            $config['embed'] = ['bundleUrl' => $this->embedBundleUrl()];
        }

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(int $options = 0): string
    {
        return (string) json_encode($this->toArray(), $options | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /**
     * The config as the embed bundle's data-* attributes. Every name is one the embed
     * README documents; nothing is invented. Booleans are written as "true"/"false",
     * which the embed parser reads as given.
     *
     * @return array<string, string>
     */
    public function toDataAttributes(): array
    {
        $attributes = [
            'data-scarlett-player' => '',
            'data-src' => $this->media->playbackUrl,
            'data-poster' => $this->poster,
            'data-title' => $this->title,
            'data-autoplay' => $this->autoplay ? 'true' : null,
            'data-muted' => $this->muted ? 'true' : null,
            'data-loop' => $this->loop ? 'true' : null,
            'data-controls' => $this->controls ? null : 'false',
            'data-start-time' => $this->startTime === null ? null : $this->formatNumber($this->startTime),
            'data-brand-color' => $this->brandColor,
            'data-brand-text-color' => $this->brandTextColor,
            'data-share-url' => $this->share['url'] ?? null,
            'data-embed-base-url' => $this->share['embedBaseUrl'] ?? null,
            'data-analytics-beacon-url' => $this->analytics['beaconUrl'] ?? null,
            'data-analytics-video-id' => $this->analytics['videoId'] ?? null,
            'data-analytics-api-key' => $this->analytics['apiKey'] ?? null,
            // No isLive or heartbeat interval attribute: the embed README documents
            // neither (FeatureMatrix 'analytics_live', 'analytics_heartbeat'). Add them
            // here when the player ships them.
            'data-captions' => $this->captions === null ? null : $this->json($this->captions['sources']),
            // A JSON list (the parser reads a value starting with '[' as JSON), or the
            // WebVTT URL the host gave.
            'data-chapters' => match (true) {
                $this->chapters === null => null,
                isset($this->chapters['src']) => $this->chapters['src'],
                default => $this->json($this->chapters['chapters'] ?? []),
            },
            // Inert without data-clips-csrf="meta"; the media id is emitted because the
            // player's fallback is data-src, the playback URL.
            'data-clips-endpoint' => $this->clips['endpoint']['url'] ?? null,
            'data-clips-csrf' => $this->clips === null ? null : 'meta',
            'data-clips-media-id' => $this->clips['mediaId'] ?? null,
            'data-clips-min-duration' => is_int($this->clips['minDuration'] ?? null) || is_float($this->clips['minDuration'] ?? null) ? $this->formatNumber((float) $this->clips['minDuration']) : null,
            'data-clips-max-duration' => is_int($this->clips['maxDuration'] ?? null) || is_float($this->clips['maxDuration'] ?? null) ? $this->formatNumber((float) $this->clips['maxDuration']) : null,
        ];

        return array_map(
            fn (mixed $value): string => (string) $value,
            array_filter($attributes, fn (mixed $value): bool => $value !== null && $value !== false),
        );
    }

    /**
     * The embed bundle URL: player.embed_bundle with {cdn_url} and {player_version}
     * filled in.
     *
     * @throws InvalidPlayerConfigException when the template needs a CDN URL and none is set.
     */
    public function embedBundleUrl(): string
    {
        return self::renderEmbedBundle($this->config);
    }

    /**
     * Render player.embed_bundle from config. Shared with the doctor check.
     *
     * @throws InvalidPlayerConfigException when the template needs a CDN URL and none is set.
     */
    public static function renderEmbedBundle(Repository $config): string
    {
        $template = $config->get('scarlett-player.player.embed_bundle');
        $template = is_string($template) && $template !== '' ? $template : self::DEFAULT_EMBED_BUNDLE;
        $cdn = $config->get('scarlett-player.player.cdn_url');
        $cdn = is_string($cdn) ? rtrim($cdn, '/') : '';

        if (str_contains($template, '{cdn_url}') && $cdn === '') {
            throw new InvalidPlayerConfigException('Embed mode needs the embed bundle location: set SCARLETT_CDN_URL (scarlett-player.player.cdn_url), or a full player.embed_bundle.');
        }

        return strtr($template, [
            '{cdn_url}' => $cdn,
            '{player_version}' => (string) $config->get('scarlett-player.player.player_version'),
        ]);
    }

    /**
     * The embed addon files this config needs, in the order they load after the bundle:
     * chapters, then clips. Each sits beside the bundle, in the same version directory,
     * in the same build flavour (embed.addon.<name>.js beside an ES module bundle,
     * embed.addon.<name>.umd.cjs beside the UMD build), because an addon refuses an
     * embed of another version. Empty in module mode.
     *
     * @return list<string>
     *
     * @throws InvalidPlayerConfigException when the bundle URL cannot be built.
     */
    public function embedAddonUrls(): array
    {
        if ($this->mode !== FeatureMatrix::EMBED) {
            return [];
        }

        $names = array_keys(array_filter(['chapters' => $this->chapters, 'clips' => $this->clips], fn (?array $feature): bool => $feature !== null));

        if ($names === []) {
            return [];
        }

        $bundle = (string) strtok($this->embedBundleUrl(), '?#');
        $directory = substr($bundle, 0, (int) strrpos($bundle, '/') + 1);
        $extension = $this->embedBundleIsModule() ? '.js' : '.umd.cjs';

        return array_map(fn (string $name): string => "{$directory}embed.addon.{$name}{$extension}", $names);
    }

    /**
     * Whether the embed bundle loads as an ES module: every bundle but the UMD build
     * (a path ending in .cjs), which is a classic script.
     *
     * @throws InvalidPlayerConfigException when the bundle URL cannot be built.
     */
    public function embedBundleIsModule(): bool
    {
        $path = (string) parse_url($this->embedBundleUrl(), PHP_URL_PATH);

        return ! str_ends_with(strtolower($path), '.cjs');
    }

    public function playerVersion(): string
    {
        return (string) $this->config->get('scarlett-player.player.player_version');
    }

    /**
     * The analytics block with the heartbeat interval resolved now, so the setter and
     * withAnalytics() work in either order. Embed mode leaves a configured interval out:
     * the page still renders and the player default applies.
     *
     * @return array<string, mixed>|null
     *
     * @throws InvalidPlayerConfigException for a malformed player.heartbeat_interval.
     */
    protected function analyticsConfig(): ?array
    {
        if ($this->analytics === null || ! FeatureMatrix::supports('analytics_heartbeat', $this->mode, $this->playerVersion())) {
            return $this->analytics;
        }

        $seconds = $this->heartbeatInterval
            ?? self::heartbeatSeconds($this->config->get('scarlett-player.player.heartbeat_interval'));

        if ($seconds === null) {
            return $this->analytics;
        }

        return [...$this->analytics, 'heartbeatInterval' => (int) round($seconds * 1000)];
    }

    /**
     * @throws UnsupportedInEmbedMode when the current mode cannot carry the feature.
     */
    protected function assertSupported(string $feature): void
    {
        if ($this->mode === FeatureMatrix::EMBED && ! FeatureMatrix::supports($feature, $this->mode, $this->playerVersion())) {
            throw new UnsupportedInEmbedMode($feature, $this->playerVersion());
        }
    }

    /**
     * A Clip policy the host registered. The rule lives on ClipPolicy so the create
     * ability and this builder apply the same test.
     */
    protected function hostClipPolicyRegistered(): bool
    {
        return ClipPolicy::hostPolicyPublished($this->gate);
    }

    protected function validMode(string $mode): string
    {
        if (! in_array($mode, FeatureMatrix::MODES, true)) {
            throw InvalidPlayerConfigException::unknownMode($mode);
        }

        return $mode;
    }

    protected function routeUrl(string $name, string $feature, string $switch, bool $absolute = true): string
    {
        if (! $this->router->has($name)) {
            // A name given after the route was added is indexed on the next refresh.
            $this->router->getRoutes()->refreshNameLookups();
        }

        if (! $this->router->has($name)) {
            throw InvalidPlayerConfigException::routeMissing($feature, $name, $switch);
        }

        return $this->url->route($name, [], $absolute);
    }

    protected function number(string $key): int|float|null
    {
        $value = $this->config->get($key);

        return is_int($value) || is_float($value) ? $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    protected function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    protected function formatNumber(float $value): string
    {
        return floor($value) === $value ? (string) (int) $value : (string) $value;
    }
}
