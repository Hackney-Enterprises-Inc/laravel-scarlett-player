# Signals candidate wire captures

Captured on 2026-09-30T12:57:55.695Z from the local player signals working tree,
using `node scripts/capture-wire-fixtures.mjs --out=<this-directory>`.
The target release is 1.20.0. Package metadata and recorded playerVersion still say
1.19.3. These are real browser transports, not proof of the published 1.20.0 build.
Recapture against released 1.20.0 before claiming release-artifact validation.
Recorded bodies and manifest are unchanged. Batching was off.

| File | Provenance |
|---|---|
| `clip.create.json` | captured, signals candidate |
| `clip.retry.json` | captured, signals candidate |
| `custom-wireCustom.fetch.json` | captured, signals candidate |
| `custom-wirePrivacy.fetch.anonymous.json` | captured, signals candidate |
| `error.fetch.json` | captured, signals candidate |
| `heartbeat.fetch.json` | captured, signals candidate |
| `heartbeat.fetch.live.json` | captured, signals candidate |
| `heartbeat.fetch.native.json` | captured, signals candidate |
| `pause.fetch.json` | captured, signals candidate |
| `playRequest.fetch.json` | captured, signals candidate |
| `preflight.options.json` | captured, signals candidate |
| `qualityChange.fetch.json` | captured, signals candidate |
| `rebufferEnd.fetch.json` | captured, signals candidate |
| `rebufferStart.fetch.json` | captured, signals candidate |
| `seeking.fetch.json` | captured, signals candidate |
| `videoStart.fetch.json` | captured, signals candidate |
| `viewEnd.fetch.destroy.json` | captured, signals candidate |
| `viewEnd.fetch.ended.json` | captured, signals candidate |
| `viewEnd.fetch.error.json` | captured, signals candidate |
| `viewEnd.sendBeacon.live-unload.json` | captured, signals candidate |
| `viewEnd.sendBeacon.unload.json` | captured, signals candidate |
| `viewStart.fetch.anonymous.json` | captured, signals candidate |
| `viewStart.fetch.json` | captured, signals candidate |
| `viewStart.fetch.live.json` | captured, signals candidate |
| `_session.sequence.json` | captured, signals candidate |
| `manifest.json` | captured, signals candidate |

Source SHA-256 at capture:

| Source | SHA-256 |
|---|---|
| `packages/plugins/analytics/src/index.ts` | `8e9497a5def0db6450bf4203c520a15557b3de902b4dc9296b1dbd93bcd51283` |
| `packages/plugins/analytics/src/types.ts` | `95fffa69527a9edaac390b7f882a48cbf697f452abd0f2dcbd014665063fa697` |
| `packages/plugins/analytics/src/transport.ts` | `4a13e448054fdfe2fa8c493c7a1c3e63d88b21afa4ad16381fe4a38daf4fabc1` |
| `scripts/capture-wire-fixtures.mjs` | `288ad4e8857fa04ad13135dace061d7307cdefb210b14a37463715425e4e1d4f` |

Harness assertions (all passed):

- every required fixture was captured
- no request left 127.0.0.1
- every beacon body is JSON with playerVersion and playerName
- every beacon has a positive integer beaconSeq, starting at 1 and increasing per view in timestamp/beaconSeq order
- seeking beacons carry player/element seekSource; the core seek to 20s is player
- fetch beacons carry X-API-Key and X-Wire-Token; the unload beacon carries neither, and api_key in the query
- the preflight named x-api-key in access-control-request-headers
- viewEnd unload is the field subset; viewEnd ended has every field
- videoStart.startupTime is above zero on VOD (play request to first frame, SCAR-ANALYTICS-4)
- a fatal error sends an error beacon with errorCode, then a viewEnd with exitType error
- QoE v2 on scored heartbeat and fetch viewEnd, absent from unload subset
- anonymous viewStart has sanitized context and beforeSend drop does not consume a sequence
- hls.js emits measured segment values; native MP4 emits none
- hls.js interval beacons carry segment aggregates; native MP4 omits them
- custom dimensions sit at the top level of every beacon, types intact
- clip create and retry share clientRequestId and body (capturedAt aside), VOD fields, CSRF header
- live heartbeat and live unload viewEnd carry isLive and the latency summary; no VOD beacon has latency keys
- isLive is null on every viewStart, then false on VOD and true on live
- heartbeat watchTime and rebufferCount never decrease (sanity)
- every bus quality:change produced a qualityChange beacon matching a level
