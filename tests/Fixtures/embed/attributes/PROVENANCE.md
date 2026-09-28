# Embed attribute reference: provenance

The embed bundle's README is the only source of `data-*` attribute names. The Blade
component in embed mode, and the builder's `toDataAttributes()`, may emit only names it
documents; the tests check every emitted name against this copy.

| File | Player | Source | State |
|---|---|---|---|
| `1.19.1/README.md` | 1.19.1 | `package/README.md` from `npm pack @scarlett-player/embed@1.19.1` (tarball sha256 `5bdd8415bed0c78ef03566096f6de468259ec01b0a1310d0f30d551069610d83`), 2026-09-28 | verbatim, sha256 `88209b66a69f46178ae7440db0f62911cd70ab056b34da4f292402c85bcdfc4a` |

Re-take it from the published package when the player pin moves.

History: the 1.17.0 copy (sha256 `bbc0b223870a68f9abb62b5f921309a2b3e2b40a933fa181e33b03bd84a033c1`) was removed on the repin to 1.19.1. The two document the same set of `data-*` names.
