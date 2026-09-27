// Stands in for every @scarlett-player/* package the initialiser imports. Each factory
// records its name and config instead of building a plugin.
const factory = (name) => (config = {}) => ({ factory: name, config });

export const createHLSPlugin = factory('hls');
export const createNativePlugin = factory('native');
export const uiPlugin = factory('ui');
export const createAnalyticsPlugin = factory('analytics');
export const createClipsPlugin = factory('clips');
export const createChaptersPlugin = factory('chapters');
export const createCaptionsPlugin = factory('captions');
export const createSharePlugin = factory('share');
export const accentTextTone = (color) => `tone(${color})`;
export const createPlayer = async (options) => {
  (globalThis.__scarlettCreated ??= []).push(options);

  return { createdWith: options };
};
