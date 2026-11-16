<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Facades\ScarlettPlayer;
use Hei\ScarlettPlayer\Models\Partner;
use Hei\ScarlettPlayer\Partners\PartnerEmbedUrls;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Pest\Browser\Drivers\LaravelHttpServer;
use Pest\Browser\ServerManager;
use Symfony\Component\Process\Process;

/*
 * Partner frame-ancestors, enforced by a real browser (HEI-LSP-24 WP2).
 *
 * The parent page is on the Pest origin, http://127.0.0.1:<port> (the browser plugin's
 * in-process server; visit() always rewrites onto it). It embeds the REAL partner iframe
 * the minter produced, at the TLS proxy (https://127.0.0.1:<free port>, see README.md):
 * a different origin, so the browser enforces the page's `frame-ancestors` against the
 * parent. The embed page prints a beacon URL whose root comes from the URL generator,
 * which the test forces to the proxy origin (the in-process server sees every request as
 * the Pest host, so a request-derived root would point the player at the wrong place);
 * the plugin attaches its key only to https beacon URLs, so the beacon has to go through
 * the proxy too.
 *
 * "Renders" is proved by the player booting in the iframe and its viewStart beacon
 * arriving through the proxy. "Refused" is proved by the iframe document having been
 * served (the browser enforces the header after receiving it) and no viewStart ever
 * arriving. Headless Chromium has no H.264, so the media is the VP9 blank.webm.
 *
 * Cases the harness cannot express are asserted at header level in
 * tests/Feature/Partners/PartnerEmbedTest.php and are a manual pre-tag check: public
 * wildcard hosts, apex versus `*.` subdomain, and Safari.
 */

/** A free local port for this test's proxy, as in BeaconCorsTest: never a fixed one. */
function partnerFramePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

    if ($socket === false) {
        throw new RuntimeException("No free port for the TLS proxy: {$errstr}");
    }

    $name = (string) stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr($name, strrpos($name, ':') + 1);
}

function partnerFrameProxy(string $listen, int $targetPort): Process
{
    $root = dirname(__DIR__, 2);

    (new Process(['sh', __DIR__.'/support/make-cert.sh'], $root))->mustRun();

    $proxy = new Process(['node', __DIR__.'/support/tls-proxy.mjs'], $root, [
        'SCARLETT_PROXY_LISTEN' => $listen,
        'SCARLETT_PROXY_TARGET' => "http://127.0.0.1:{$targetPort}",
        'SCARLETT_PROXY_LOG' => '1',
    ]);
    $proxy->start();
    $proxy->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'tls-proxy listening on'));

    if (! $proxy->isRunning() || ! str_contains($proxy->getOutput(), 'tls-proxy listening on')) {
        throw new RuntimeException("The TLS proxy did not start on {$listen}: ".$proxy->getErrorOutput());
    }

    return $proxy;
}

