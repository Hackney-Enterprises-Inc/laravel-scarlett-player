# Player 1.22.0 beacon fixtures

Status: **derived (recapture owed)**. Prepared 2026-10-06 from the uncommitted
working tree of the player repository for the planned `@scarlett-player/analytics`
1.22.0 contract. Not a released npm build and not a browser capture. Since package
0.5.0 the default player pin is 1.22.0; these envelopes are still derived, not captured.

Directory name: `1.22.0-derived/`, not `1.22.0/`. The player's capture harness
writes `tests/Fixtures/wire/<version>/` by default and refuses an existing
directory, so the captured set will live in `1.22.0/` beside this one.

Re-verified 2026-10-06 against the versioned player checkout (analytics and embed
`package.json` at 1.22.0, CHANGELOG 1.22.0 section): every field, type, nullability
and event in these envelopes matches the released source. At 17:08Z npm still answered
404 for `@scarlett-player/analytics@1.22.0`; once its tarball was served (17:14:54Z) the
published `dist/index.js` was read as text and its builders (`cumulativeMetrics()`,
`viewEndMetrics()`, `reconnecting`/`recovered`, the error beacon, `errorDetail()`) match
these envelopes too. That verifies field shapes, not transports: the envelopes remain
derived until the harness captures 1.22.0.

The harness (`node scripts/capture-wire-fixtures.mjs --out=<scratch>`) ran all eight
scenarios against 1.22.0 (74 beacons, 9 views) and wrote nothing: three of its own
assertions still encode the pre-1.22 unload subset (`VIEW_END_FETCH_ONLY`):

- `FAIL viewEnd unload is the field subset; viewEnd ended has every field - unload has qoeScore, which the unload path does not send`
- `FAIL QoE v2 on scored heartbeat and fetch viewEnd, absent from unload subset - unload carries QoE fetch-only fields`
- `FAIL live heartbeat and live unload viewEnd carry isLive and the latency summary; no VOD beacon has latency keys - live unload has qoeScore`

Those are the player change working as released, not ingest defects; the harness
needs updating in the player repo. It also has no reconnect, `recovered`,
reconnecting-marked error or `liveEnded` scenario, so those envelopes stay derived
even after a successful capture.

## Sources read before derivation

Paths are relative to the sibling `scarlett-player` repository:

- `packages/plugins/analytics/src/index.ts`: `buildPayload()` (key order: context,
  `anonymous`, custom dimensions, event data, `beaconSeq`), `cumulativeMetrics()`
  (heartbeat and both viewEnds: counters, `reconnectCount`, `reconnectDuration`,
  nullable `avgBitrate`/`maxBitrate`, `elementSeekCount`, `qoeVersion: 2`, `dvrTime`
  only when the view resolves live), `viewEndMetrics()` (adds `startupTime`,
  `rebufferRatio`, optional `fatalErrorCategory`, `exitType`, `completionRate`, null
  on live views), `onEnded()` (`liveEnded` on a live view), `onBeforeUnload()` (the
  unload viewEnd uses the same `viewEndMetrics()`), `onError()`/`onCoreError()`
  (`errorSeverity: 'warning'` for a fatal error whose detail says `reconnecting`),
  `onReconnecting()` and `onRecovered()`.
- `packages/plugins/analytics/src/errors.ts`: `errorDetail()` whitelist
  (`httpStatus`, `mediaErrorCode`, `networkState`, `readyState`, `attempts` numbers;
  `retriesExhausted`, `reconnectExhausted`, `reconnecting`, `timedOut` booleans),
  `sourceHost()`, `classifyError()`.
- `packages/plugins/analytics/src/types.ts` and `README.md` beacon tables.

## Derivation and replay

Hand-derived `{ fixture, request: { method, path, query, headers, body } }`
envelopes. Identity strings, timestamps, host dimensions and measurement values are
synthetic sample values. The live scenario models one outage: a reconnecting-marked
error (seq 3), the first `reconnecting` (4), the long-outage repeat (6), `recovered`
(7) and a `liveEnded` viewEnd (9); seq 5 and 8 (the `rebufferStart`/`rebufferEnd`
the outage also sends) are omitted, which an ingest sees as gaps. The heartbeat
(seq 2) is modelled as delivered late, carrying an outage still open; its
`duration` is 0, what the HLS provider's `durationchange` handler sets on a live
source (`packages/plugins/hls/src/event-map.ts`; a raw `Infinity` or the initial
`NaN` would serialize to `null`). The VOD scenario is a heartbeat (seq 4, `duration`
600.25 seconds from the player's `duration` state, an earlier `pauseDuration`) and
the unload viewEnd (seq 5) through `sendBeacon` with the key in the query string.
Revised 2026-10-06: the live heartbeat's `duration` changed from a finite sample to
0 and `heartbeat.fetch.json` was added, both still derived. There are no captured-at, browser-version or harness claims for this set.

`wire-capture-key` is the existing harness's non-secret test key.

| File | Source / assertion covered | Status |
|---|---|---|
| `heartbeat.fetch.json` | `sendHeartbeat()` on VOD: `duration` in seconds, `pauseDuration` including an open pause, known bitrates | derived (recapture owed) |
| `heartbeat.fetch.live.json` | `sendHeartbeat()` + `cumulativeMetrics()`: null bitrates, `elementSeekCount`, `reconnectCount`/`reconnectDuration`, `dvrTime`, no latency summary | derived (recapture owed) |
| `error.fetch.reconnecting.json` | `onCoreError()`: fatal true, severity warning, `reconnecting`, `networkState`, `readyState`, `online`, `sourceHost` | derived (recapture owed) |
| `reconnecting.fetch.json` | `onReconnecting()`, first attempt of an outage | derived (recapture owed) |
| `reconnecting.fetch.long-outage.json` | `onReconnecting()`, the long-outage repeat with `longOutage: true` | derived (recapture owed) |
| `recovered.fetch.json` | `onRecovered()`: outage `duration` in ms, `attempt`, `elapsedMs` | derived (recapture owed) |
| `viewEnd.fetch.live-ended.json` | `onEnded()` + `viewEndMetrics()`: `liveEnded`, null `completionRate` and bitrates, `dvrTime` | derived (recapture owed) |
| `viewEnd.sendBeacon.unload.json` | `onBeforeUnload()` + `viewEndMetrics()`: the unload variant carrying the full field set | derived (recapture owed) |

## Recapture owed

After 1.22.0 is published on npm and the CDN, run the player repository's
`scripts/capture-wire-fixtures.mjs` at that version and replace these envelopes with
real transport captures, recording the manifest and assertions then.
