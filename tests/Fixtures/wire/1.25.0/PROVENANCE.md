# Scarlett Player 1.25.0 wire fixtures

Captured from the published player producer version `1.25.0` by the player
capture harness, run in the Scarlett Player checkout at 1.25.0 on 2026-10-13.
Command: `node scripts/capture-wire-fixtures.mjs`. Every harness check passed,
including the 1.25.0 check that every unbatched beacon carries a dispatch `sentAt`
within 1s of its event timestamp.

The harness manifest records all captured files and the selected scenarios. The
file set is the same as the 1.24.0 capture's.

| File set | Provenance |
| --- | --- |
| `manifest.json`, `_session.sequence.json` | Captured harness manifest and the full-session sequence. |
| `clip.create.json`, `clip.retry.json` | Captured clip requests. |
| `custom-wireCustom.fetch.json`, `custom-wirePrivacy.fetch.anonymous.json` | Captured custom beacons. |
| `error.fetch.json`, `error.fetch.reconnecting.json` | Captured error beacons. |
| `heartbeat.fetch.json`, `heartbeat.fetch.live.json`, `heartbeat.fetch.native.json` | Captured heartbeat beacons. |
| `pause.fetch.json`, `playRequest.fetch.json`, `preflight.options.json`, `qualityChange.fetch.json` | Captured pause, play request, preflight and quality change requests. |
| `rebufferEnd.fetch.json`, `rebufferStart.fetch.json`, `reconnecting.fetch.json`, `recovered.fetch.json` | Captured rebuffer and recovery beacons. |
| `seeking.fetch.json`, `videoStart.fetch.json` | Captured seeking and video start beacons. |
| `viewEnd.fetch.destroy.json`, `viewEnd.fetch.ended.json`, `viewEnd.fetch.error.json`, `viewEnd.fetch.live-ended.json`, `viewEnd.fetch.recovered.json` | Captured view end beacons. |
| `viewEnd.sendBeacon.live-unload.json`, `viewEnd.sendBeacon.unload.json` | Captured unload view end beacons sent by `sendBeacon`. |
| `viewStart.fetch.anonymous.json`, `viewStart.fetch.json`, `viewStart.fetch.live.json` | Captured view start beacons. |

New on the wire in 1.25.0: `sentAt` on every beacon, and `decodedFramesTotal` /
`droppedFramesTotal` on heartbeats and both viewEnd transports.

Coverage gaps: no live `seeking` beacon, so the `seekableStart` / `seekableEnd`
window 1.25.0 adds to live seeks is not captured; no batch envelope (the package
refuses batches); no load timeout handed to auto-reconnect (`detail.timedOut`);
and, as in 1.24.0, no new source after an ended view and no `rebufferRatio: null`.
