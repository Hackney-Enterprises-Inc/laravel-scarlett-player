<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Tests\TestCase;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Pest\Browser\Drivers\LaravelHttpServer;
use Pest\Browser\ServerManager;
use Symfony\Component\Process\Process;

/*
 * The two-origin beacon test: the real analytics plugin from the
 * pinned @scarlett-player/embed 1.22.0 bundle, in Chromium, beaconing cross-origin to
 * this package's ingest over HTTPS, with beacons.key set and apiKey configured in
 * every case.
 *
 * Origins. The page is served by the browser plugin's in-process server (Pest's
 * visit() always rewrites onto it), http://127.0.0.1:<port>. The page gives the
 * plugin beaconUrl https://127.0.0.1:<free port>, the TLS proxy (support/tls-proxy.mjs),
 * which forwards to that same in-process app. A different scheme and port is a
 * different origin, so every beacon is cross-origin and preflights, and the plugin
 * sees an https beaconUrl, the only kind it sends its key to (helpers.ts
 * isHttpsUrl(), no localhost exception). Serving the ingest in-process means each
 * case sets its own CORS config and reads the store directly; the proxy is
 * transparent (TlsProxySmokeTest proves it), so the browser sees the ingest's own
 * CORS headers.
 *
 * Assertions: (1) in-session beacons arrive with X-API-Key; (2) with the credentialed
 * recipe the unload viewEnd arrives with the key from ?api_key= and is stored;
 * (3) with supports_credentials false the unload viewEnd never arrives while (1)
 * still holds.
 *
 * The unload is fired as the plugin's own `pagehide` handler on the LIVE page, then
 * the page navigates away. Navigating first was flaky, for a reason outside this
 * package: the unload beacon is a Blob typed application/json, so it preflights, and
 * when a same-site navigation tears the document down while that preflight is in
 * flight, Chromium (HeadlessChrome 153) sometimes drops the POST after a successful
 * preflight (7 of 20 runs; 1 of 20 cross-site; 0 of 20 on a live page, all through
 * the same proxy, 2026-09-27). That is a real-world delivery loss the player owns
 * (README Beacons); here it only made the CORS assertion depend on teardown timing.
 * On a live page the beacon still goes out through navigator.sendBeacon with
 * credentials include and ?api_key=, and its preflight gets the same CORS verdict, so
 * both cases prove the recipe and nothing else: case 3's absence can only be CORS.
 */

const CORS_VIDEO = 'video-browser';

/**
 * The plan's config/cors.php block for an ingest on the default prefix.
 *
 * @return array<string, mixed>
 */
function corsBrowserRecipe(string $pageOrigin, bool $credentials): array
{
    return [
        'paths' => ['api/scarlett/beacons'],
        'allowed_methods' => ['POST', 'OPTIONS'],
        'allowed_origins' => [$pageOrigin],
        'allowed_origins_patterns' => [],
        'allowed_headers' => ['Content-Type', 'X-API-Key'],
        'exposed_headers' => [],
        'max_age' => 0,
        'supports_credentials' => $credentials,
    ];
}

/**
 * Start the TLS proxy in front of the in-process app, after regenerating the cert.
 */
/**
 * A free local port for this test's proxy. A fixed 8443 could be held by a proxy
 * orphaned when an earlier run was killed, or be the target of a previous run's
 * Chromium still sending heartbeats: the new proxy would then fail to bind and the
 * page's beacons would go to the stale one.
 */
function corsFreePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

    if ($socket === false) {
        throw new RuntimeException("No free port for the TLS proxy: {$errstr}");
    }

    $name = (string) stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr($name, strrpos($name, ':') + 1);
}

function corsStartProxy(string $listen, int $targetPort): Process
{
    $root = dirname(__DIR__, 2);

    (new Process(['sh', __DIR__.'/support/make-cert.sh'], $root))->mustRun();

    $proxy = new Process(['node', __DIR__.'/support/tls-proxy.mjs'], $root, [
        'SCARLETT_PROXY_LISTEN' => $listen,
        'SCARLETT_PROXY_TARGET' => "http://127.0.0.1:{$targetPort}",
        // stderr only; attached to a failing assertion (corsDiagnostics()).
        'SCARLETT_PROXY_LOG' => '1',
    ]);
    $proxy->start();
    $proxy->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'tls-proxy listening on'));

    // waitUntil() also returns when the process exits: a proxy that failed to bind
    // must fail the test here, by name, not as a viewStart that never arrives.
    if (! $proxy->isRunning() || ! str_contains($proxy->getOutput(), 'tls-proxy listening on')) {
        throw new RuntimeException("The TLS proxy did not start on {$listen}: ".$proxy->getErrorOutput());
    }

    return $proxy;
}

/**
 * The page: the pinned bundle, an mp4 the native provider can load, and the
 * analytics attributes from packages/embed/README.md.
 */
