# Browser tests

The `browser` group drives a real Chromium (Pest 4 browser plugin on Playwright) to prove
what a server-side client cannot: that the analytics plugin's beacons, including the unload
`viewEnd` sent with `navigator.sendBeacon`, survive the browser's CORS enforcement against
this package's ingest. The group skips unless `SCARLETT_BROWSER=1` is set.

## The origins

`BeaconCorsTest.php` runs the real analytics plugin from the pinned
`@scarlett-player/embed` 1.17.0 bundle (`tests/Fixtures/player/`, see its `PROVENANCE.md`)
with `beacons.key` set and `apiKey` configured in every case.

| Origin | Serves | Why |
|---|---|---|
| `http://127.0.0.1:<port>` | the test page, the bundle, a tiny mp4, and the ingest itself | the browser plugin's in-process server: `visit()` rewrites every URL onto it, so the page lives here, and serving the ingest from the same app lets each case set its own `cors` config and read the store directly |
| `https://127.0.0.1:<free port>` | the `beaconUrl` the page gives the plugin: `support/tls-proxy.mjs`, forwarding to `<port>`; a new free port per test, so a proxy orphaned by a killed run or a previous run's Chromium can never be in the path | the plugin attaches the API key only when `beaconUrl` is `https:` (`analytics/src/helpers.ts` `isHttpsUrl()`, no localhost exception); a different scheme and port is a different origin, so every beacon is cross-origin and preflights |

The build plan's table names three fixed ports (page 8001, ingest 8002, proxy 8443). The
browser plugin cannot serve a page from anywhere but its own server, so the page and the
ingest share it; the cross-origin and HTTPS requirements are met the same way, through the
proxy. The page stays plain http on purpose: an http page may post to an https origin, so
one certificate meets both. The proxy's default target stays `http://127.0.0.1:8002` for
running it by hand in front of `vendor/bin/testbench serve --port=8002`.

The three assertions: (1) in-session beacons arrive with `X-API-Key`; (2) with the
credentialed CORS recipe the unload `viewEnd` (`navigator.sendBeacon` on `pagehide`)
arrives with the key from `?api_key=` and is stored; (3) with `supports_credentials` false
the unload `viewEnd` never arrives, while (1) still holds and its preflight does reach the
ingest, so the absence is CORS refusing it and not a beacon that was never sent.

The unload is fired as the plugin's own `pagehide` handler on the live page, and the page
navigates away afterwards (asserting no second `viewEnd`). Navigating first was flaky:
when a same-site navigation tears the page down while the beacon's preflight is in flight,
Chromium sometimes drops the POST after a successful preflight. Measured 2026-09-27, 20
runs each through the same proxy: same-site navigation 13 of 20 delivered, cross-site 19 of
20, live page 20 of 20. On a live page the beacon still uses `navigator.sendBeacon` with
credentials `include` and the query key, and gets the same CORS verdict. The proxy logs
each request to stderr when `SCARLETT_PROXY_LOG=1`; the test turns it on and prints the
log with the ingest's view in any failing assertion. Requests are counted only from the
test's own page origin, because a previous run's Chromium can keep sending heartbeats for
a few seconds after it ends. A proxy that fails to start (a port already bound) fails the
test by name instead of surfacing as a `viewStart` that never arrives.

The proxy is transparent by contract, or the CORS assertions would be testing the proxy:
`OPTIONS` is forwarded like any other method, request headers (`Origin` and `Host`
included) go out exactly as they arrived, the body is streamed byte for byte, the path and
query string are untouched (the unload beacon carries `?api_key=`), and the ingest's status
and response headers come back verbatim, `Access-Control-*` included. The one
normalisation: a header name repeated with different case goes out under its first
spelling, with every value in order. `TlsProxySmokeTest.php` proves this against an echo
target (`support/echo-target.php`) on ports 48002 and 48443, away from the defaults, and
that a response the target misframes (a 204 with `transfer-encoding: chunked`, which the
browser plugin's amphp server sends for a preflight; `support/misframed-target.mjs`) comes
back as that response rather than a 502.

## Running it locally

Needs Node 20 or later, `openssl`, and Playwright's Chromium:

```bash
npm install
npm run browser:install                 # npx playwright install chromium
SCARLETT_BROWSER=1 vendor/bin/pest --group=browser
```

The tests start and stop the servers they need. To run the proxy by hand:

```bash
npm run cert                            # sh tests/Browser/support/make-cert.sh
npm run proxy                           # node tests/Browser/support/tls-proxy.mjs
```

| Variable | Default |
|---|---|
| `SCARLETT_PROXY_LISTEN` | `127.0.0.1:8443` |
| `SCARLETT_PROXY_TARGET` | `http://127.0.0.1:8002` |
| `SCARLETT_PROXY_CERT` | `tests/Fixtures/tls/cert.pem` |
| `SCARLETT_PROXY_KEY` | `tests/Fixtures/tls/key.pem` |
| `SCARLETT_PROXY_LOG` | unset; `1` logs each request and its outcome to stderr |

The proxy prints `tls-proxy listening on https://HOST:PORT -> TARGET` once it is ready and
exits cleanly on `SIGTERM` or `SIGINT`. It answers 502 with `X-Scarlett-Proxy-Error: 1`
when the target cannot be reached before it answers, the only response it writes itself.

## The certificate

`make-cert.sh` writes a self-signed certificate and key for `127.0.0.1` and `localhost`
into `tests/Fixtures/tls/`, which is gitignored. It is regenerated on every run: nothing
depends on a particular certificate, and nothing trusts it except a browser told to ignore
certificate errors.

The browser context needs `ignoreHTTPSErrors`. Pest's browser plugin (4.3.1) sets it only
as a launch option, which Playwright does not apply to the contexts it creates, so without
more a fetch to the proxy fails with `TypeError: Failed to fetch` and nothing reaches the proxy.
`visit()` spreads its options into the new context, so the test passes it there:
`visit('/scarlett-browser/page', ['ignoreHTTPSErrors' => true])`. No direct Playwright
launch is needed.

## Test routes

The page routes are registered by the test, not by the package: `/scarlett-browser/page`,
`/scarlett-browser/away` (the navigation that fires `pagehide`), `/scarlett-browser/embed.umd.cjs`
and `/scarlett-browser/blank.webm` (VP9: headless Chromium has no H.264). The test reads the in-process server's port from
`Pest\Browser\ServerManager`, an `@internal` class of the plugin, so a plugin upgrade may
need that line changed.
