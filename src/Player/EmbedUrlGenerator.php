<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Player;

use DateInterval;
use DateTimeInterface;
use Hei\ScarlettPlayer\Data\MediaSource;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Routing\UrlGenerator;

/**
 * Builds the embed page URL and the iframe snippet around it.
 *
 * embedUrl() is a URL: what the share plugin gets as embedBaseUrl. embedCode() is the
 * iframe snippet a CMS paste field gets. The URL is signed when the media is protected,
 * when embed.always_sign is on, or when an expiry is asked for; the share plugin then
 * appends startTime and shareUrl, which ValidateEmbedSignature leaves out of the check.
 */
class EmbedUrlGenerator
{
    public const ROUTE = 'scarlett.embed.show';

    public function __construct(
        private readonly UrlGenerator $url,
        private readonly Repository $config,
        private readonly EmbedConfig $embedConfig,
    ) {}

    public function embedUrl(MediaSource $media, DateTimeInterface|DateInterval|int|null $expires = null): string
    {
        $parameters = [$media->id];

        if (! $this->mustSign($media) && $expires === null) {
            return $this->url->route(self::ROUTE, $parameters);
        }

        // A signature with no expiry would be printed into every page that shows the
        // media and copied from there forever; embed.signed_ttl bounds it.
        $expires ??= $this->embedConfig->signedTtl();

        return $expires === null
            ? $this->url->signedRoute(self::ROUTE, $parameters)
            : $this->url->temporarySignedRoute(self::ROUTE, $expires, $parameters);
    }

    /**
     * The iframe snippet, with the same attributes the share plugin's snippet uses.
     */
    public function embedCode(
        MediaSource $media,
        DateTimeInterface|DateInterval|int|null $expires = null,
        int|string $width = 640,
        int|string $height = 360,
    ): string {
        $attributes = [
            'src' => $this->embedUrl($media, $expires),
            'width' => (string) $width,
            'height' => (string) $height,
            'frameborder' => '0',
            'allow' => 'autoplay; fullscreen; picture-in-picture',
        ];

        if ($media->title !== null && $media->title !== '') {
            $attributes['title'] = $media->title;
        }

        $html = '';

        foreach ($attributes as $name => $value) {
            $html .= ' '.$name.'="'.e($value).'"';
        }

        return '<iframe'.$html.' allowfullscreen loading="lazy"></iframe>';
    }

    /**
     * Whether an embed of this media must carry a signature.
     */
    public function mustSign(MediaSource $media): bool
    {
        return $media->isProtected || (bool) $this->config->get('scarlett-player.embed.always_sign');
    }
}
