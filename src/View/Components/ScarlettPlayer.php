<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\View\Components;

use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Player\FeatureMatrix;
use Hei\ScarlettPlayer\Player\PlayerConfigBuilder;
use Hei\ScarlettPlayer\ScarlettPlayer as ScarlettPlayerRoot;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\View\Component;

/**
 * <x-scarlett-player :media="$video" mode="module" autoplay muted class="aspect-video" />
 *
 * module mode renders the container, the host config as application/json and a call
 * into the initialiser the host bundled. embed mode renders the container with the
 * embed bundle's data-* attributes and a script tag for the pinned bundle.
 */
class ScarlettPlayer extends Component
{
    public readonly PlayerConfigBuilder $builder;

    public readonly string $playerId;

    /**
     * @param  iterable<array-key, mixed>|string|null  $chapters
     * @param  iterable<array-key, mixed>|null  $captions
     */
    public function __construct(
        ScarlettPlayerRoot $scarlett,
        Model|string|MediaSource $media,
        ?string $mode = null,
        bool $autoplay = false,
        bool $muted = false,
        bool $loop = false,
        bool $controls = true,
        int|float|null $startTime = null,
        ?string $poster = null,
        ?string $title = null,
        ?string $brandColor = null,
        ?string $brandTextColor = null,
        bool $analytics = false,
        int|float|string|null $heartbeatInterval = null,
        bool $clips = false,
        iterable|string|null $chapters = null,
        ?iterable $captions = null,
        ?string $shareUrl = null,
        bool $shareEmbed = true,
        ?string $playerId = null,
        public readonly ?string $nonce = null,
        public readonly bool $manual = false,
        ?bool $anonymous = null,
        ?bool $respectDoNotTrack = null,
    ) {
        $builder = $scarlett->for($media);

        if ($mode !== null) {
            $builder->mode($mode);
        }

        $builder->autoplay($autoplay)->muted($muted)->loop($loop)->controls($controls)
            ->startTime($startTime)->brandColor($brandColor)->brandTextColor($brandTextColor);

        if ($poster !== null) {
            $builder->poster($poster);
        }

        if ($title !== null) {
            $builder->title($title);
        }

        $builder->analyticsPrivacy($anonymous, $respectDoNotTrack);

        if ($analytics) {
            $builder->withAnalytics();
        }

        // Seconds; a plain attribute arrives as a string, :heartbeat-interval as a number.
        $heartbeat = PlayerConfigBuilder::heartbeatSeconds($heartbeatInterval);

        if ($heartbeat !== null) {
            $builder->heartbeatInterval($heartbeat);
        }

        if ($clips) {
            $builder->withClips();
        }

        if ($chapters !== null) {
            $builder->withChapters($chapters);
        }

        if ($captions !== null) {
            $builder->withCaptions($captions);
        }

        if ($shareUrl !== null && $shareUrl !== '') {
            $builder->withShare($shareUrl, $shareEmbed);
        }

        $this->builder = $builder;
        $this->playerId = $playerId ?? 'scarlett-player-'.Str::lower(Str::random(10));
    }

    public function isEmbed(): bool
    {
        return $this->builder->currentMode() === FeatureMatrix::EMBED;
    }

    /**
     * The host config as JSON that is safe inside a script element.
     */
    public function configJson(): string
    {
        return $this->builder->toJson(JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
    }

    public function render(): View
    {
        return view('scarlett::components.player');
    }
}
