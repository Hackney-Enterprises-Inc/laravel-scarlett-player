<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Partners;

use DateInterval;
use DateTimeInterface;
use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Exceptions\InvalidPartnerException;
use Hei\ScarlettPlayer\Models\Partner;
use Hei\ScarlettPlayer\Player\EmbedUrlGenerator;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Routing\UrlGenerator as RoutingUrlGenerator;
use Illuminate\Support\Carbon;

/**
 * Mints partner page URLs and iframe snippets.
 *
 * The URL is signed RELATIVE (`/v/{media}?scp_key=...`) and then prefixed with a root, so
 * the signature does not depend on the host that minted it: a wrong APP_URL, a console
 * run with no request or a different public host can never invalidate a snippet. The
 * root is the one given, else app.url, else the URL generator's own root.
 *
 * Nothing is minted for a partner or media the page would refuse: an unknown, revoked or
 * expired partner, embed.always_sign off (without it, stripping scp_key and the signature
 * leaves an unlocked page), a media id outside PartnerParams::MEDIA_ID, protected media
 * with no expiry, an expiry in the past or further than PartnerParams::MAX_EXPIRES away.
 */
class PartnerEmbedUrls
{
    public function __construct(
        private readonly UrlGenerator $url,
        private readonly Repository $config,
        private readonly EmbedUrlGenerator $embeds,
    ) {}

    /**
     * The signed partner page URL.
     *
     * @param  DateTimeInterface|DateInterval|int|null  $expires  an integer is seconds from now, as for embedUrl()
     *
     * @throws InvalidPartnerException when the partner, media or expiry cannot be used.
     */
    public function url(string $ref, MediaSource $media, DateTimeInterface|DateInterval|int|null $expires = null, ?string $root = null): string
    {
        if (! (bool) $this->config->get('scarlett-player.embed.always_sign')) {
            throw InvalidPartnerException::alwaysSignOff();
        }

        $partner = $this->partner($ref);

        if (! PartnerParams::isMediaId($media->id)) {
            throw InvalidPartnerException::invalidMediaId($media->id);
        }

        $until = $this->expiry($expires, $media);
        $prefix = $this->root($root);

        $relative = $this->relativeSignedRoute(
            EmbedUrlGenerator::ROUTE,
            [$media->id, PartnerParams::KEY => $partner->key_id],
            $until,
        );

        return $prefix.$relative;
    }

    /**
     * The beacon proof printed on a partner page: the beacon route, relative signed over
     * the partner ref and the media with a PartnerParams::BEACON_TTL expiry, then given
     * its absolute root by the URL generator (URL::forceScheme(), forceRootUrl() and a
     * sub-path install apply), never by the request. The signature is over the route path
     * alone, so the root never changes it.
     */
    public function beaconUrl(string $ref, string $mediaId): string
    {
        $relative = $this->relativeSignedRoute(
            'scarlett.beacons.store',
            [PartnerParams::REF => $ref, PartnerParams::MEDIA => $mediaId],
            Carbon::now()->addSeconds(PartnerParams::BEACON_TTL),
        );

        return $this->url->to($relative);
    }

    /**
     * A relative signed route whose path is what the receiving request will see.
     *
     * The framework verifies a relative signature over '/'.$request->path(), which has
     * neither a host nor the install's base path. route(..., absolute: false) strips the
     * root only up to the request's own base URL, so a forced root URL that carries a path
     * (a proxy that strips its prefix) would leak that path into the signed string and
     * every URL minted under it would fail. Signing through a copy of the generator with
     * no forced root signs the path the request will have.
     *
     * @param  array<array-key, mixed>  $parameters
     */
    private function relativeSignedRoute(string $name, array $parameters, ?DateTimeInterface $expires): string
    {
        $url = $this->url;

        if ($url instanceof RoutingUrlGenerator) {
            $url = clone $url;
            $url->forceRootUrl(null);
            // Drops the copy's cached route generator, which still points at the original.
            $url->setRequest($url->getRequest());
        }

        return $url->signedRoute($name, $parameters, $expires, absolute: false);
    }

    /**
     * The iframe snippet around url(), with the attributes embedCode() uses.
     *
     * @throws InvalidPartnerException as url().
     */
    public function code(
        string $ref,
        MediaSource $media,
        DateTimeInterface|DateInterval|int|null $expires = null,
        int|string $width = 640,
        int|string $height = 360,
        ?string $root = null,
    ): string {
        return $this->embeds->iframe($this->url($ref, $media, $expires, $root), $media, $width, $height);
    }

    /**
     * @throws InvalidPartnerException for an unknown, revoked or expired partner.
     */
    private function partner(string $ref): Partner
    {
        if (! PartnerParams::isRef($ref)) {
            throw InvalidPartnerException::invalidRef($ref);
        }

        $partner = Partner::query()->where('ref', $ref)->first();

        return match (true) {
            $partner === null => throw InvalidPartnerException::unknown($ref),
            $partner->isRevoked() => throw InvalidPartnerException::revoked($ref),
            $partner->isExpired() => throw InvalidPartnerException::expired($ref),
            default => $partner,
        };
    }

    /**
     * The URL expiry as a date, or null for none.
     *
     * @throws InvalidPartnerException for protected media with none, or one in the past or too far away.
     */
    private function expiry(DateTimeInterface|DateInterval|int|null $expires, MediaSource $media): ?Carbon
    {
        if ($expires === null) {
            return $media->isProtected ? throw InvalidPartnerException::protectedMediaNeedsExpiry() : null;
        }

        $now = Carbon::now();

        $at = match (true) {
            $expires instanceof DateTimeInterface => Carbon::instance($expires),
            $expires instanceof DateInterval => $now->copy()->add($expires),
            default => $now->copy()->addSeconds($expires),
        };

        if ($at->lessThanOrEqualTo($now)) {
            throw new InvalidPartnerException('A partner snippet expiry must be in the future.');
        }

        if ($at->getTimestamp() > $now->getTimestamp() + PartnerParams::MAX_EXPIRES) {
            throw InvalidPartnerException::expiryTooFar();
        }

        return $at;
    }

    /**
     * The origin (and any sub-path) the relative URL is prefixed with: no trailing slash.
     *
     * @throws InvalidPartnerException when the given or configured root is not an http(s) URL.
     */
    private function root(?string $root): string
    {
        $configured = $this->config->get('app.url');

        $root = match (true) {
            $root !== null && $root !== '' => $root,
            is_string($configured) && $configured !== '' => $configured,
            default => $this->url->to('/'),
        };

        $root = rtrim($root, '/');

        // Printable ASCII, http(s), a host, no query or fragment: it is printed into an iframe src.
        if (preg_match('#^https?://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~/-]*)?$#D', $root) !== 1) {
            throw new InvalidPartnerException('The root ['.substr((string) preg_replace('/[^\x20-\x7e]/', '?', $root), 0, 80).'] is not an http(s) URL: use https://host[:port][/path] with no query or fragment.');
        }

        return $root;
    }
}
