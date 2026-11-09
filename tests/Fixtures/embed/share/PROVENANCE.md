# Share-built iframe fixtures: provenance

| File | Player | Source | State |
|---|---|---|---|
| `1.27.1/iframes.json` | 1.27.1 | `buildEmbedSnippet()` verified against `@scarlett-player/share@1.27.1` `dist/index.js` (npm, sha256 `3223ada35cdb0b43e2fe9ec5672f8e1e0fb4e255ce32a9c16e02211f9c0b3df3`; tarball sha256 `198aaf7bd274249aca59ee06512832462feb648addd9300f50001161591a19ca`), 2026-11-09 | derived from the published 1.27.1 dist, byte for byte; not a browser capture (the capture harness has no share scenario) |

The shipped function is the transport here: it is the code that appends `startTime`
(floored, VOD only) and `shareUrl` with `URL.searchParams` to the embed base URL. What a
browser adds on top is only `window.location.href` as the base for a relative
`embedBaseUrl`, and `embedUrl()` is always absolute.

The fixture's `embedBaseUrl` is `embedUrl('paid-1')` with `embed.signed_ttl` null (no
expiry), so its signature is deterministic under the TestCase app key; the test pins that
setting. Expiring signatures are covered by `EmbedPageTest`.

History: first built from `@scarlett-player/share@1.16.2` (`dist/index.js` sha256
`f9855fb1b98955c0c1c92962e12bc1f5266c237585499f965dbff0de5db92056`). Rebuilt for 1.17.0:
`buildEmbedSnippet()` is byte-identical to the 1.16.2 function and the generated iframes are
identical case for case; the 1.16.2 copy was removed.

Rebuilt for 1.19.1: `buildEmbedSnippet()` is byte-identical to the 1.17.0 function (`dist/index.js`
sha256 `a6c714b6d2698f5e5261986a0c5b05bc69688b8f141a114282808200b22d11b6`) and the generated
iframes are identical case for case; only `_about` and `playerVersion` in the file changed,
and the 1.17.0 copy was removed.

Rebuilt for 1.22.0: `buildEmbedSnippet()` was first compared as text with the 1.19.1
function (byte-identical) before `build-iframes.mjs` ran it, and the generated iframes are
identical case for case; only `_about` and `playerVersion` in the file changed, and the
1.19.1 copy (built from `dist/index.js` sha256 `ae3385fe...0650dc7`) was removed.

Rebuilt for 1.24.0: `buildEmbedSnippet()` is byte-identical to the 1.22.0 function (`dist/index.js`
sha256 `f91d182d...c2ab7`, compared as text), and `build-iframes.mjs` regenerated the two cases
identical to the 1.22.0 ones; only `_about` and `playerVersion` in the file changed, and the 1.22.0
copy was removed.

Rebuilt for 1.25.0: `buildEmbedSnippet()` is byte-identical to the 1.24.0 function (`dist/index.js`
sha256 `6b429af3...fe296d8`, compared as text), and `build-iframes.mjs` regenerated the two cases
identical to the 1.24.0 ones; only `_about` and `playerVersion` in the file changed, and the 1.24.0
copy was removed.

Rebuilt for 1.26.0: `buildEmbedSnippet()` is byte-identical to the 1.25.0 function (`dist/index.js`
sha256 `db222fee...117f1`, compared as text), and `build-iframes.mjs` regenerated the two cases
identical to the 1.25.0 ones; only `_about` and `playerVersion` in the file changed, and the 1.25.0
copy was removed.

Rebuilt for 1.27.0: `buildEmbedSnippet()` is byte-identical to the 1.26.0 function (`dist/index.js`
sha256 `e03d200c...6f868`, compared as text), and `build-iframes.mjs` regenerated the two cases
identical to the 1.26.0 ones; only `_about` and `playerVersion` in the file changed, and the 1.26.0
copy was removed.

Rebuilt for 1.27.1 (2026-11-09): `buildEmbedSnippet()` is byte-identical to the 1.27.0 function
(compared as text; the share `dist/index.js` differs from 1.27.0 only in its `PKG_VERSION` line). The two cases are identical to the 1.27.0 ones; only `_about` and `playerVersion`
in the file changed (edited by hand, the generated iframes being identical), and the 1.27.0 copy was removed.
