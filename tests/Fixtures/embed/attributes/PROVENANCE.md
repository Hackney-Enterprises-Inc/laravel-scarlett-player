# Embed attribute reference: provenance

The embed bundle's README is the only source of `data-*` attribute names. The Blade
component in embed mode, and the builder's `toDataAttributes()`, may emit only names it
documents; the tests check every emitted name against this copy.

| File | Player | Source | State |
|---|---|---|---|
| `1.24.0/README.md` | 1.24.0 | `package/README.md` from `npm pack @scarlett-player/embed@1.24.0` (tarball sha256 `115c20a80f3f59c0d3643d801227894db27f88dd5b2b6ac6af3ff562d4790732`), 2026-10-13 | verbatim, sha256 `89db9921694610c862c5a096c02fcc957092ead89462d430e2fa33071920d121` |

Re-take it from the published package when the player pin moves.

History: the 1.17.0 copy (sha256 `bbc0b223870a68f9abb62b5f921309a2b3e2b40a933fa181e33b03bd84a033c1`) was removed on the repin to 1.19.1. The two document the same set of `data-*` names.

Repin to 1.22.0 (2026-10-06): the published 1.22.0 README documents exactly the union of
the names in the 1.19.1 copy (sha256 `88209b66...bcdfc4a`) and the source-derived
`signals-candidate/README.md` (the 1.20.0 privacy and batch attributes, copied from the
player working tree on 2026-09-30, source sha256 `53cc5e9f...d4bf00`). Both were removed:
the published README supersedes the candidate, and no test needs the older set.

Repin to 1.24.0 (2026-10-13): the published 1.24.0 README has the same set of 45 `data-*` names as
the 1.22.0 copy (sha256 `e9c2b95e...ec553`, removed). Its differences are the `data-keyboard`
description, a `keyboard` line in the iframe URL parameters, and the keyboard shortcuts section
(video only, `?`, `C` and `0`-`9` bullets added). None of these changes an attribute name the
builder writes.
