# Player 1.19.3 additive beacon fixtures

Status: **derived (recapture owed)**. Prepared 2026-09-29 from the player's working
tree for the planned 1.19.3 contract, not a released npm build or a browser capture.
At preparation (package 0.3.0) the package remained pinned to 1.19.1 (0.5.0 pins 1.22.0); its captured `../1.19.1/` set is unchanged.

## Sources read before derivation

Paths below are relative to the sibling `scarlett-player` repository:

- `packages/plugins/analytics/src/index.ts`: `initSession()` starts `beaconSeq` at
  zero; `startView()` sends `viewStart`; `sendBeacon()` and `sendUnloadBeacon()`
  append `++session.beaconSeq` after custom dimensions and event data, after the
  disable-in-development and sampling early returns. Neither custom dimensions nor
  event data can override the sequence.
- `packages/plugins/analytics/src/index.ts`: `onSeeking()` emits `seekCount`,
  `seekSource: 'player'` and the finite bus target as `seekTo`. The `seeking`
  false-to-true branch of `onStateChange()` emits `seekCount`, the provider's updated
  `currentTime` as `seekTo`, and `seekSource: 'element'` when it is not a bus echo.
- `packages/plugins/analytics/src/types.ts`: `BeaconPayload.beaconSeq` is required;
  `seekSource?: 'player' | 'element'`. Sequence numbers are per view, starting at 1;
  sampled-out errors consume none. Ingest exclusions can leave gaps.
- `.docs/plans/analytics-rebuffer-grace-and-element-seeks.md`, lanes B/B2 and C:
  the companion plan describing the seek paths and reserved ordering field.

The Laravel companion is `.docs/plan-v0.3.0.md`: additive known fields and a raw-log
`seq` column, without changing the view-row merge or repinning the player.

## Derivation and replay

These are hand-derived `{ fixture, request: { method, path, query, headers, body } }`
envelopes. Common environment and host-dimension values, the initial timestamp and
the first seek target are taken from the captured 1.19.1 `viewStart.fetch.json` and
`seeking.fetch.json` as sample values, not evidence of a 1.19.3 browser run. Identity
strings are deliberately synthetic. The 1.19.3 version, sequence, second timestamp,
second seek target and source labels are derived values. There are no captured-at,
received-at, browser-version or harness-success claims for this set.

The modeled view starts unclassified (`isLive: null`), then metadata classifies VOD
before two seeks while paused. `viewStart` is sequence 1, the bus seek is 2, and the
independent element seek is 3, two seconds later (outside the one-second bus-echo
window). Seek totals are 1 then 2. This is a small contract scenario, not a complete
session capture; it does not demonstrate rebuffer timing, sampling or unload delivery.

`sendBeacon()` uses POST JSON through fetch with keepalive. `baseHeaders()` attaches
the API key on HTTPS. These envelopes model that HTTPS header path, retaining only
the headers needed for replay; they invent no browser headers or optional host token.
`wire-capture-key` is the existing harness's non-secret test key, also configured by
`tests/Feature/Beacons/WireFixturesTest.php`. It is not a deployment credential.

| File | Source / assertion covered | Status |
|---|---|---|
| `viewStart.fetch.json` | `startView()` + `sendBeacon()`: sequence 1, unknown live classification, raw `seq` stored outside custom | derived (recapture owed) |
| `seeking.fetch.player.json` | `onSeeking()` + `sendBeacon()`: bus target, `seekSource: player`, sequence 2, raw fields outside custom | derived (recapture owed) |
| `seeking.fetch.element.json` | `onStateChange()` + `sendBeacon()`: element target, `seekSource: element`, sequence 3, raw fields outside custom | derived (recapture owed) |

## Recapture owed

After player 1.19.3 is published on npm and the CDN, run the player repository's
`scripts/capture-wire-fixtures.mjs` against that version and replace derived envelopes
with actual transport captures in the follow-up repin/recapture work. Record the real
manifest and assertions then; there is intentionally no fabricated capture manifest
here. Keep the 1.19.1 replay coverage for older players, which send neither new key.
