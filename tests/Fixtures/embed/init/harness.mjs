// node harness.mjs <init.js> <config.json>
//
// Loads the real initialiser with @scarlett-player/* resolved to stub-player.mjs, runs
// initScarlettPlayer() on the config, and prints what createPlayer() received as JSON.
// Function values are called where the initialiser hands the player a callback
// (the clips endpoint headers), so the test sees what they return.
import { registerHooks } from 'node:module';
import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

const stub = new URL('./stub-player.mjs', import.meta.url).href;

registerHooks({
  resolve(specifier, context, next) {
    if (specifier.startsWith('@scarlett-player/')) {
      return { url: stub, shortCircuit: true };
    }

    return next(specifier, context);
  },
});

globalThis.document = {
  querySelector: (selector) => (selector === 'meta[name="csrf-token"]'
    ? { getAttribute: () => 'csrf-from-meta' }
    : null),
};

const [initPath, configPath] = process.argv.slice(2);
const init = await import(pathToFileURL(initPath).href);
const config = JSON.parse(readFileSync(configPath, 'utf8'));

const container = { querySelector: () => null };

try {
  const player = await init.initScarlettPlayer(container, config);
  const options = player.createdWith;

  for (const plugin of options.plugins) {
    const headers = plugin.config?.endpoint?.headers;
    if (typeof headers === 'function') plugin.config.endpoint.headers = { called: await headers() };
  }

  process.stdout.write(JSON.stringify({
    ok: true,
    version: init.SCARLETT_CONFIG_VERSION,
    options: { ...options, container: options.container === container ? 'container' : 'other' },
  }));
} catch (error) {
  process.stdout.write(JSON.stringify({ ok: false, error: String(error.message) }));
}
