# Pinned player bundle

The browser group runs the real analytics plugin from the published embed bundle, never
from a local build: the player repository's `packages/embed/dist` holds whatever was last
built there (1.16.3, unreleased, on 2026-09-27), which is not the version this package pins.

| Field | Value |
|---|---|
| Package | `@scarlett-player/embed` |
| Version | `1.19.1` (the `player.player_version` pin) |
| Obtained | `npm pack @scarlett-player/embed@1.19.1`, 2026-09-28 |
| Tarball | `scarlett-player-embed-1.19.1.tgz` |
| Tarball integrity (npm) | `sha512-jXmb96PkcWJmQdBZWFLA3LVSOSIkIoKjyq2MRfEVsnFZPFOs+1ZechVemGkf1OKFUPEF22JSsymqbiNh0WWjQQ==` |
| Tarball shasum (npm, sha1) | `30f93ce6edfeab34aa8224f504b6aca370bf6839` |

| File | From the tarball | sha256 |
|---|---|---|
| `1.19.1/embed.umd.cjs` | `package/dist/embed.umd.cjs` (the Full build: video, audio, analytics, playlist, media session, sharing), 783006 bytes | `c27bfa8c913d7cd519ee6d8273c7f6419ba5110f8f2cf31e64beacad571f898d` |
| `1.19.1/LICENSE` | `package/LICENSE` (MIT) | `193d8279cea28ab478f59f07dda1c308e8b63de46e17b0ae1888a6840b4ebb47` |

The UMD build is self-contained: it loads no chunk at runtime, so no other file from the
tarball is needed for an mp4 source. It reports `playerVersion` `1.19.1` on every beacon.

`embed.umd.cjs` contains an em dash (1 in 1.19.1, in a third-party string). It is kept byte for
byte, so the CI em-dash gate excludes files named `embed.umd.cjs`; nothing written for this
package may use that name.

The 1.16.2 copy (sha256 `1339eb0c...85c4c8`, npm integrity
`sha512-yM4D+06b...UsLpw==`) was removed on the repin to 1.17.0, and the 1.17.0 copy
(sha256 `32e64076...a30093`, npm integrity `sha512-9L5FBBca...Aclo4QA==`) on the repin to
1.19.1; nothing referenced either.

`blank.webm` is not from the player. It is the media the test page gives the player, so the
native provider loads without an error (an error beacon would end the view before the
unload path runs). VP9 in WebM, because headless Chromium has no H.264 and player 1.17.0 and later
refuses an mp4 it cannot play with a fatal `SOURCE_LOAD_FAILED` (the 1.16.2 era `blank.mp4`,
H.264, was removed for that reason). Generated with ffmpeg 6.1.6:

```bash
ffmpeg -f lavfi -i "testsrc=duration=2:size=160x90:rate=10" \
    -c:v libvpx-vp9 -b:v 50k -an blank.webm
```

sha256 `0fd70b500dfe39882456c07e121de14f9a57723487796f839b9ea54c1be9fed2`, 8091 bytes.

To repin: `npm pack @scarlett-player/embed@<version>`, copy `package/dist/embed.umd.cjs`
into `<version>/`, update this file, and point the browser test at the new directory.
