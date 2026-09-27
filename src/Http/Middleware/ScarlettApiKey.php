<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a beacon by its API key, from the X-API-Key header or the api_key
 * query parameter.
 *
 * Both are needed: the analytics plugin sends the header on its fetch transport,
 * but the unload beacon goes out through navigator.sendBeacon, which cannot carry a
 * header, so the plugin appends ?api_key= instead. A route that read only the header
 * would drop every unload viewEnd, the beacon carrying the session totals.
 *
 * OPTIONS always passes: a CORS preflight carries no credentials by definition. In
 * the default stack this branch never runs: HandleCors answers a preflight as global
 * middleware, and the router answers any other OPTIONS on this POST-only route
 * itself, before route middleware. It is defence in depth, so a host that reorders
 * middleware or adds an OPTIONS route still cannot 401 a preflight.
 *
 * An unset key fails closed: every beacon is refused until beacons.key is set.
 */
class ScarlettApiKey
{
    public const HEADER = 'X-API-Key';

    public const QUERY = 'api_key';

    public function __construct(
        private readonly Repository $config,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $next($request);
        }

        $expected = $this->config->get('scarlett-player.beacons.key');
        $supplied = $request->headers->get(self::HEADER);

        if (! is_string($supplied) || $supplied === '') {
            $supplied = $request->query(self::QUERY);
        }

        if (! is_string($expected) || $expected === '' || ! is_string($supplied) || ! hash_equals($expected, $supplied)) {
            return new JsonResponse(['message' => 'Invalid beacon key.'], 401);
        }

        return $next($request);
    }
}
