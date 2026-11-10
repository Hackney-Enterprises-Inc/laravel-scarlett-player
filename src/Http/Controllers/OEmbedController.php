<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Http\Controllers;

use DateTimeImmutable;
use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Exceptions\MediaNotFoundException;
use Hei\ScarlettPlayer\Partners\PartnerGate;
use Hei\ScarlettPlayer\Partners\PartnerParams;
use Hei\ScarlettPlayer\Player\EmbedConfig;
use Hei\ScarlettPlayer\Player\EmbedUrlGenerator;
use Hei\ScarlettPlayer\ScarlettPlayer;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Throwable;

/**
 * GET {prefix}/oembed?url=<embed page URL>: the oEmbed "video" response the share
 * plugin's embed target and CMS paste fields want. Its html is embedCode().
 *
 * A URL for media that must be signed has to carry a valid signature itself, and the
 * snippet keeps that URL's expiry: an oEmbed lookup never mints a longer-lived link
 * than the one it was given.
 */
class OEmbedController
{
    public function __construct(
        private readonly ScarlettPlayer $scarlett,
        private readonly EmbedUrlGenerator $embeds,
        private readonly Router $router,
        private readonly Repository $config,
        private readonly EmbedConfig $embedConfig,
        private readonly PartnerGate $partners,
        private readonly UrlGenerator $url,
    ) {}

    public function show(Request $request): JsonResponse
    {
        if ($request->query('format', 'json') !== 'json') {
            abort(501, 'Only the json format is supported.');
        }

        $url = $request->query('url');

        if (! is_string($url) || $url === '') {
            abort(404);
        }

        $target = Request::create($url);
        $route = $this->matchEmbedRoute($target);

        if ($route === null) {
            abort(404);
        }

        $parameters = $route->parameters();
        $id = reset($parameters);

        try {
            $media = $this->scarlett->resolve(is_scalar($id) ? (string) $id : '');
        } catch (MediaNotFoundException) {
            abort(404);
        }

        [$width, $height] = $this->dimensions($request);

        if ($target->query->has(PartnerParams::KEY)) {
            // A partner URL is relative-signed: the absolute check below never runs for it.
            $html = $this->embeds->iframe($this->partnerSrc($url, $target, $route, $media), $media, $width, $height);

            return $this->response($media, $width, $height, $html);
        }

        $expires = null;

        if ($this->embeds->mustSign($media)) {
            if (! $target->query->has('signature') || ! $target->hasValidSignatureWhileIgnoring($this->embedConfig->unsignedParams())) {
                abort(401);
            }

            $expiry = $target->query('expires');
            $expires = is_numeric($expiry) ? (new DateTimeImmutable)->setTimestamp((int) $expiry) : null;
        }

        return $this->response($media, $width, $height, $this->embeds->embedCode($media, $expires, $width, $height));
    }

    private function response(MediaSource $media, int $width, int $height, string $html): JsonResponse
    {
        $data = [
            'version' => '1.0',
            'type' => 'video',
            'provider_name' => (string) $this->config->get('app.name'),
            'provider_url' => url('/'),
            'width' => $width,
            'height' => $height,
            'html' => $html,
        ];

        if ($media->title !== null) {
            $data['title'] = $media->title;
        }

        if ($media->poster !== null) {
            $data['thumbnail_url'] = $media->poster;
        }

        return new JsonResponse($data);
    }

    /**
     * The verified partner URL as an iframe src, rebuilt and never echoed.
     *
     * Status per plan section 5: a missing or bad relative signature (tampered, expired,
     * appended to) is 401; the shared PartnerGate then refuses an unknown, revoked or
     * expired partner, a malformed key or protected media without a bounded expiry with 403.
     * The src is the generator's https root, the matched route path and the verified signed
     * pairs in their given order (unsigned presentation parameters dropped), so the input's
     * scheme, host, fragment and unsigned values never reach the markup, and no expiry is
     * added or changed.
     */
    private function partnerSrc(string $url, Request $target, Route $route, MediaSource $media): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (is_string($scheme) && ! in_array(strtolower($scheme), ['http', 'https'], true)) {
            abort(404);
        }

        $ignore = $this->embedConfig->unsignedParams();

        if (! $target->query->has('signature') || ! $target->hasValidRelativeSignatureWhileIgnoring($ignore)) {
            abort(401);
        }

        try {
            $this->partners->partner($target);
            $this->partners->assertExpiry($target, $media);
        } catch (InvalidSignatureException) {
            abort(403);
        }

        $parameters = $route->parameters();
        $pairs = array_diff_key($target->query->all(), array_flip($ignore));
        $path = $this->url->route(EmbedUrlGenerator::ROUTE, [reset($parameters)], false);

        return $this->url->secure($path.'?'.http_build_query($pairs, '', '&', PHP_QUERY_RFC3986));
    }

    private function matchEmbedRoute(Request $target): ?Route
    {
        try {
            $route = $this->router->getRoutes()->match($target);
        } catch (Throwable) {
            return null;
        }

        if ($route->getName() !== EmbedUrlGenerator::ROUTE || $target->getHttpHost() !== request()->getHttpHost()) {
            return null;
        }

        return $route->bind($target);
    }

    /**
     * 640 by 360, scaled down to fit maxwidth and maxheight when given.
     *
     * @return array{int, int}
     */
    private function dimensions(Request $request): array
    {
        $width = 640;
        $height = 360;
        $maxWidth = $request->integer('maxwidth');
        $maxHeight = $request->integer('maxheight');

        if ($maxWidth > 0 && $width > $maxWidth) {
            $height = intdiv($height * $maxWidth, $width);
            $width = $maxWidth;
        }

        if ($maxHeight > 0 && $height > $maxHeight) {
            $width = intdiv($width * $maxHeight, $height);
            $height = $maxHeight;
        }

        return [$width, $height];
    }
}
