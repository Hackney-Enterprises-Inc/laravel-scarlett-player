# Chapters normaliser fixture: provenance

| File | Player | Source | State |
|---|---|---|---|
| `1.27.1/normalise.mjs` | 1.27.1 | `normaliseChapters()` verified against `@scarlett-player/chapters@1.27.1` `dist/index.js` (npm, sha256 `688874ece403e8f30cb780f46ad2c62eab6b60494f276515b7e3594095f47cf5`; tarball sha256 `535979dd885eab7a485bcc639d7ea53fb15618f183165ed4d84fce2ae2a02fdb`), 2026-11-09 | shipped code, verbatim; only the export and the CLI wrapper are added |

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

Re-lifted for 1.25.0: the function is byte-identical to the 1.24.0 one (compared as text, the
1.24.0 `dist/index.js` sha256 `1742715c...169ac5`); only the version in the file's header comment
changed, and the 1.24.0 copy was removed. The `Chapter` interface in `@scarlett-player/core@1.25.0`
(`dist/types/state.d.ts`) and `CaptionSource` in `@scarlett-player/captions@1.25.0`
(`dist/index.d.ts`) are byte-identical to 1.24.0.

Re-lifted for 1.26.0: the function is byte-identical to the 1.25.0 one (compared as text, the
1.25.0 `dist/index.js` sha256 `a760eb5a...e9bde`); only the version in the file's header comment
changed, and the 1.25.0 copy was removed. The `Chapter` interface in `@scarlett-player/core@1.26.0`
(`dist/types/state.d.ts`) and `CaptionSource` in `@scarlett-player/captions@1.26.0`
(`dist/index.d.ts`) are byte-identical to 1.25.0 (checked with cmp).

Re-lifted for 1.27.0 (2026-11-02): `normaliseChapters()` is byte-identical to the 1.26.0 function
(compared as text); only the version in the file's header comment changed, and the directory was
renamed. The `Chapter` interface in `@scarlett-player/core@1.27.0` and `CaptionSource` in
`@scarlett-player/captions@1.27.0` are unchanged from 1.26.0 (compared as text).

Re-lifted for 1.27.1 (2026-11-09): `normaliseChapters()` is byte-identical to the 1.27.0 function
(compared as text; the chapters `dist/index.js` differs from 1.27.0 only in its `PKG_VERSION`
line); only the version in the file's header comment changed, and the directory was renamed.
`state.d.ts` of `@scarlett-player/core` and `dist/index.d.ts` of `@scarlett-player/captions` are
byte-identical to 1.27.0.
