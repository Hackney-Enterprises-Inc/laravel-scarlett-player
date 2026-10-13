# Pinned player bundle

The browser group runs the published `@scarlett-player/embed` 1.24.0 UMD bundle, the
package's default `player.player_version`. Historically, the player repository's
`packages/embed/dist` holds whatever was last built there, which is not necessarily the
version this package pins, so the bundle is always taken from npm.

| Field | Value |
|---|---|
| Package | `@scarlett-player/embed` |
| Version | `1.24.0` |
| Obtained | `npm pack @scarlett-player/embed@1.24.0`, 2026-10-13 |
| Tarball | `scarlett-player-embed-1.24.0.tgz`, sha256 `115c20a80f3f59c0d3643d801227894db27f88dd5b2b6ac6af3ff562d4790732` |
| Tarball integrity (npm) | `sha512-aR1Xwd/QBOq8E+Ffi+76xu+v1sj86tCaQ15eA3j2KWdjOfKqu9VlHXZFL9BICYOvatd8VKN/B8HkR3PpHcKC7Q==` |
| Tarball shasum (npm, sha1) | `adbdd6fa017ed0e1563fa758c8533f289146a7c7` |

| File | From the tarball | sha256 |
|---|---|---|
| `1.24.0/embed.umd.cjs` | `package/dist/embed.umd.cjs` (the Full build: video, audio, analytics, playlist, media session, sharing), 825164 bytes | `3a998a0934867e766b04086712dab3e71202db5ad8f0f9b6b15aeb542edd5cfc` |
| `1.24.0/LICENSE` | `package/LICENSE` (MIT) | `193d8279cea28ab478f59f07dda1c308e8b63de46e17b0ae1888a6840b4ebb47` |

The UMD build is self-contained: it loads no chunk at runtime, so no other file from the
tarball is needed for an mp4 or WebM source. Its analytics module reports `playerVersion` `1.24.0`.

`embed.umd.cjs` contains two em dashes, both on one line, in the player's own playback stall
messages (the 1.22.0 copy had the same two; an earlier note here said one). It is kept byte for
byte, so the CI em-dash gate excludes files named `embed.umd.cjs`; nothing written for this
package may use that name.

The 1.16.2 copy (sha256 `1339eb0c...85c4c8`, npm integrity
`sha512-yM4D+06b...UsLpw==`) was removed on the repin to 1.17.0, and the 1.17.0 copy
(sha256 `32e64076...a30093`, npm integrity `sha512-9L5FBBca...Aclo4QA==`) on the repin to
1.19.1, and the 1.19.1 copy (sha256 `c27bfa8c...71f898d`, npm integrity
`sha512-jXmb96Pk...WWjQQ==`) on the repin to 1.22.0, and the 1.22.0 copy (sha256 `ec985a3e...5b6bd58`,
npm integrity `sha512-9C7z2qs4...`) on the repin to 1.24.0; nothing referenced any of them.

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
