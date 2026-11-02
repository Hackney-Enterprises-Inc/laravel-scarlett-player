# Scarlett Player 1.27.0 wire fixtures

Captured from the published player producer version `1.27.0` by the player
capture harness, run in the Scarlett Player checkout at the 1.27.0 release commit on
2026-11-02. Command: `node scripts/capture-wire-fixtures.mjs`. Every harness check passed
(26 assertions), including that every unbatched beacon carries a dispatch `sentAt` within 1s of
its event timestamp (168 beacons across 12 scenarios).

The file set is the 1.26.0 capture's (31 files) plus four fixtures from two new harness
scenarios (35 captured files, plus this one):

- `playBlocked.fetch.json` and `viewEnd.fetch.awaiting-tap.json`, from the `autoplay-blocked`
  scenario: a page that calls `play()` itself with no gesture in a Chromium that keeps its
  default autoplay policy. `playBlocked` carries `reason: "autoplay"`, `errorName:
  "NotAllowedError"` and `readyState`; the `viewEnd` carries `startState: "awaitingTap"`.
- `error.fetch.stalled.json` and `viewEnd.fetch.stalled.json`, from the `stall-ceiling`
  scenario: a live stream whose segments are held after playback starts, with `stallTimeoutMs`
  shortened to 15 s. The error is the terminal one, `stalled: true` together with
  `reconnectExhausted: true`; the `viewEnd` carries `startState: "played"` and `exitType: "error"`.

New on the wire in 1.27.0:

- `startState` on every `viewEnd` (`played`, `awaitingTap`, `failed` or `never`).
- The `playBlocked` event.
- `stalled` on the terminal error of the stall ceiling.
- `qoeVersion` 3: a view that never showed a first frame scores 0 (it asked to play and got
  none) or null (it never asked, or is waiting for a tap). `viewEnd.fetch.awaiting-tap.json`
  has `qoeScore: null` and `viewEnd.fetch.stalled.json` has `qoeScore: 0` (an error exit).

Coverage gaps: the headless `playBlocked` reports `readyState` 0 (a physical iPhone reported 1);
no live `seeking` beacon, no batch envelope (the package refuses batches), no load timeout
handed to auto-reconnect (`detail.timedOut`), no new source after an ended view and no
`rebufferRatio: null`. The `never` start state is not captured: no scenario ends a view that asked
to play and showed no frame without an error.

The harness manifest (`manifest.json`) records every captured file, the selected
scenarios and each assertion.
One assertion in it is named "QoE v2 on heartbeat and both viewEnd transports". That is a
mislabel: the check asserts `qoeVersion` 3 (the harness now names it "QoE v3 ..."). The manifest
is left as the 1.27.0 run printed it and is corrected by the next recapture.
