<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer;

use DateInterval;
use DateTimeInterface;
use Hei\ScarlettPlayer\Contracts\ResolvesMedia;
use Hei\ScarlettPlayer\Contracts\ScarlettMedia;
use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Exceptions\MediaNotFoundException;
use Hei\ScarlettPlayer\Player\EmbedUrlGenerator;
use Hei\ScarlettPlayer\Player\PlayerConfigBuilder;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;

/**
 * The facade root: the one entry point a host calls to turn its media into player
 * configuration. Modules add their methods here.
 */
class ScarlettPlayer
{
    public function __construct(
        protected readonly Container $container,
        protected readonly ResolvesMedia $resolver,
        protected readonly Repository $config,
    ) {}

    /**
     * Resolve a player mediaId through the bound ResolvesMedia.
     *
     * @throws MediaNotFoundException when the resolver knows no such media.
     */
    public function resolve(string $mediaId): MediaSource
    {
        return $this->resolver->resolve($mediaId) ?? throw new MediaNotFoundException($mediaId);
    }

    /**
     * Start the player configuration for a host model, a mediaId or a MediaSource.
     *
     * A model implementing ScarlettMedia supplies its own MediaSource; any other model
     * is resolved by its media.key attribute (its route key when none is set).
     *
     * @throws MediaNotFoundException when the media cannot be resolved.
     */
    public function for(Model|string|MediaSource $media): PlayerConfigBuilder
    {
        return $this->container->make(PlayerConfigBuilder::class, ['media' => $this->sourceOf($media)]);
    }

    /**
     * The embed page URL for the media: what the share plugin gets as embedBaseUrl.
     * Signed when the media is protected, embed.always_sign is on, or $expires is given.
     *
     * @throws MediaNotFoundException when the media cannot be resolved.
     */
    public function embedUrl(Model|string|MediaSource $media, DateTimeInterface|DateInterval|int|null $expires = null): string
    {
        return $this->container->make(EmbedUrlGenerator::class)->embedUrl($this->sourceOf($media), $expires);
    }

    /**
     * The iframe snippet around embedUrl(): what a CMS paste field gets.
     *
     * @throws MediaNotFoundException when the media cannot be resolved.
     */
    public function embedCode(
        Model|string|MediaSource $media,
        DateTimeInterface|DateInterval|int|null $expires = null,
        int|string $width = 640,
        int|string $height = 360,
    ): string {
        return $this->container->make(EmbedUrlGenerator::class)->embedCode($this->sourceOf($media), $expires, $width, $height);
    }

    protected function sourceOf(Model|string|MediaSource $media): MediaSource
    {
        return match (true) {
            $media instanceof MediaSource => $media,
            $media instanceof ScarlettMedia => $media->toScarlettMediaSource(),
            $media instanceof Model => $this->resolve($this->mediaIdOf($media)),
            default => $this->resolve($media),
        };
    }

    protected function mediaIdOf(Model $model): string
    {
        $key = $this->config->get('scarlett-player.media.key');
        $value = is_string($key) && $key !== '' ? $model->getAttribute($key) : null;

        return (string) ($value ?? $model->getRouteKey());
    }
}