function corsPage(string $proxy): string
{
    $beaconUrl = 'https://'.$proxy.'/api/scarlett/beacons';
    $key = TestCase::BEACON_KEY;
    $video = CORS_VIDEO;

    return <<<HTML
        <!doctype html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>Scarlett beacon origin test</title>
            <script src="/scarlett-browser/embed.umd.cjs"></script>
        </head>
        <body>
            <div id="player" data-scarlett-player
                data-src="/scarlett-browser/blank.webm"
                data-analytics-beacon-url="{$beaconUrl}"
                data-analytics-video-id="{$video}"
                data-analytics-api-key="{$key}"></div>
        </body>
        </html>
        HTML;
}

/**
 * Fire the analytics plugin's unload path on the live page: its pagehide listener
 * calls sendUnloadBeacon(), i.e. navigator.sendBeacon with ?api_key=.
 */
function corsFireUnload(object $page): void
{
    $page->script('() => { window.dispatchEvent(new PageTransitionEvent("pagehide", { persisted: false })); }');
}

/**
 * What the ingest and the proxy saw, for a failing assertion's message.
 */
function corsDiagnostics(object $test): string
{
    return 'ingest saw '.json_encode($test->seen->getArrayCopy()).'; proxy log: '.$test->proxy->getErrorOutput();
}

/**
 * Poll while the browser plugin's event loop keeps serving requests.
 */
function corsWaitFor(object $page, Closure $condition, float $seconds): bool
{
    $deadline = microtime(true) + $seconds;

    while (microtime(true) < $deadline) {
        if ($condition()) {
            return true;
        }

        $page->wait(0.2);
    }

    return $condition();
}

beforeEach(function (): void {
    $this->usesMigrations();
    config()->set('queue.default', 'sync');

    $fixtures = dirname(__DIR__).'/Fixtures/player';

    $this->proxyAddress = '127.0.0.1:'.corsFreePort();

    Route::get('/scarlett-browser/page', fn () => response(corsPage($this->proxyAddress), 200, ['Content-Type' => 'text/html; charset=utf-8']));
    Route::get('/scarlett-browser/away', fn () => response('<!doctype html><title>away</title>', 200, ['Content-Type' => 'text/html']));
    Route::get('/scarlett-browser/embed.umd.cjs', fn () => response()->file($fixtures.'/1.22.0/embed.umd.cjs', ['Content-Type' => 'application/javascript']));
    // VP9/WebM, not H.264: headless Chromium has no proprietary codecs, and from 1.17.0
    // the player refuses an mp4 it cannot play with a fatal error, whose viewEnd
    // (exitType error, sent by fetch) ends the view before the unload path runs.
    Route::get('/scarlett-browser/blank.webm', fn () => response()->file($fixtures.'/blank.webm', ['Content-Type' => 'video/webm']));

    // Every beacon request as the ingest saw it: method, key transport, event, status.
    $this->seen = new ArrayObject;
    Event::listen(RequestHandled::class, function (RequestHandled $handled): void {
        if (! str_ends_with($handled->request->path(), 'scarlett/beacons')) {
            return;
        }

        $body = json_decode($handled->request->getContent(), true);

        $this->seen->append([
            'method' => $handled->request->getMethod(),
            'origin' => $handled->request->headers->get('Origin'),
            'header' => $handled->request->headers->get('X-API-Key'),
            'query' => $handled->request->query('api_key'),
            'event' => is_array($body) ? ($body['event'] ?? null) : null,
            'status' => $handled->response->getStatusCode(),
        ]);
    });

    $server = ServerManager::instance()->http();
    assert($server instanceof LaravelHttpServer);

    $this->pageOrigin = 'http://127.0.0.1:'.$server->port;
    $this->proxy = corsStartProxy($this->proxyAddress, $server->port);
});

afterEach(function (): void {
    // Unset when the group skipped before beforeEach ran (tests/Pest.php).
    if (isset($this->proxy)) {
        $this->proxy->stop(5, SIGTERM);
    }
});

/**
 * Requests from this test's page only: a previous run's Chromium can still be
 * sending heartbeats for a few seconds after it ended.
 *
 * @return list<array{method: string, origin: ?string, header: ?string, query: mixed, event: mixed, status: int}>
 */
function corsSeen(object $test, string $method, ?string $event): array
{
    return array_values(array_filter(
        $test->seen->getArrayCopy(),
        fn (array $request): bool => $request['origin'] === $test->pageOrigin
            && $request['method'] === $method
            && $request['event'] === $event,
    ));
}