/** Poll while the browser plugin's event loop keeps serving requests. */
function partnerFrameWaitFor(object $page, Closure $condition, float $seconds): bool
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
    $server = ServerManager::instance()->http();
    assert($server instanceof LaravelHttpServer);

    $this->port = $server->port;
    $this->pageOrigin = 'http://127.0.0.1:'.$server->port;
    $proxyAddress = '127.0.0.1:'.partnerFramePort();
    $this->proxyOrigin = 'https://'.$proxyAddress;

    // The beacon proof and the bundle are served through the proxy, so the iframe page
    // is https and the plugin attaches its key.
    config()->set('scarlett-player.embed.always_sign', true);
    config()->set('scarlett-player.embed.allowed_domains', []);
    config()->set('scarlett-player.player.embed_bundle', $this->proxyOrigin.'/scarlett-browser/embed.umd.cjs');

    // visit() boots the in-process server after this hook runs, and that boot points the
    // URL generator at the server (useOrigin(), forceScheme('http')). The host's own
    // setting, the TLS proxy as root over https, is applied when the parent page is
    // requested, which is before any iframe is: forceRootUrl() alone would keep the
    // request's http scheme and print an http beacon URL the https iframe cannot send.
    $forceProxyRoot = function (): void {
        URL::forceRootUrl($this->proxyOrigin);
        URL::forceScheme('https');
    };

    $this->frame = '';
    Route::get('/scarlett-browser/parent', function () use ($forceProxyRoot) {
        $forceProxyRoot();

        return response('<!doctype html><title>parent</title><iframe src="'.e($this->frame).'" width="640" height="360"></iframe>', 200, ['Content-Type' => 'text/html']);
    });
    // top (127.0.0.1) > middle (localhost) > the partner iframe.
    Route::get('/scarlett-browser/parent-chain', function () use ($forceProxyRoot) {
        $forceProxyRoot();

        return response('<!doctype html><iframe src="http://localhost:'.$this->port.'/scarlett-browser/middle" width="800" height="400"></iframe>', 200, ['Content-Type' => 'text/html']);
    });
    Route::get('/scarlett-browser/middle', fn () => response('<!doctype html><title>middle</title><iframe src="'.e($this->frame).'" width="640" height="360"></iframe>', 200, ['Content-Type' => 'text/html']));
    Route::get('/scarlett-browser/away', fn () => response('<!doctype html><title>away</title>', 200, ['Content-Type' => 'text/html']));
    Route::get('/scarlett-browser/embed.umd.cjs', fn () => response()->file($fixtures.'/1.27.1/embed.umd.cjs', ['Content-Type' => 'application/javascript']));
    Route::get('/scarlett-browser/blank.webm', fn () => response()->file($fixtures.'/blank.webm', ['Content-Type' => 'video/webm']));

    // What the app served for the partner pages: the iframe document was received even
    // when the browser then refuses to render it.
    $this->served = new ArrayObject;
    Event::listen(RequestHandled::class, function (RequestHandled $handled): void {
        if (str_starts_with($handled->request->path(), 'v/')) {
            preg_match('/data-analytics-beacon-url="([^"]*)"/', (string) $handled->response->getContent(), $beacon);

            $this->served->append([
                $handled->request->path(),
                $handled->response->getStatusCode(),
                $handled->response->headers->get('Content-Security-Policy'),
                html_entity_decode($beacon[1] ?? ''),
            ]);
        }
    });

    $this->fake = ScarlettPlayer::fake();
    $this->proxy = partnerFrameProxy($proxyAddress, $server->port);
});

afterEach(function (): void {
    URL::forceRootUrl(null);

    // Unset when the group skipped before beforeEach ran (tests/Pest.php).
    if (isset($this->proxy)) {
        $this->proxy->stop(5, SIGTERM);
    }
});

/**
 * A partner with these origins, a media of its own, and the real minted iframe URL.
 *
 * @param  list<string>  $origins
 */
function partnerFrameSetup(object $test, string $name, array $origins): string
{
    $media = new MediaSource(
        id: $name,
        playbackUrl: $test->proxyOrigin.'/scarlett-browser/blank.webm',
        isLive: false,
        isProtected: false,
        duration: 1.0,
    );

    $test->fake->withMedia($media);
    Partner::create(['ref' => $name, 'name' => $name, 'origins' => $origins]);

    return app(PartnerEmbedUrls::class)->url($name, $media, root: $test->proxyOrigin);
}

/**
 * Whether a beacon for this media reached the ingest. The facade is faked (it resolves
 * the media), and the fake swaps the beacon store for a ledger, so the ledger is the record.
 */
function partnerFrameRendered(object $test, string $media): bool
{
    return collect($test->fake->recordedBeacons())->contains(fn (array $beacon): bool => ($beacon['videoId'] ?? null) === $media);
}

/**
 * Whether the ledger holds a viewStart for this media attributed to this partner: the
 * recorded array carries `scarlett_partner` only for a beacon whose proof verified, so an
 * invalid proof still ingests (and passes partnerFrameRendered()) but fails this.
 */
function partnerFrameAttributed(object $test, string $media, string $ref): bool
{
    return collect($test->fake->recordedBeacons())->contains(
        fn (array $beacon): bool => ($beacon['event'] ?? null) === 'viewStart'
            && ($beacon['videoId'] ?? null) === $media
            && ($beacon['scarlett_partner'] ?? null) === $ref,
    );
}

/**
 * The player booted in the frame: its viewStart reached the ingest, through the proxy,
 * carrying the verified partner of the proof the page printed (the partner ref is the
 * media name, see partnerFrameSetup()).
 */
