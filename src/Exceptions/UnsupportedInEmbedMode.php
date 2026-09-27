<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Exceptions;

use Hei\ScarlettPlayer\Player\FeatureMatrix;

/**
 * A feature the embed bundle cannot carry on the pinned player version was asked
 * for in embed mode. Module mode carries every feature.
 */
class UnsupportedInEmbedMode extends ScarlettPlayerException
{
    public function __construct(
        public readonly string $feature,
        public readonly string $playerVersion,
    ) {
        $label = FeatureMatrix::FEATURES[$feature]['label'] ?? $feature;

        parent::__construct(
            "Scarlett {$label} is not supported in embed mode on player {$playerVersion}. ".
            "Use module mode instead: ->mode('module'), or set scarlett-player.player.mode to 'module' ".
            'and bundle @scarlett-player/* with the published initialiser (vendor:publish --tag=scarlett-js).'
        );
    }
}