it('receives the unload viewEnd with the key from ?api_key= under the credentialed CORS recipe', function (): void {
    config()->set('cors', corsBrowserRecipe($this->pageOrigin, credentials: true));

    $page = visit('/scarlett-browser/page', ['ignoreHTTPSErrors' => true]);

    // (1) The in-session viewStart: fetch, key in the X-API-Key header, stored.
    expect(corsWaitFor($page, fn (): bool => corsSeen($this, 'POST', 'viewStart') !== [], 15))->toBeTrue();

    $viewStart = corsSeen($this, 'POST', 'viewStart')[0];

    expect($viewStart['header'])->toBe(TestCase::BEACON_KEY)
        ->and($viewStart['query'])->toBeNull()
        ->and($viewStart['status'])->toBe(204);

    // (2) The plugin's unload path: sendBeacon, no header, key on the URL.
    corsFireUnload($page);

    expect(corsWaitFor($page, fn (): bool => corsSeen($this, 'POST', 'viewEnd') !== [], 15))
        ->toBeTrue('The unload viewEnd never arrived: '.corsDiagnostics($this));

    $viewEnd = corsSeen($this, 'POST', 'viewEnd')[0];

    expect($viewEnd['header'])->toBeNull()
        ->and($viewEnd['query'])->toBe(TestCase::BEACON_KEY)
        ->and($viewEnd['status'])->toBe(204);

    $view = DB::table('scarlett_views')->where('video_id', CORS_VIDEO)->sole();

    expect($view->ended_at)->not->toBeNull()
        ->and($view->exit_type)->toBe('abandoned')
        ->and($view->player_version)->toBe('1.22.0')
        ->and(DB::table('scarlett_beacon_events')->where('event', 'viewEnd')->count())->toBe(1);

    // Player 1.22.0: the unload viewEnd carries the full field set (no longer a subset),
    // and a native source that never reports a bitrate sends null, not 0.
    $raw = json_decode((string) DB::table('scarlett_beacon_events')->where('event', 'viewEnd')->value('payload'), true);
    expect($raw)->toHaveKeys(['qoeScore', 'qoeVersion', 'rebufferRatio', 'completionRate', 'pauseDuration', 'seekCount', 'elementSeekCount', 'reconnectCount', 'reconnectDuration', 'warningCount'])
        ->and($raw['qoeVersion'])->toBe(2)
        ->and($raw)->not->toHaveKeys(['avgBitrate', 'maxBitrate'])
        ->and($view->avg_bitrate)->toBeNull()
        ->and($view->max_bitrate)->toBeNull()
        ->and((int) $view->reconnect_count)->toBe(0);

    // Then the page really leaves. The plugin's guard (session.viewEnd) sends no second
    // unload beacon, so exactly one viewEnd is ever stored.
    $page->navigate('/scarlett-browser/away');
    corsWaitFor($page, fn (): bool => count(corsSeen($this, 'POST', 'viewEnd')) > 1, 2);

    expect(corsSeen($this, 'POST', 'viewEnd'))->toHaveCount(1)
        ->and(DB::table('scarlett_beacon_events')->where('event', 'viewEnd')->count())->toBe(1);
});

it('never receives the unload viewEnd when supports_credentials is false, while in-session beacons still arrive', function (): void {
    config()->set('cors', corsBrowserRecipe($this->pageOrigin, credentials: false));

    $page = visit('/scarlett-browser/page', ['ignoreHTTPSErrors' => true]);

    // (1) still holds: fetch uses credentials same-origin, which needs no credentialed CORS.
    expect(corsWaitFor($page, fn (): bool => corsSeen($this, 'POST', 'viewStart') !== [], 15))->toBeTrue()
        ->and(corsSeen($this, 'POST', 'viewStart')[0]['header'])->toBe(TestCase::BEACON_KEY);

    corsFireUnload($page);

    // The browser tries: the unload beacon's preflight, key on the URL, reaches the
    // ingest. On a live page nothing else can stop the POST that follows it.
    $unloadPreflight = fn (): array => array_filter(
        corsSeen($this, 'OPTIONS', null),
        fn (array $request): bool => $request['query'] === TestCase::BEACON_KEY,
    );

    expect(corsWaitFor($page, fn (): bool => $unloadPreflight() !== [], 15))
        ->toBeTrue('The unload preflight never arrived: '.corsDiagnostics($this));

    // (3) sendBeacon sends with credentials include: the preflight answer lacks
    // Access-Control-Allow-Credentials, so the browser never sends the POST. The
    // positive case sees it within milliseconds of the preflight; 5 s is generous.
    corsWaitFor($page, fn (): bool => corsSeen($this, 'POST', 'viewEnd') !== [], 5);

    expect(corsSeen($this, 'POST', 'viewEnd'))->toBe([], corsDiagnostics($this))
        ->and(DB::table('scarlett_beacon_events')->where('event', 'viewEnd')->count())->toBe(0)
        ->and(DB::table('scarlett_views')->where('video_id', CORS_VIDEO)->sole()->ended_at)->toBeNull();
});