function partnerFrameAssertRenders(object $test, object $page, string $media): void
{
    expect(partnerFrameWaitFor($page, fn (): bool => partnerFrameRendered($test, $media), 20))
        ->toBeTrue('The partner iframe never rendered: served '.json_encode($test->served->getArrayCopy()).'; proxy log: '.$test->proxy->getErrorOutput());

    expect(partnerFrameWaitFor($page, fn (): bool => partnerFrameAttributed($test, $media, $media), 10))
        ->toBeTrue('The viewStart arrived without the verified partner: '.json_encode($test->fake->recordedBeacons()));

    expect($test->proxy->getErrorOutput())->toContain('POST /api/scarlett/beacons');
}

/** The document was served with the header and the browser still showed nothing. */
function partnerFrameAssertRefused(object $test, object $page, string $media): void
{
    expect(partnerFrameWaitFor($page, fn (): bool => collect($test->served->getArrayCopy())->contains(fn (array $request): bool => $request[0] === 'v/'.$media), 15))
        ->toBeTrue('The partner page was never requested: '.$test->proxy->getErrorOutput());

    // Long enough for a player that booted to send its viewStart (the positive cases see it within seconds).
    partnerFrameWaitFor($page, fn (): bool => partnerFrameRendered($test, $media), 6);

    expect(partnerFrameRendered($test, $media))->toBeFalse('The refused iframe rendered and beaconed.')
        ->and(collect($test->served->getArrayCopy())->firstWhere(0, 'v/'.$media)[1])->toBe(200);
}

it('renders the partner iframe in a parent on the exact allowed origin and beacons through the proxy', function (): void {
    $this->frame = partnerFrameSetup($this, 'pf-ok', [$this->pageOrigin]);

    $page = visit('/scarlett-browser/parent', ['ignoreHTTPSErrors' => true]);

    partnerFrameAssertRenders($this, $page, 'pf-ok');

    $served = collect($this->served->getArrayCopy())->firstWhere(0, 'v/pf-ok');

    expect($served[2])->toBe("frame-ancestors 'self' {$this->pageOrigin}")
        // The beacon proof is rooted by the URL generator at the proxy, not at the request's host.
        ->and($served[3])->toStartWith($this->proxyOrigin.'/api/scarlett/beacons?');
});

it('refuses the partner iframe when the allowed origin has another port', function (): void {
    $this->frame = partnerFrameSetup($this, 'pf-port', ['http://127.0.0.1:'.($this->port + 1)]);

    $page = visit('/scarlett-browser/parent', ['ignoreHTTPSErrors' => true]);

    partnerFrameAssertRefused($this, $page, 'pf-port');
});

it('refuses the partner iframe when the allowed origin has another host (localhost versus 127.0.0.1)', function (): void {
    $this->frame = partnerFrameSetup($this, 'pf-host', ['http://localhost:'.$this->port]);

    $page = visit('/scarlett-browser/parent', ['ignoreHTTPSErrors' => true]);

    partnerFrameAssertRefused($this, $page, 'pf-host');
});

it('refuses the partner iframe when the origins are empty', function (): void {
    $this->frame = partnerFrameSetup($this, 'pf-none', []);

    $page = visit('/scarlett-browser/parent', ['ignoreHTTPSErrors' => true]);

    partnerFrameAssertRefused($this, $page, 'pf-none');
});

it('checks the whole ancestor chain: a nested partner iframe renders only when every ancestor is allowed', function (): void {
    // Only the immediate parent is allowed: the top page is not, so the chain is refused.
    $this->frame = partnerFrameSetup($this, 'pf-chain-bad', ['http://localhost:'.$this->port]);

    $page = visit('/scarlett-browser/parent-chain', ['ignoreHTTPSErrors' => true]);

    partnerFrameAssertRefused($this, $page, 'pf-chain-bad');

    // Both ancestors allowed: the same chain renders (and proves the middle page loaded).
    $this->frame = partnerFrameSetup($this, 'pf-chain-ok', ['http://localhost:'.$this->port, $this->pageOrigin]);

    $page = visit('/scarlett-browser/parent-chain', ['ignoreHTTPSErrors' => true]);

    partnerFrameAssertRenders($this, $page, 'pf-chain-ok');
});
