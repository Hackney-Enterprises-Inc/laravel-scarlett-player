<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Doctor\Checks;

use Hei\ScarlettPlayer\Doctor\Check;
use Hei\ScarlettPlayer\Doctor\CheckResult;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;

/**
 * The host's config/cors.php can carry the unload beacon.
 *
 * The in-session beacons are fetch with credentials same-origin, which any CORS
 * setup passes. The unload viewEnd is navigator.sendBeacon, which sends with
 * credentials include: the browser then needs an exact Access-Control-Allow-Origin
 * and Access-Control-Allow-Credentials: true, and a `*` origin fails it with no
 * error anywhere. So an ingest that looks fine in the network tab can be losing
 * every session total. This check warns on each way that happens; it cannot prove
 * the browser accepts the response (the package's browser test does).
 */
class CorsCheck implements Check
{
    public function __construct(
        private readonly Repository $config,
    ) {}

    public function name(): string
    {
        return 'beacon cors';
    }

    public function run(): CheckResult
    {
        if (! $this->config->get('scarlett-player.routes.beacons') || ! $this->config->get('scarlett-player.beacons.enabled')) {
            return CheckResult::pass('beacons are off; no CORS needed');
        }

        $path = trim((string) $this->config->get('scarlett-player.routes.prefix'), '/').'/beacons';
        $problems = [];

        $paths = array_values(array_map('strval', (array) $this->config->get('cors.paths', [])));

        if (! $this->matchesAny($path, $paths)) {
            $problems[] = "cors.paths does not cover [{$path}]";
        }

        $credentials = (bool) $this->config->get('cors.supports_credentials', false);

        if (! $credentials) {
            $problems[] = 'cors.supports_credentials is false, so every unload viewEnd (sendBeacon, credentials include) is dropped';
        }

        $origins = array_map('strval', (array) $this->config->get('cors.allowed_origins', []));

        if ($credentials && in_array('*', $origins, true)) {
            $problems[] = "cors.allowed_origins contains '*' with supports_credentials: list the embedding origins explicitly";
        }

        $headers = array_map(fn (mixed $header): string => strtolower((string) $header), (array) $this->config->get('cors.allowed_headers', []));

        if (! in_array('*', $headers, true) && ! in_array('x-api-key', $headers, true)) {
            $problems[] = 'cors.allowed_headers is missing X-API-Key, so in-session beacons fail their preflight';
        }

        if ($problems !== []) {
            return CheckResult::warn(implode('; ', $problems).' (README: the CORS recipe)');
        }

        return CheckResult::pass("[{$path}] is covered with credentials and an explicit origin list");
    }

    /**
     * The way HandleCors matches cors.paths: a pattern per entry, `*` a wildcard.
     *
     * @param  list<string>  $patterns
     */
    private function matchesAny(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $pattern = trim($pattern, '/');

            if ($pattern === '*' || Str::is($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
