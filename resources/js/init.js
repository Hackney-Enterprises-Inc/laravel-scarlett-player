/**
 * Scarlett Player host initialiser for hei/laravel-scarlett-player.
 *
 * Consumes the host config PlayerConfigBuilder::toArray() emits (scarlettConfigVersion 1),
 * builds the Plugin[] that core's createPlayer() needs, and creates the player. PHP cannot
 * produce plugins (they carry init() and destroy()), which is why this file exists.
 *
 * Publish it with `php artisan vendor:publish --tag=scarlett-js` and import it from your
 * Vite entry point. The host must have these packages installed, at the version named in
 * scarlett-player.player.player_version:
 *
 *   npm install @scarlett-player/core @scarlett-player/hls @scarlett-player/native \
 *     @scarlett-player/ui @scarlett-player/analytics @scarlett-player/clips \
 *     @scarlett-player/chapters @scarlett-player/captions @scarlett-player/share
 *
 * Every import is static, so all of them must be installed even if a page uses only
 * some features; a bundler resolves every specifier at build time.
 *
 * The Blade component (module mode) writes the config into a script element and calls
 * window.ScarlettPlayerHost.initAll(document, window.scarlettPlayerOptions). Set
 * window.scarlettPlayerOptions in your entry point for page-wide options (the Sanctum
 * SPA and token recipes, analytics headers). Importing this file starts nothing on its
 * own; see the note at the bottom.
 *
 * Set window.scarlettPlayerOptions SYNCHRONOUSLY in the entry module, before any await:
 * a top-level await yields, and the component's inline call (or the deferred initAll()
 * this file schedules) can then run while the options are still undefined.
 */
import { createPlayer } from '@scarlett-player/core';
import { createHLSPlugin } from '@scarlett-player/hls';
import { createNativePlugin } from '@scarlett-player/native';
import { uiPlugin, accentTextTone } from '@scarlett-player/ui';
import { createAnalyticsPlugin } from '@scarlett-player/analytics';
import { createClipsPlugin } from '@scarlett-player/clips';
import { createChaptersPlugin } from '@scarlett-player/chapters';
import { createCaptionsPlugin } from '@scarlett-player/captions';
import { createSharePlugin } from '@scarlett-player/share';

/** The host config schema this initialiser understands. */
export const SCARLETT_CONFIG_VERSION = 1;

/**
 * The video control bar when a clip or share control has to be in the layout, which is
 * the only case the ui plugin needs a layout at all. Mirrors the embed bundle's order.
 */
const BASE_LAYOUT = [
  'play',
  'skip-backward',
  'skip-forward',
  'volume',
  'time',
  'live-indicator',
  'bandwidth-indicator',
  'spacer',
  'settings',
  'captions',
  'chromecast',
  'airplay',
  'pip',
  'fullscreen',
];

/**
 * The CSRF token from the page's `<meta name="csrf-token">`, read per request so a
 * rotated token is picked up.
 */
function csrfHeaders() {
  const meta = document.querySelector('meta[name="csrf-token"]');
  const token = meta ? meta.getAttribute('content') : null;

  return token ? { 'X-CSRF-TOKEN': token } : {};
}

/** Headers from a static object or a (possibly async) function. */
async function resolveHeaders(headers) {
  return (typeof headers === 'function' ? await headers() : headers) || {};
}

/** Drop the keys a host set to null, so it can remove a default header. */
function withoutNulls(headers) {
  return Object.fromEntries(Object.entries(headers).filter(([, value]) => value !== null && value !== undefined));
}

function controlLayout(config) {
  const extra = [];

  if (config.clips) extra.push('clip');
  if (config.share) extra.push('share');

  if (extra.length === 0) return undefined;

  const layout = [...BASE_LAYOUT];
  layout.splice(layout.indexOf('spacer') + 1, 0, ...extra);

  return layout;
}

