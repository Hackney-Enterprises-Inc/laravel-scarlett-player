# Wire fixtures: player 1.17.0

**Captured** by the player repo's capture harness
(`scripts/capture-wire-fixtures.mjs`) against the `v1.17.0` checkout, Chromium 151.0.7922.34,
Node v24.20.0, at 2026-09-27T18:54:25.615Z. Each beacon file is
`{ fixture: { player, capturedAt, harness, chromium, node, event, transport, variant,
scenario, receivedAt }, request: { method, path, query, headers, body } }` with the browser's
headers recorded verbatim. The harness configured the key `wire-capture-key` and added an
`X-Wire-Token` header on fetch beacons; the ingest ignores unknown headers.

Nothing in this directory is edited but this file. When a captured body disagrees with
the package, the package changes. Replayed by
`tests/Feature/Beacons/WireFixturesTest.php` (beacons) and
`tests/Feature/Clips/ClipWireFixturesTest.php` (the clip pair).

`manifest.json`: sha256 `6b2c0878ae629b46`, `absent: []`.

## Files

| File | Event | Transport | Variant | Scenario | sha256 (first 16) | Status |
|---|---|---|---|---|---|---|
| `clip.create.json` | `clip` | `fetch` | `create` | `clip` | `5afd0c66e8739459` | captured |
| `clip.retry.json` | `clip` | `fetch` | `retry` | `clip` | `54159d113e18a13c` | captured |
| `custom-wireCustom.fetch.json` | `custom:wireCustom` | `fetch` | `-` | `full-session` | `9b3f17c607deca7e` | captured |
| `error.fetch.json` | `error` | `fetch` | `-` | `error` | `73be8819feee72cb` | captured |
| `heartbeat.fetch.json` | `heartbeat` | `fetch` | `-` | `full-session` | `956cbe017ae9d415` | captured |
| `heartbeat.fetch.live.json` | `heartbeat` | `fetch` | `live` | `live` | `9373959d599da4e6` | captured |
| `pause.fetch.json` | `pause` | `fetch` | `-` | `full-session` | `5c6f98501a95ba0c` | captured |
| `playRequest.fetch.json` | `playRequest` | `fetch` | `-` | `full-session` | `c45bfbfed98687b1` | captured |
| `preflight.options.json` | `None` | `preflight` | `-` | `full-session` | `238b986c2c6cd70d` | captured |
| `qualityChange.fetch.json` | `qualityChange` | `fetch` | `-` | `full-session` | `cb1244d04dfc8b3d` | captured |
| `rebufferEnd.fetch.json` | `rebufferEnd` | `fetch` | `-` | `full-session` | `98928e8504d450df` | captured |
| `rebufferStart.fetch.json` | `rebufferStart` | `fetch` | `-` | `full-session` | `3603f49157c436ce` | captured |
| `seeking.fetch.json` | `seeking` | `fetch` | `-` | `full-session` | `a01ce08fd4d23475` | captured |
| `videoStart.fetch.json` | `videoStart` | `fetch` | `-` | `full-session` | `6cee22ac363dc453` | captured |
| `viewEnd.fetch.destroy.json` | `viewEnd` | `fetch` | `destroy` | `destroy` | `b1eb3cc08aec25fe` | captured |
| `viewEnd.fetch.ended.json` | `viewEnd` | `fetch` | `ended` | `full-session` | `e7183ec80743c0fc` | captured |
| `viewEnd.fetch.error.json` | `viewEnd` | `fetch` | `error` | `error` | `f21cca56423c2381` | captured |
| `viewEnd.sendBeacon.live-unload.json` | `viewEnd` | `sendBeacon` | `live-unload` | `live` | `109e52870c9678ed` | captured |
| `viewEnd.sendBeacon.unload.json` | `viewEnd` | `sendBeacon` | `unload` | `unload` | `210bb1462b004ed6` | captured |
| `viewStart.fetch.json` | `viewStart` | `fetch` | `-` | `full-session` | `393bde15f49067d8` | captured |
| `viewStart.fetch.live.json` | `viewStart` | `fetch` | `live` | `live` | `ea2967548bf98b0a` | captured |
| `_session.sequence.json` | `full-session sequence (34 beacons)` | `-` | `-` | `full-session` | `d2ebf08f97b4ee79` | captured |
| `manifest.json` | `sequence` | `-` | `-` | `-` | `6b2c0878ae629b46` | captured |

## Harness assertions (manifest.json)

| Assertion | Result | Detail |
|---|---|---|
| every required fixture was captured | ok |  |
| no request left 127.0.0.1 | ok |  |
| every beacon body is JSON with playerVersion and playerName | ok | 75 beacons |
| fetch beacons carry X-API-Key and X-Wire-Token; the unload beacon carries neither, and api_key in the query | ok |  |
| the preflight named x-api-key in access-control-request-headers | ok |  |
| viewEnd unload is the field subset; viewEnd ended has every field | ok |  |
| a fatal error sends an error beacon with errorCode, then a viewEnd with exitType error | ok |  |
| custom dimensions sit at the top level of every beacon, types intact | ok |  |
| clip create and retry share clientRequestId and body (capturedAt aside), VOD fields, CSRF header | ok |  |
| live heartbeat and live unload viewEnd carry isLive and the latency summary; no VOD beacon has latency keys | ok | latency mean 6.13s over 2 readings, lowLatency false |
| heartbeat watchTime and rebufferCount never decrease (sanity) | ok | 14 heartbeats |
| every bus quality:change produced a qualityChange beacon matching a level | ok | 1 of 1 |

## Scenarios

| Scenario | Beacons |
|---|---|
| `full-session` | 34 |
| `unload` | 9 |
| `destroy` | 8 |
| `error` | 11 |
| `clip` | 4 |
| `live` | 9 |

The live scenario's `isLive` per event (`viewStart` false, every later event true) is what
the `is_live` true-wins merge class exists for, because a live viewStart is sent before the
playlist is read.
