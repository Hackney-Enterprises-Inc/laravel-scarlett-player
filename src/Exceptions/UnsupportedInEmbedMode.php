<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Exceptions;

use Hei\ScarlettPlayer\Player\FeatureMatrix;

/**
 * A feature the embed bundle cannot carry on the pinned player version, or in the
 * configured build (the Audio build has no captions, chapters or clips), was asked for
 * in embed mode. Module mode carries every feature.
 */
class UnsupportedInEmbedMode extends ScarlettPlayerException
{
    /**
     * @param  string|null  $build  the embed bundle file when the build, not the version,
     *                              is what cannot carry the feature (the Audio build).
     */
    public function __construct(
        public readonly string $feature,
        public readonly string $playerVersion,
        public readonly ?string $build = null,
    ) {
        $label = FeatureMatrix::FEATURES[$feature]['label'] ?? $feature;
        $where = $build === null
            ? "in embed mode on player {$playerVersion}"
            : "by the embed Audio build [{$build}] (Full and Video builds only; set player.embed_bundle to embed.js or embed.video.js)";

        parent::__construct(
            "Scarlett {$label} is not supported {$where}. ".
            "Use module mode instead: ->mode('module'), or set scarlett-player.player.mode to 'module' ".
            'and bundle @scarlett-player/* with the published initialiser (vendor:publish --tag=scarlett-js).'
        );
    }
}
