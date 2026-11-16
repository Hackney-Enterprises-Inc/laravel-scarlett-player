<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Http\Controllers;

use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Http\Middleware\ValidateEmbedSignature;
use Hei\ScarlettPlayer\Partners\PartnerContext;
use Hei\ScarlettPlayer\Partners\PartnerEmbedUrls;
use Hei\ScarlettPlayer\Partners\PartnerOrigin;
use Hei\ScarlettPlayer\Player\EmbedConfig;
use Hei\ScarlettPlayer\ScarlettPlayer;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The embed page: the media in the embed bundle, full-window, with noindex, Open
 * Graph tags, the brand colour and a frame-ancestors policy from embed.allowed_domains.
 *
 * Runs behind ValidateEmbedSignature, which resolved the media and already dropped a
 * startTime or shareUrl that failed validation.
 */
class EmbedController
{
    public function __construct(
        private readonly ScarlettPlayer $scarlett,
        private readonly Repository $config,
        private readonly EmbedConfig $embedConfig,
        private readonly PartnerEmbedUrls $partnerUrls,
    ) {}

    public function show(Request $request): Response
    {
        $media = $request->attributes->get(ValidateEmbedSignature::MEDIA_ATTRIBUTE);

        if (! $media instanceof MediaSource) {
            abort(404);
        }

        $builder = $this->scarlett->for($media)->mode('embed')
            ->autoplay($request->boolean('autoplay'))
            ->muted($request->boolean('muted', $request->boolean('autoplay')))
            ->brandColor($this->metaString($media, 'brand_color'))
            ->brandTextColor($this->metaString($media, 'brand_text_color'));

        $startTime = $request->query('startTime');

        if (is_string($startTime) && is_numeric($startTime) && ! $media->isLive) {
            $builder->startTime((float) $startTime);
        }

        $partner = $request->attributes->get(PartnerContext::ATTRIBUTE);
        $partner = $partner instanceof PartnerContext ? $partner : null;

        $shareUrl = $request->query('shareUrl');

        // A partner page never offers the share sheet: it would hand any viewer the
        // partner's own signed, non-expiring URL as the embed target.
        if ($partner === null && is_string($shareUrl) && $shareUrl !== '') {
            $builder->withShare($shareUrl, $this->canonicalUrl($request));
        }

        // The case the CORS recipe exists for: an embed iframe beaconing cross-origin.
        if ($this->beaconsConfigured()) {
            $builder->withAnalytics();

            if ($partner !== null) {
                $builder->analyticsBeaconUrl($this->partnerBeaconUrl($partner, $media));
            }
        }

        $response = new Response(view('scarlett::embed', [
            'builder' => $builder,
            'media' => $media,
            'canonicalUrl' => $this->canonicalUrl($request),
        ])->render());

        $response->headers->set('Content-Security-Policy', 'frame-ancestors '.$this->frameAncestors($partner));
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        if ($partner !== null) {
            // A page cache or CDN keyed by URL would keep serving a revoked partner's 200.
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    /**
     * The proof the page hands the player as its beacon URL, so the beacon route can
     * attribute a view without a database read (the player attaches api_key afterwards,
     * which the signature ignores). Its absolute root comes from the URL generator, never
     * from the request.
     */
    private function partnerBeaconUrl(PartnerContext $partner, MediaSource $media): string
    {
        return $this->partnerUrls->beaconUrl($partner->ref, $media->id);
    }

    /**
     * This page's URL without the unsigned presentation parameters: still carrying its
     * signature and expiry, so the share sheet's embed snippet validates, and never
     * re-signed, so sharing cannot extend an expiry.
     */
    private function canonicalUrl(Request $request): string
    {
        $unsigned = $this->embedConfig->unsignedParams();
        $query = [];

        foreach (explode('&', (string) $request->server->get('QUERY_STRING')) as $pair) {
            if ($pair !== '' && ! in_array(urldecode(strstr($pair, '=', true) ?: $pair), $unsigned, true)) {
                $query[] = $pair;
            }
        }

        return $request->url().($query === [] ? '' : '?'.implode('&', $query));
    }

    /**
     * A partner's page is framed by that partner's origins and nothing else: every stored
     * origin is validated again here, whatever was written to the row, and 'none' is what
     * is left when none survives. Never the global list, never '*'.
     */
    private function frameAncestors(?PartnerContext $partner): string
    {
        if ($partner !== null) {
            $origins = PartnerOrigin::validOnly($partner->origins);

            return $origins === [] ? "'none'" : implode(' ', ["'self'", ...$origins]);
        }

        $domains = $this->embedConfig->allowedDomains();

        if ($domains === []) {
            return '*';
        }

        $sources = ["'self'"];

        foreach ($domains as $domain) {
            $sources[] = $domain;
            $sources[] = '*.'.$domain;
        }

        return implode(' ', $sources);
    }

    /**
     * Analytics on the embed page needs the ingest on, its route registered and a key
     * for the plugin to send.
     */
    private function beaconsConfigured(): bool
    {
        $key = $this->config->get('scarlett-player.beacons.key');

        return (bool) $this->config->get('scarlett-player.beacons.enabled')
            && (bool) $this->config->get('scarlett-player.routes.beacons')
            && is_string($key) && $key !== '';
    }

    private function metaString(MediaSource $media, string $key): ?string
    {
        $value = $media->meta[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
