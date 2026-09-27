// Regenerates tests/Fixtures/embed/share/<version>/iframes.json from the SHIPPED share plugin.
//
//   npm pack @scarlett-player/share@<version> && tar xzf scarlett-player-share-<version>.tgz
//   node build-iframes.mjs package/dist/index.js '<embedBaseUrl>' out.json
//
// It lifts buildEmbedSnippet() verbatim out of the published bundle (the function is not
// exported) and runs it outside a browser, where it resolves the base URL on its own.
// <embedBaseUrl> is ScarlettPlayer::embedUrl('paid-1') under the TestCase app key; the
// EmbedSignatureTest asserts the fixture still matches it.
import { readFileSync, writeFileSync } from 'node:fs';
const src = readFileSync(process.argv[2], 'utf8');
const fn = src.match(/^function buildEmbedSnippet[\s\S]*?^}/m)[0];
const buildEmbedSnippet = new Function(`${fn}; return buildEmbedSnippet;`)();
const base = process.argv[3];
const cases = {
  'share-built': { url: 'https://host.test/watch/paid-1', currentTime: 83.7, isLive: false },
  'off-domain-share-url': { url: 'https://evil.example.net/watch?x=1&y=2', currentTime: 12.2, isLive: false },
};
const out = {};
for (const [name, context] of Object.entries(cases)) {
  const snippet = buildEmbedSnippet(context, { embedBaseUrl: base });
  out[name] = { context, snippet, src: snippet.match(/src="([^"]+)"/)[1] };
}
writeFileSync(process.argv[4], JSON.stringify(out, null, 2));
