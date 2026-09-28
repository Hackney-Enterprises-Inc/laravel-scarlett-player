# Chapters normaliser fixture: provenance

| File | Player | Source | State |
|---|---|---|---|
| `1.19.1/normalise.mjs` | 1.19.1 | `normaliseChapters()` lifted verbatim from `@scarlett-player/chapters@1.19.1` `dist/index.js` (npm, sha256 `88d74f3f67917839c7e8b256bd3cc5e9cfaf42fdd10a414a94967e337713636d`), 2026-09-28 | shipped code, verbatim; only the export and the CLI wrapper are added |

It is the player's own rule for chapter ends: an explicit `endTime` greater than `time` is
honoured (clamped to the next chapter's start), otherwise the chapter runs to the next
chapter. Re-lift it when the player pin moves.

History: first lifted from 1.16.2 (`dist/index.js` sha256
`ee893a16a105bfca38afa4766788972d56f1545860548cf6e3a2c07eefcafa18`). Re-lifted for 1.17.0:
the function is byte-identical to the 1.16.2 one (the dist file around it changed); the
1.16.2 copy was removed. The `Chapter` interface in `@scarlett-player/core@1.17.0`
(`dist/types/state.d.ts`) is unchanged: `time`, `label`, `endTime?`, `subtitle?`,
`thumbnail?`, `metadata?`.

Re-lifted for 1.19.1: the function is byte-identical to the 1.17.0 one (`dist/index.js` sha256
`0d029ba7afb8ed1cc9e27bc2cad004c214a1365514cb5ff0684fe5bf7db97b86`); only the version in the
file's header comment changed, and the 1.17.0 copy was removed. The `Chapter` interface in
`@scarlett-player/core@1.19.1` and `CaptionSource` in `@scarlett-player/captions@1.19.1` are
unchanged from 1.17.0.
