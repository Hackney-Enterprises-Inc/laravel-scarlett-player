// node page-harness.mjs <init.js> <config.json> <scenario>
//
// Runs the real initialiser in a minimal fake page: one container rendered by the Blade
// component, its config script, and window. Scenarios:
//   inline      host bundle loaded first; the component's inline call passes
//               window.scarlettPlayerOptions, set by the host before it runs
//   pending     the component ran first and left scarlettPlayerPending; the host sets
//               its options right after importing, as an entry point does
//   no-autorun  the file is imported with a container on the page and nothing else
//   manual      the container carries data-scarlett-manual
//   remove-csrf as inline, with a host header of null for X-CSRF-TOKEN
// Prints { created, plugins } where plugins are those createPlayer() received, with the
// clips endpoint and analytics headers functions called.
import { registerHooks } from 'node:module';
import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

const stub = new URL('./stub-player.mjs', import.meta.url).href;

registerHooks({
  resolve(specifier, context, next) {
    return specifier.startsWith('@scarlett-player/')
      ? { url: stub, shortCircuit: true }
      : next(specifier, context);
  },
});

const [initPath, configPath, scenario] = process.argv.slice(2);
const configText = readFileSync(configPath, 'utf8');

const container = { dataset: { scarlettHost: 'p1-config' }, querySelector: () => null };
if (scenario === 'manual') container.dataset.scarlettManual = '';

globalThis.document = {
  querySelector: (selector) => (selector === 'meta[name="csrf-token"]' ? { getAttribute: () => 'csrf-from-meta' } : null),
  querySelectorAll: () => [container],
  getElementById: (id) => (id === 'p1-config' ? { textContent: configText } : null),
};
globalThis.window = {};

const hostOptions = {
  clips: { endpoint: { credentials: 'include', headers: () => ({ Authorization: 'Bearer host-token' }) } },
  analytics: { headers: () => ({ 'X-Tenant': 'acme' }) },
};

if (scenario === 'remove-csrf') {
  hostOptions.clips.endpoint.headers = async () => ({ 'X-CSRF-TOKEN': null, 'X-XSRF-TOKEN': 'from-cookie' });
}

if (scenario === 'inline' || scenario === 'remove-csrf') {
  window.scarlettPlayerOptions = hostOptions;
  await import(pathToFileURL(initPath).href);
  // The component's inline script.
  await window.ScarlettPlayerHost.initAll(document, window.scarlettPlayerOptions || {});
} else if (scenario === 'pending') {
  window.scarlettPlayerPending = true;
  await import(pathToFileURL(initPath).href);
  window.scarlettPlayerOptions = hostOptions;
  await new Promise((resolve) => setTimeout(resolve, 20));
} else {
  await import(pathToFileURL(initPath).href);
  await new Promise((resolve) => setTimeout(resolve, 20));
  if (scenario === 'manual') {
    await window.ScarlettPlayerHost.initAll();
  }
}

// The stub records every createPlayer() call, including the one the pending path makes
// on a timer inside the initialiser.
const created = globalThis.__scarlettCreated ?? [];
const state = container.dataset.scarlettInitialised ?? null;
const out = created[0] ?? null;

const plugins = await Promise.all((out?.plugins ?? []).map(async (plugin) => {
  const config = { ...plugin.config };
  if (config.endpoint) {
    config.endpoint = { ...config.endpoint };
    if (typeof config.endpoint.headers === 'function') config.endpoint.headers = { called: await config.endpoint.headers() };
  }
  if (typeof config.headers === 'function') config.headers = { called: await config.headers() };
  return { factory: plugin.factory, config };
}));

process.stdout.write(JSON.stringify({ state, created: created.length, plugins }));
