<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Http\Middleware;

use Closure;
use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Exceptions\MediaNotFoundException;
use Hei\ScarlettPlayer\Player\EmbedConfig;
use Hei\ScarlettPlayer\Player\EmbedUrlGenerator;
use Hei\ScarlettPlayer\ScarlettPlayer;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the embed page.
 *
 * The share plugin appends startTime and shareUrl to whatever embedBaseUrl it is given,
 * so a signature over the whole query string would reject every share-built iframe.
 * This middleware checks the signature while ignoring embed.unsigned_params: the
 * signature covers identity and expiry, and the presentation parameters are validated
 * on their own terms here, dropped from the request when they fail, never echoed.
 *
 * A signature is required when the media is protected or embed.always_sign is on; a
 * signature that is present is always checked. The resolved MediaSource is left on the
 * request as the `scarlett.media` attribute.
 */
class ValidateEmbedSignature extends ValidateSignature
{
    public const MEDIA_ATTRIBUTE = 'scarlett.media';

    public function __construct(
        private readonly ScarlettPlayer $scarlett,
        private readonly EmbedUrlGenerator $embeds,
        private readonly EmbedConfig $embedConfig,
    ) {}

    /**
     * @param  Request  $request
     * @param  Closure(Request): Response  $next
     * @param  array<int, string>  ...$args
     * @return Response
     */
    public function handle($request, Closure $next, ...$args)
    {
        $ignore = $this->embedConfig->unsignedParams();
        $signed = $request->query->has('signature');

        if ($signed && ! $request->hasValidSignatureWhileIgnoring($ignore)) {
            throw new InvalidSignatureException;
        }

        $media = $this->resolve($request);

        if (! $signed && $this->embeds->mustSign($media)) {
            throw new InvalidSignatureException;
        }

        $this->sanitisePresentation($request);
        $request->attributes->set(self::MEDIA_ATTRIBUTE, $media);

        return $next($request);
    }

    private function resolve(Request $request): MediaSource
    {
        // The first route parameter is the media id, whatever embed.route names it.
        $route = $request->route();
        $parameters = $route instanceof Route ? $route->parameters() : [];
        $id = reset($parameters);

        try {
            return $this->scarlett->resolve(is_scalar($id) ? (string) $id : '');
        } catch (MediaNotFoundException) {
            abort(404);
        }
    }

    private function sanitisePresentation(Request $request): void
    {
        $startTime = $request->query('startTime');

        // Plain seconds only: is_numeric() would also take '+5', '1e9' and ' 5'.
        if ($startTime !== null && (! is_string($startTime) || preg_match('/^\d+(\.\d+)?$/', $startTime) !== 1)) {
            $request->query->remove('startTime');
        }

        $shareUrl = $request->query('shareUrl');

        if ($shareUrl !== null && (! is_string($shareUrl) || ! $this->allowedShareUrl($shareUrl))) {
            $request->query->remove('shareUrl');
        }
    }

    /**
     * An absolute http(s) URL, on an allowed domain when embed.allowed_domains is set.
     *
     * parse_url() and a browser's WHATWG parser disagree on backslashes, whitespace
     * and userinfo: 'https://evil.test\@allowed.test/' reads as allowed.test here and
     * goes to evil.test in the browser. shareUrl is unsigned, so anyone can append one
     * to a legitimate signed embed; anything either parser could read differently is
     * refused before the host is checked, and the host itself must be plain ASCII.
     */
    private function allowedShareUrl(string $url): bool
    {
        if (preg_match('/[\\\\\s\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        // A plain ASCII host only: a '%' or a non-ASCII byte (a fullwidth slash, an
        // ideographic full stop) is at best a broken share link, so it is dropped.
        if (! in_array($scheme, ['http', 'https'], true) || preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
            return false;
        }

        return $this->embedConfig->allowsHost($host);
    }
}