function seekWhenReady(container, seconds) {
  const video = container.querySelector('video');

  if (!video) return;

  const seek = () => {
    video.currentTime = seconds;
  };

  if (video.readyState >= 1) {
    seek();
  } else {
    video.addEventListener('loadedmetadata', seek, { once: true });
  }
}

/**
 * Build the plugin list for one host config.
 *
 * @param {object} config  PlayerConfigBuilder::toArray()
 * @param {object} [options]
 * @param {object} [options.analytics]  merged into the analytics plugin config, e.g. { headers }
 * @param {object} [options.clips]  merged into the clips plugin config; options.clips.endpoint is
 *   merged into the endpoint (credentials, headers) for the Sanctum SPA and token recipes
 * @param {Array} [options.plugins]  extra plugins, appended before the UI plugin
 * @returns {Array} Plugin[]
 */
export function buildPlugins(config, options = {}) {
  const plugins = [createHLSPlugin(), createNativePlugin()];

  if (config.captions) {
    plugins.push(createCaptionsPlugin({ sources: config.captions.sources }));
  }

  if (config.chapters) {
    plugins.push(createChaptersPlugin(config.chapters));
  }

  if (config.analytics) {
    const analytics = {
      beaconUrl: config.analytics.beaconUrl,
      videoId: config.analytics.videoId,
      isLive: config.analytics.isLive,
      ...(config.analytics.apiKey ? { apiKey: config.analytics.apiKey } : {}),
      ...(config.analytics.videoTitle ? { videoTitle: config.analytics.videoTitle } : {}),
      // Milliseconds, from player.heartbeat_interval; a page-wide option below still wins.
      ...(config.analytics.heartbeatInterval ? { heartbeatInterval: config.analytics.heartbeatInterval } : {}),
      ...(typeof config.analytics.anonymous === 'boolean' ? { anonymous: config.analytics.anonymous } : {}),
      ...(typeof config.analytics.respectDoNotTrack === 'boolean' ? { respectDoNotTrack: config.analytics.respectDoNotTrack } : {}),
      ...(options.analytics || {}),
    };
    plugins.push(createAnalyticsPlugin(analytics));
  }

  if (config.clips) {
    const { endpoint: endpointOverrides = {}, ...clipOverrides } = options.clips || {};
    const { headers: hostHeaders, ...otherOverrides } = endpointOverrides;
    const endpoint = {
      url: config.clips.endpoint.url,
      ...otherOverrides,
    };

    // Host headers (a Bearer token, say) are added to the CSRF header, never swapped
    // for it: the Sanctum SPA recipe needs both.
    // A host value of null removes that header, including X-CSRF-TOKEN.
    if (config.clips.endpoint.csrf) {
      endpoint.headers = hostHeaders
        ? async () => withoutNulls({ ...csrfHeaders(), ...(await resolveHeaders(hostHeaders)) })
        : csrfHeaders;
    } else if (hostHeaders) {
      endpoint.headers = async () => withoutNulls(await resolveHeaders(hostHeaders));
    }
    plugins.push(createClipsPlugin({
      mediaId: config.clips.mediaId,
      ...(config.clips.minDuration != null ? { minDuration: config.clips.minDuration } : {}),
      ...(config.clips.maxDuration != null ? { maxDuration: config.clips.maxDuration } : {}),
      ...clipOverrides,
      endpoint,
    }));
  }

  if (config.share) {
    plugins.push(createSharePlugin({
      url: config.share.url,
      ...(config.share.title ? { title: config.share.title } : {}),
      ...(config.share.embedBaseUrl ? { embedBaseUrl: config.share.embedBaseUrl } : {}),
    }));
  }

  plugins.push(...(options.plugins || []));

  if (config.playback.controls !== false) {
    const theme = {};
    if (config.brand.color) theme.accentColor = config.brand.color;
    if (config.brand.textColor) {
      theme.accentTextColor = config.brand.textColor;
    } else if (config.brand.color) {
      theme.accentTextColor = accentTextTone(config.brand.color);
    }

    const ui = {};
    if (Object.keys(theme).length > 0) ui.theme = theme;
    const controls = controlLayout(config);
    if (controls) ui.controls = controls;

    plugins.push(uiPlugin(ui));
  }

  return plugins;
}

