# Share-built iframe fixtures: provenance

| File | Player | Source | State |
|---|---|---|---|
| `1.22.0/iframes.json` | 1.22.0 | `buildEmbedSnippet()` lifted verbatim from `@scarlett-player/share@1.22.0` `dist/index.js` (npm, sha256 `f91d182d1693f2861cd51706aed77a9faa9e1fda57c1980b0eeeac909abc2ab7`; tarball sha256 `157daae8a00383635690150decac5349c7ce1818f3eb4a40b4d9460ceeca97f3`), run in Node by `build-iframes.mjs` on 2026-10-06 | derived from the published 1.22.0 dist, byte for byte; not a browser capture (the capture harness has no share scenario) |

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
