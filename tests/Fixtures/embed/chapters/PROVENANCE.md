# Chapters normaliser fixture: provenance

| File | Player | Source | State |
|---|---|---|---|
| `1.24.0/normalise.mjs` | 1.24.0 | `normaliseChapters()` lifted verbatim from `@scarlett-player/chapters@1.24.0` `dist/index.js` (npm, sha256 `1742715cf7789f245f8fd6d8f14402ee6a78659c5bdbcaa758e673a885169ac5`; tarball sha256 `e61a02e3024e3d6dc6d8b65304809080a5080e2c7ca4e38139a9c241bd7b9ab4`), 2026-10-13 | shipped code, verbatim; only the export and the CLI wrapper are added |

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

Re-lifted for 1.22.0: the function is byte-identical to the 1.19.1 one (compared as text,
the 1.19.1 copy with `dist/index.js` sha256 `88d74f3f...713636d`); only the version in the
file's header comment changed, and the 1.19.1 copy was removed. The `Chapter` interface in
`@scarlett-player/core@1.22.0` (`dist/types/state.d.ts`: `time`, `label`, `endTime?`,
`subtitle?`, `thumbnail?`, `metadata?`) and `CaptionSource` in
`@scarlett-player/captions@1.22.0` (`language`, `label`, `src`, `kind?`, `default?`) are
unchanged.

Re-lifted for 1.24.0: the function is byte-identical to the 1.22.0 one (compared as text, the
1.22.0 `dist/index.js` sha256 `e2c81b61...15f0`); only the version in the file's header comment
changed, and the 1.22.0 copy was removed. The `Chapter` interface in `@scarlett-player/core@1.24.0`
(`dist/types/state.d.ts`) and `CaptionSource` in `@scarlett-player/captions@1.24.0`
(`dist/index.d.ts`) are byte-identical to 1.22.0.
