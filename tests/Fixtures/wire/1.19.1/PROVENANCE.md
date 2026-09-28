# Wire fixtures: player 1.19.1

**Captured** by the player repo's capture harness
(`scripts/capture-wire-fixtures.mjs`) against the `v1.19.1` checkout, Chromium 151.0.7922.34,
Node v24.20.0, at 2026-09-28T19:01:30.445Z. Each beacon file is
`{ fixture: { player, capturedAt, harness, chromium, node, event, transport, variant,
scenario, receivedAt }, request: { method, path, query, headers, body } }` with the browser's
headers recorded verbatim. The harness configured the key `wire-capture-key` and added an
`X-Wire-Token` header on fetch beacons; the ingest ignores unknown headers.

Nothing in this directory is edited but this file. When a captured body disagrees with
the package, the package changes. Replayed by
`tests/Feature/Beacons/WireFixturesTest.php` (beacons) and
`tests/Feature/Clips/ClipWireFixturesTest.php` (the clip pair).

`manifest.json`: sha256 `f8535efb8f384965`, `absent: []`.

## Files

| File | Event | Transport | Variant | Scenario | sha256 (first 16) | Status |
|---|---|---|---|---|---|---|
| `clip.create.json` | `clip` | `fetch` | `create` | `clip` | `fe4e34ccdb73d18e` | captured |
| `clip.retry.json` | `clip` | `fetch` | `retry` | `clip` | `534e739898e2ffb7` | captured |
| `custom-wireCustom.fetch.json` | `custom:wireCustom` | `fetch` | `-` | `full-session` | `ed958e301daae639` | captured |
| `error.fetch.json` | `error` | `fetch` | `-` | `error` | `0f31f6b674fb2be4` | captured |
| `heartbeat.fetch.json` | `heartbeat` | `fetch` | `-` | `full-session` | `b5971e1b57fdb077` | captured |
| `heartbeat.fetch.live.json` | `heartbeat` | `fetch` | `live` | `live` | `642fbea3c2fec97b` | captured |
| `pause.fetch.json` | `pause` | `fetch` | `-` | `full-session` | `8cc485e91495667e` | captured |
| `playRequest.fetch.json` | `playRequest` | `fetch` | `-` | `full-session` | `843567572d938ffc` | captured |
| `preflight.options.json` | `None` | `preflight` | `-` | `full-session` | `90782ecaff0451b8` | captured |
| `qualityChange.fetch.json` | `qualityChange` | `fetch` | `-` | `full-session` | `071dd278c0a68da6` | captured |
| `rebufferEnd.fetch.json` | `rebufferEnd` | `fetch` | `-` | `full-session` | `5cc0c69dd91d994a` | captured |
| `rebufferStart.fetch.json` | `rebufferStart` | `fetch` | `-` | `full-session` | `c5a9f45080bae207` | captured |
| `seeking.fetch.json` | `seeking` | `fetch` | `-` | `full-session` | `8e34eb4ddc87f39b` | captured |
| `videoStart.fetch.json` | `videoStart` | `fetch` | `-` | `full-session` | `29a6e5166e2e0023` | captured |
| `viewEnd.fetch.destroy.json` | `viewEnd` | `fetch` | `destroy` | `destroy` | `0d35b4a222dfbe00` | captured |
| `viewEnd.fetch.ended.json` | `viewEnd` | `fetch` | `ended` | `full-session` | `f084bf0be534a9bc` | captured |
| `viewEnd.fetch.error.json` | `viewEnd` | `fetch` | `error` | `error` | `57de816e4489285e` | captured |
| `viewEnd.sendBeacon.live-unload.json` | `viewEnd` | `sendBeacon` | `live-unload` | `live` | `644ba2aa4f00ec70` | captured |
| `viewEnd.sendBeacon.unload.json` | `viewEnd` | `sendBeacon` | `unload` | `unload` | `285041eb5639abcd` | captured |
| `viewStart.fetch.json` | `viewStart` | `fetch` | `-` | `full-session` | `2d4adc19bba32cfa` | captured |
| `viewStart.fetch.live.json` | `viewStart` | `fetch` | `live` | `live` | `bca681bded65648b` | captured |
| `_session.sequence.json` | `full-session sequence (26 beacons)` | `-` | `-` | `full-session` | `ff8bd2326ebcf150` | captured |
| `manifest.json` | `sequence` | `-` | `-` | `-` | `f8535efb8f384965` | captured |

## Harness assertions (manifest.json)

| Assertion | Result | Detail |
|---|---|---|
| every required fixture was captured | ok |  |
| no request left 127.0.0.1 | ok |  |
| every beacon body is JSON with playerVersion and playerName | ok | 67 beacons |
| fetch beacons carry X-API-Key and X-Wire-Token; the unload beacon carries neither, and api_key in the query | ok |  |
| the preflight named x-api-key in access-control-request-headers | ok |  |
| viewEnd unload is the field subset; viewEnd ended has every field | ok |  |
| videoStart.startupTime is above zero on VOD (play request to first frame, SCAR-ANALYTICS-4) | ok | 215 ms |
| a fatal error sends an error beacon with errorCode, then a viewEnd with exitType error | ok |  |
| custom dimensions sit at the top level of every beacon, types intact | ok |  |
| clip create and retry share clientRequestId and body (capturedAt aside), VOD fields, CSRF header | ok |  |
| live heartbeat and live unload viewEnd carry isLive and the latency summary; no VOD beacon has latency keys | ok | latency mean 6.12s over 2 readings, lowLatency false |
| isLive is null on every viewStart, then false on VOD and true on live | ok | 6 viewStart null |
| heartbeat watchTime and rebufferCount never decrease (sanity) | ok | 13 heartbeats |
| every bus quality:change produced a qualityChange beacon matching a level | ok | 1 of 1 |

## Scenarios

| Scenario | Beacons |
|---|---|
| `full-session` | 26 |
| `unload` | 9 |
| `destroy` | 8 |
| `error` | 12 |
| `clip` | 4 |
| `live` | 8 |

Every `viewStart` carries `isLive: null` (from player 1.18 it is sent before live-ness is
known); the ingest treats null as absent, and the live scenario's later events say true,
which the `is_live` true-wins merge class keeps.

Against the 1.17.0 set: the same files, keys, event names and exit types. The full session
recorded 26 beacons (34 in the 1.17.0 set); from 1.18 a rebuffer recovery no longer sends
`playRequest`, and `startupTime` measures play request to first frame, so its value moved
while its key did not.

Not in this set: the view boundary player 1.19 added (a playlist track change, `setVideo()`
with another id, or a replay after `ended` closes the view with `exitType` `abandoned` and
starts a new `viewId`). The harness has no video-change scenario; adding one is a player
repo item. The store keys every row by `viewId`, so a new view is a new row either way.
