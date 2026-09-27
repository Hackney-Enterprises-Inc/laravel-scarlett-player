# Test fixtures

Each directory has one owning module. A change to a module's fixtures goes only under that
module's directories; anything else is a note for the maintainer.

| Directory | Owner | Contents |
|---|---|---|
| `wire/<player-version>/` | Beacons | Wire fixtures captured from the player's real transports by the player repo's harness, one JSON per `(event, transport, variant)` plus the clip pair, a session sequence and `manifest.json`; never edited, `PROVENANCE.md` a row per file (`captured`) and the harness assertions |
| `player/` | Beacons | `<player-version>/embed.umd.cjs`, the pinned `@scarlett-player/embed` bundle the browser group runs the real analytics plugin from, plus `blank.webm` (VP9) for the test page and `PROVENANCE.md` for where each file came from |
| `Beacons/` | Beacons | Helper classes for the beacon tests (PSR-4 `Hei\ScarlettPlayer\Tests\Fixtures\Beacons`): the `Beacons` payload builder and the `ProcessesBeacon` pipeline steps `DropHeartbeats` and `RedactEmail` |
| `clips/` | Clips | Clip request bodies and synthetic media for the integration group |
| `embed/` | Player and embed | `attributes/<player-version>/README.md`, the published embed README (the only source of `data-*` names) with `attributes/PROVENANCE.md`; `share/<player-version>/iframes.json`, share-built embed iframes from the shipped share plugin, with `share/PROVENANCE.md` and the generator; `share-url-payloads.tsv`, the fuzzed `shareUrl` payloads with their expected verdicts; `chapters/<player-version>/normalise.mjs`, the chapters plugin's `normaliseChapters()` lifted from its published build, with `chapters/PROVENANCE.md`; `init/`, the Node harness that runs `resources/js/init.js` against stubbed `@scarlett-player/*` modules |
| `tls/` | Browser support | The self-signed certificate `tests/Browser/support/make-cert.sh` regenerates on every run. Gitignored; never commit it |
| `Models/` | Spine | Host-model fixtures for the media contract; also analysed by PHPStan |
| `Provider/` | Scaffold | Probe route files, a probe provider, doctor probe checks and an in-memory resolver for the scaffold tests |

A derived fixture is not a finished one. Fixtures come from real transports.

Directory names differ from one another by more than case: macOS is case-insensitive and CI
is not, so `Beacons/` next to a `beacons/`, or `Player/` next to `player/`, would be one
directory locally and two in CI.
