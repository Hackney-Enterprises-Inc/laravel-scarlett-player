# Share-built iframe fixtures: provenance

| File | Player | Source | State |
|---|---|---|---|
| `1.19.1/iframes.json` | 1.19.1 | `buildEmbedSnippet()` lifted verbatim from `@scarlett-player/share@1.19.1` `dist/index.js` (npm, sha256 `ae3385fee44dddf8fc367fa698e0235f95eb419d70d8e5bd0fcd99b0c8650dc7`), run in Node by `build-iframes.mjs` on 2026-09-28 | derived from the published 1.19.1 dist, byte for byte; not a browser capture (the capture harness has no share scenario) |

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
