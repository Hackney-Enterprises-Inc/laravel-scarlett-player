# Embed attribute reference: provenance

The embed bundle's README is the only source of `data-*` attribute names. The Blade
component in embed mode, and the builder's `toDataAttributes()`, may emit only names it
documents; the tests check every emitted name against this copy.

| File | Player | Source | State |
|---|---|---|---|
| `1.17.0/README.md` | 1.17.0 | `package/README.md` from `npm pack @scarlett-player/embed@1.17.0` (tarball sha256 `9635dd04bd9a22b13240e5c07f6f93d84e172bc20f3acc1b2eda621efcde6082`), 2026-09-27 | verbatim, sha256 `bbc0b223870a68f9abb62b5f921309a2b3e2b40a933fa181e33b03bd84a033c1` |

Re-take it from the published package when the player pin moves.
