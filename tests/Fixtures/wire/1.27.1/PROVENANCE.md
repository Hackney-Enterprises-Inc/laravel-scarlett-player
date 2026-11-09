# Scarlett Player 1.27.1 wire fixtures

Captured from the published player producer version `1.27.1` by the player
capture harness, run in the Scarlett Player checkout at the post-release commit
(`ac5e79e`, "rebuild demo bundle for v1.27.1") on 2026-11-09. Command:
`node scripts/capture-wire-fixtures.mjs`. Every harness check passed (26 assertions),
including that every unbatched beacon carries a dispatch `sentAt` within 1s of its event
timestamp (170 beacons across 13 views).

The file set is the 1.27.0 capture's (35 captured files, plus this one), scenario for
scenario: `full-session`, `unload`, `destroy`, `error`, `clip`, `live`, `live-recovery`,
`live-ended`, `privacy`, `native`, `autoplay-blocked` and `stall-ceiling`.

1.27.1 changes no key, event or type on the wire. The analytics plugin has no diff in the
release; its version moves only because the monorepo versions in lockstep. What changes is
when some existing keys arrive:

- Native HLS (iOS Safari, in-app browsers): after an interruption, only the first failed
  reload of an outage is sent, as a **non-fatal** `error` that also carries
  `reconnecting: true` and `attempts`. Before 1.27.1 `reconnecting` only rode on a
  `fatal: true` error.
- After a native live event ends, later beacons can carry `isLive: false`.
- Fewer false `stalled: true` errors on iOS.

**Coverage gap, by design:** the harness runs headless Chromium on MSE (hls.js), which never
takes the native reload path, so the new `fatal: false` + `reconnecting: true` error is not in
this set. It is pinned by `tests/Feature/Beacons/Player1271Test.php` from a body derived from
the player source (`hls/src/event-map.ts`, analytics `onError`), **derived (recapture owed)**:
replace it only with a real iOS capture. The `viewEnd.fetch.live-ended.json` scenario runs
on hls.js, so it is not a native ENDLIST capture either.

Other gaps are those of 1.27.0: the headless `playBlocked` reports `readyState` 0, no live
`seeking` beacon, no batch envelope (the package refuses batches), no load timeout handed to
auto-reconnect, no new source after an ended view, no `rebufferRatio: null`, and the `never`
start state is not captured.

The harness manifest (`manifest.json`) records every captured file, the selected
scenarios and each assertion. One assertion in it is named "QoE v3 on heartbeat and both viewEnd
transports", which is correct (the 1.27.0 manifest had it mislabelled "QoE v2").
