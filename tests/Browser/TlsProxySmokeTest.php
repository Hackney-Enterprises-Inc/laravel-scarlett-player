<?php

declare(strict_types=1);

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/*
 * Proves tests/Browser/support/tls-proxy.mjs is invisible, which the beacon CORS
 * browser tests depend on: a plain-http echo target (support/echo-target.php on
 * PHP's built-in server) sits behind the proxy, and each request is compared with
 * what the target received and what it answered. Ports are free ones the OS
 * picks for each run, never the 8001, 8002 and 8443 defaults, so a running ingest
 * or page server is never hit. Fixed high ports sit in Linux's ephemeral range,
 * where an earlier test's client socket can hold them (CI saw "Address already
 * in use" on 48002 after BeaconCorsTest).
 */

const SMOKE_ORIGIN = 'http://127.0.0.1:8001';

/** A free 127.0.0.1 port, as host:port. */
function smokeFreeAddress(): string
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

    if ($socket === false) {
        throw new RuntimeException("No free port for the TLS proxy smoke test: {$errstr}");
    }

    $name = (string) stream_socket_get_name($socket, false);
    fclose($socket);

    return $name;
}

/**
 * Starts the echo target and the proxy, runs the callback with their addresses,
 * and always stops both.
 *
 * @param  Closure(string, string): void  $callback  receives the proxy, then the target
 */
function smokeWithTlsProxy(Closure $callback): void
{
    $root = dirname(__DIR__, 2);
    $support = __DIR__.'/support';
    $targetAddress = smokeFreeAddress();
    $proxyAddress = smokeFreeAddress();

    $cert = new Process(['sh', $support.'/make-cert.sh'], $root);
    $cert->mustRun();

    $target = new Process([PHP_BINARY, '-S', $targetAddress, $support.'/echo-target.php'], $root);
    $proxy = new Process(['node', $support.'/tls-proxy.mjs'], $root, [
        'SCARLETT_PROXY_LISTEN' => $proxyAddress,
        'SCARLETT_PROXY_TARGET' => 'http://'.$targetAddress,
    ]);

    try {
        $target->start();
        smokeWaitForPort($targetAddress, $target);

        $proxy->start();
        $proxy->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'tls-proxy listening on'));

        $callback($proxyAddress, $targetAddress);
    } finally {
        $proxy->stop(5, SIGTERM);
        $target->stop(5);
    }
}

function smokeWaitForPort(string $address, Process $process): void
{
    [$host, $port] = explode(':', $address);

    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen($host, (int) $port, $errno, $errstr, 0.1);

        if ($socket !== false) {
            fclose($socket);

            return;
        }

        if (! $process->isRunning()) {
            throw new RuntimeException("[{$address}] exited before listening: ".$process->getErrorOutput());
        }

        usleep(100_000);
    }

    throw new RuntimeException("[{$address}] did not start listening");
}

/**
 * The response headers the proxy is expected to leave alone: everything except
 * the ones that legitimately differ between two separate responses.
 *
 * @return array<string, list<string>>
 */
function smokeComparableHeaders(Response $response): array
{
    $headers = array_change_key_case($response->headers(), CASE_LOWER);

    unset($headers['date']);

    ksort($headers);

    return $headers;
}

it('passes an OPTIONS preflight through with its Origin and returns the target headers verbatim', function (): void {
    Http::allowStrayRequests();

    smokeWithTlsProxy(function (string $proxyAddress, string $targetAddress): void {
        $send = fn (string $base): Response => Http::withOptions(['verify' => false])
            ->withHeaders([
                'Origin' => SMOKE_ORIGIN,
                'Host' => $proxyAddress,
                'Access-Control-Request-Method' => 'POST',
                'Access-Control-Request-Headers' => 'content-type,x-api-key',
            ])
            ->send('OPTIONS', $base.'/api/scarlett/beacons');

        $proxied = $send('https://'.$proxyAddress);
        $direct = $send('http://'.$targetAddress);

        expect($proxied->status())->toBe(200)
            ->and($proxied->header('X-Scarlett-Echo'))->toBe('target')
            ->and($proxied->header('Access-Control-Allow-Origin'))->toBe(SMOKE_ORIGIN)
            ->and($proxied->header('Access-Control-Allow-Credentials'))->toBe('true')
            ->and(smokeComparableHeaders($proxied))->toBe(smokeComparableHeaders($direct))
            ->and($proxied->json('method'))->toBe('OPTIONS')
            ->and($proxied->json('headers.origin'))->toBe(SMOKE_ORIGIN)
            ->and($proxied->json('headers.access-control-request-headers'))->toBe('content-type,x-api-key')
            ->and($proxied->json('headers'))->toBe($direct->json('headers'));
    });
});

it('passes a POST through with its query string and a byte-identical body', function (): void {
    Http::allowStrayRequests();

    smokeWithTlsProxy(function (string $proxyAddress): void {
        $body = json_encode(['event' => 'viewEnd', 'viewId' => 'v-1', 'videoTitle' => "caf\u{e9} \u{1f3ac}"], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $response = Http::withOptions(['verify' => false])
            ->withHeaders(['Origin' => SMOKE_ORIGIN])
            ->withBody($body, 'application/json')
            ->post('https://'.$proxyAddress.'/api/scarlett/beacons?api_key=abc');

        expect($response->status())->toBe(201)
            ->and($response->header('X-Scarlett-Echo'))->toBe('target')
            ->and($response->json('method'))->toBe('POST')
            ->and($response->json('uri'))->toBe('/api/scarlett/beacons?api_key=abc')
            ->and($response->json('query'))->toBe('api_key=abc')
            ->and($response->json('headers.origin'))->toBe(SMOKE_ORIGIN)
            ->and($response->json('headers.content-type'))->toBe('application/json')
            ->and(base64_decode((string) $response->json('body_base64'), true))->toBe($body)
            ->and($response->json('body_sha256'))->toBe(hash('sha256', $body));
    });
});

it('returns a 204 the target misframes as chunked as that 204, never a 502', function (): void {
    Http::allowStrayRequests();

    $root = dirname(__DIR__, 2);
    $support = __DIR__.'/support';

    (new Process(['sh', $support.'/make-cert.sh'], $root))->mustRun();

    $targetAddress = smokeFreeAddress();
    $proxyAddress = smokeFreeAddress();
    $target = new Process(['node', $support.'/misframed-target.mjs', substr($targetAddress, strrpos($targetAddress, ':') + 1)], $root);
    $proxy = new Process(['node', $support.'/tls-proxy.mjs'], $root, [
        'SCARLETT_PROXY_LISTEN' => $proxyAddress,
        'SCARLETT_PROXY_TARGET' => 'http://'.$targetAddress,
    ]);

    try {
        $target->start();
        $target->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'listening'));
        $proxy->start();
        $proxy->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'tls-proxy listening on'));

        $response = Http::withOptions(['verify' => false])
            ->withHeaders(['Origin' => SMOKE_ORIGIN, 'Access-Control-Request-Method' => 'POST'])
            ->send('OPTIONS', 'https://'.$proxyAddress.'/api/scarlett/beacons?api_key=abc');

        expect($response->status())->toBe(204)
            ->and($response->header('X-Scarlett-Echo'))->toBe('misframed')
            ->and($response->header('Access-Control-Allow-Credentials'))->toBe('true')
            ->and($response->header('X-Scarlett-Proxy-Error'))->toBe('');
    } finally {
        $proxy->stop(5, SIGTERM);
        $target->stop(5, SIGTERM);
    }
});
