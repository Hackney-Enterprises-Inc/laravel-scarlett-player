<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Http\Controllers;

use DateTimeImmutable;
use Hei\ScarlettPlayer\Exceptions\MediaNotFoundException;
use Hei\ScarlettPlayer\Player\EmbedConfig;
use Hei\ScarlettPlayer\Player\EmbedUrlGenerator;
use Hei\ScarlettPlayer\ScarlettPlayer;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $expires = null;

        if ($this->embeds->mustSign($media)) {
            if (! $target->query->has('signature') || ! $target->hasValidSignatureWhileIgnoring($this->embedConfig->unsignedParams())) {
                abort(401);
            }

            $expiry = $target->query('expires');
            $expires = is_numeric($expiry) ? (new DateTimeImmutable)->setTimestamp((int) $expiry) : null;
        }

        [$width, $height] = $this->dimensions($request);

        $data = [
            'version' => '1.0',
            'type' => 'video',
            'provider_name' => (string) $this->config->get('app.name'),
            'provider_url' => url('/'),
            'width' => $width,
            'height' => $height,
            'html' => $this->embeds->embedCode($media, $expires, $width, $height),
        ];

        if ($media->title !== null) {
            $data['title'] = $media->title;
        }

        if ($media->poster !== null) {
            $data['thumbnail_url'] = $media->poster;
        }

        return new JsonResponse($data);
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
