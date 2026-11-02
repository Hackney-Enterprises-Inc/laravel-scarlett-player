# Scarlett Player 1.26.0 wire fixtures

Captured from the published player producer version `1.26.0` by the player
capture harness, run in the Scarlett Player checkout at 1.26.0 on 2026-11-01.
Command: `node scripts/capture-wire-fixtures.mjs`. Every harness check passed,
including that every unbatched beacon carries a dispatch `sentAt` within 1s of
its event timestamp (105 beacons across 11 views).

The file set is the same as the 1.25.0 capture's (31 files).

New on the wire in 1.26.0: `queuedForMs` on `trackEvent()` calls made before the
analytics plugin initialised. None is captured here (no scenario queues an event before init; no fixture
carries the key). The ingest needs no change for it, because it is a custom dimension (a number the
package does not know, stored in the view's `custom` map and the raw log).

Coverage gaps are those of the 1.25.0 capture: no live `seeking` beacon, no batch
envelope (the package refuses batches), no load timeout handed to auto-reconnect
(`detail.timedOut`), no new source after an ended view and no `rebufferRatio: null`.

The harness manifest (`manifest.json`) records every captured file, the selected
scenarios and each assertion.
