# Scarlett Player 1.24.0 derived wire fixtures

Each row is `derived (recapture owed)`. Source files are real captures listed in
`tests/Fixtures/wire/1.24.0/PROVENANCE.md`.

| File | Provenance |
| --- | --- |
| `startup-failure.viewStart.json` | derived (recapture owed) from `1.24.0/viewStart.fetch.json`; replaced viewId, sessionId and viewerId with the startup-failure identity, advanced timestamp by 1000000 ms, and set scenario metadata. Kept the captured field set and `isLive: null`. |
| `startup-failure.error.json` | derived (recapture owed) from `1.24.0/error.fetch.json`; replaced viewId, sessionId and viewerId with the startup-failure identity, advanced timestamp by 1000000 ms, set `beaconSeq` 2 (it follows the viewStart directly; the captured error was seq 8 after a played view), and set scenario metadata. Kept the captured fatal error field set. |
| `startup-failure.viewEnd.json` | derived (recapture owed) from `1.24.0/viewEnd.fetch.error.json`; replaced viewId, sessionId and viewerId with the startup-failure identity, advanced timestamp by 1000000 ms, set `beaconSeq` 3, and set scenario metadata and `exitType: error`. Kept the captured viewEnd field set, which already reports `startupTime: null`, `playTime: 0` and `completionRate: null`. |
| `trailing-coalesced-seeking.sendBeacon.json` | derived (recapture owed) from `1.24.0/seeking.fetch.json`; replaced view identity, timestamp, beaconSeq and seekCount, and set `seekSource: player` with `seekTo` 20 from the player seek at beaconSeq 9 of `1.24.0/_session.sequence.json` (only player seeks coalesce; the captured `seeking.fetch.json` is an element seek), to model a trailing coalesced seek. Copied query and headers from `1.24.0/viewEnd.sendBeacon.unload.json`, which supplies `api_key` in query and no API key header. Retained the seeking body field set. |
| `viewEnd.fetch.null-rebuffer.json` | derived (recapture owed) from `1.24.0/viewEnd.fetch.ended.json`; advanced the timestamp, changed `rebufferRatio` to null, and kept `beaconSeq` 27, the captured view identity, `gaugeScale: percent` and all other body fields. |
