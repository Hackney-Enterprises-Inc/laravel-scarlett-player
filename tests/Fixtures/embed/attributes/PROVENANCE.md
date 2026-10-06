# Embed attribute reference: provenance

The embed bundle's README is the only source of `data-*` attribute names. The Blade
component in embed mode, and the builder's `toDataAttributes()`, may emit only names it
documents; the tests check every emitted name against this copy.

| File | Player | Source | State |
|---|---|---|---|
| `1.22.0/README.md` | 1.22.0 | `package/README.md` from `npm pack @scarlett-player/embed@1.22.0` (tarball sha256 `ebdd9981b38eff2e81bfc1eee38ba6cad54572d37956b66f90a3a0248c22bd82`), 2026-10-06 | verbatim, sha256 `e9c2b95e304e43f018055421b37f6e81ba615b12b5f36946b3996aa5987ec553` |

Re-take it from the published package when the player pin moves.

History: the 1.17.0 copy (sha256 `bbc0b223870a68f9abb62b5f921309a2b3e2b40a933fa181e33b03bd84a033c1`) was removed on the repin to 1.19.1. The two document the same set of `data-*` names.

Repin to 1.22.0 (2026-10-06): the published 1.22.0 README documents exactly the union of
the names in the 1.19.1 copy (sha256 `88209b66...bcdfc4a`) and the source-derived
`signals-candidate/README.md` (the 1.20.0 privacy and batch attributes, copied from the
player working tree on 2026-09-30, source sha256 `53cc5e9f...d4bf00`). Both were removed:
the published README supersedes the candidate, and no test needs the older set.
