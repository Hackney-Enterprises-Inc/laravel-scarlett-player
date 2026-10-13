# Pinned player bundle

The browser group runs the published `@scarlett-player/embed` 1.25.0 UMD bundle, the
package's default `player.player_version`. Historically, the player repository's
`packages/embed/dist` holds whatever was last built there, which is not necessarily the
version this package pins, so the bundle is always taken from npm.

| Field | Value |
|---|---|
| Package | `@scarlett-player/embed` |
| Version | `1.25.0` |
| Obtained | `npm pack @scarlett-player/embed@1.25.0`, 2026-10-13 |
| Tarball | `scarlett-player-embed-1.25.0.tgz`, sha256 `a63c13124b761a77e1705b4018f080373e2f2eb647221cb7f8dc51c3c42b64d3` |
| Tarball integrity (npm) | `sha512-SuUTNC8uxXrvAtFgBTtZemo8KoWDgC9J7yBWT/imDSMnCueIcFlngh0EYxv2j4aALfORvxJ6Uq0xwdp+M1vRQA==` |
| Tarball shasum (npm, sha1) | `e1408408e528998d03eb3e8dc2966643c66c2638` |

| File | From the tarball | sha256 |
|---|---|---|
| `1.25.0/embed.umd.cjs` | `package/dist/embed.umd.cjs` (the Full build: video, audio, analytics, playlist, media session, sharing), 831595 bytes | `7d931ea132223b4994d35a6f622aa0f4958ec5030497cd62a6cf55f84577e337` |
| `1.25.0/LICENSE` | `package/LICENSE` (MIT) | `193d8279cea28ab478f59f07dda1c308e8b63de46e17b0ae1888a6840b4ebb47` |

The UMD build is self-contained: it loads no chunk at runtime, so no other file from the
tarball is needed for an mp4 or WebM source. Its analytics module reports `playerVersion` `1.25.0`.

`embed.umd.cjs` contains two em dashes, both on one line, in the player's own playback stall
messages (the 1.22.0 copy had the same two; an earlier note here said one). It is kept byte for
byte, so the CI em-dash gate excludes files named `embed.umd.cjs`; nothing written for this
package may use that name.

The 1.16.2 copy (sha256 `1339eb0c...85c4c8`, npm integrity
`sha512-yM4D+06b...UsLpw==`) was removed on the repin to 1.17.0, and the 1.17.0 copy
(sha256 `32e64076...a30093`, npm integrity `sha512-9L5FBBca...Aclo4QA==`) on the repin to
1.19.1, and the 1.19.1 copy (sha256 `c27bfa8c...71f898d`, npm integrity
`sha512-jXmb96Pk...WWjQQ==`) on the repin to 1.22.0, and the 1.22.0 copy (sha256 `ec985a3e...5b6bd58`,
npm integrity `sha512-9C7z2qs4...`) on the repin to 1.24.0, and the 1.24.0 copy (sha256 `3a998a09...2edd5cfc`,
npm integrity `sha512-aR1Xwd/Q...`) on the repin to 1.25.0; nothing referenced any of them.

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