/**
 * Create a player in the container from a host config.
 *
 * @param {HTMLElement|string} container
 * @param {object} config  PlayerConfigBuilder::toArray()
 * @param {object} [options]  see buildPlugins()
 * @returns {Promise<object>} the ScarlettPlayer instance
 */
export async function initScarlettPlayer(container, config, options = {}) {
  if (!config || config.scarlettConfigVersion !== SCARLETT_CONFIG_VERSION) {
    throw new Error(
      `[scarlett] host config version ${config && config.scarlettConfigVersion} is not supported; ` +
      `this initialiser reads version ${SCARLETT_CONFIG_VERSION}. Republish it with --tag=scarlett-js.`,
    );
  }

  if (config.mode !== 'module') {
    throw new Error('[scarlett] initScarlettPlayer() takes a module-mode config; embed mode uses the embed bundle.');
  }

  const element = typeof container === 'string' ? document.querySelector(container) : container;

  if (!element) {
    throw new Error(`[scarlett] container ${container} was not found.`);
  }

  const player = await createPlayer({
    container: element,
    src: config.source.src,
    ...(config.poster ? { poster: config.poster } : {}),
    autoplay: config.playback.autoplay,
    muted: config.playback.muted,
    loop: config.playback.loop,
    plugins: buildPlugins(config, options),
  });

  if (config.playback.startTime && !config.source.isLive) {
    seekWhenReady(element, config.playback.startTime);
  }

  return player;
}

/**
 * The page-wide defaults for initAll(): window.scarlettPlayerOptions, when the host set it.
 * Same shape as buildPlugins() options: { clips: { endpoint: { credentials, headers } },
 * analytics: { headers }, plugins }.
 */
function pageOptions() {
  return (typeof window !== 'undefined' && window.scarlettPlayerOptions) || {};
}

/**
 * Initialise every container the Blade component rendered that is not yet running.
 *
 * A container with data-scarlett-manual is skipped: the host calls initScarlettPlayer()
 * on it itself, with whatever options it needs.
 *
 * @param {ParentNode} [root]
 * @param {object} [options]  see buildPlugins(); defaults to window.scarlettPlayerOptions
 * @returns {Promise<Array>} the players created by this call
 */
export async function initAll(root = document, options = pageOptions()) {
  const created = [];

  for (const element of root.querySelectorAll('[data-scarlett-host]')) {
    if (element.dataset.scarlettInitialised || 'scarlettManual' in element.dataset) continue;
    element.dataset.scarlettInitialised = 'pending';

    const source = document.getElementById(element.dataset.scarlettHost);

    try {
      const config = JSON.parse(source ? source.textContent : 'null');
      created.push(await initScarlettPlayer(element, config, options));
      element.dataset.scarlettInitialised = 'true';
    } catch (error) {
      element.dataset.scarlettInitialised = 'failed';
      console.error('[scarlett] failed to initialise a player:', error);
    }
  }

  return created;
}

/*
 * Importing this file starts nothing by itself: a host's entry point usually sets
 * window.scarlettPlayerOptions AFTER its imports run, and a player started at import
 * would never see them. The Blade component's inline script calls initAll() when the
 * host is already loaded, or leaves window.scarlettPlayerPending for this file, which
 * then starts the players on the next task, once the importing bundle has finished.
 */
if (typeof window !== 'undefined') {
  window.ScarlettPlayerHost = { init: initScarlettPlayer, initAll, buildPlugins };

  if (window.scarlettPlayerPending) {
    window.scarlettPlayerPending = false;
    setTimeout(() => initAll(), 0);
  }
}
