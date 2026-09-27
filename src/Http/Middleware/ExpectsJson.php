<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The clips plugin posts JSON without an Accept header, so Laravel would answer a
 * validation or auth failure with a redirect the overlay cannot show. Runs ahead of the
 * configured clips middleware so every clip error is a JSON body with a message.
 */
class ExpectsJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
