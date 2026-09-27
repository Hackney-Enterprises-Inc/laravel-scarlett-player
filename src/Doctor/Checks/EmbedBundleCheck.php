<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Doctor\Checks;

use Hei\ScarlettPlayer\Doctor\Check;
use Hei\ScarlettPlayer\Doctor\CheckResult;
use Hei\ScarlettPlayer\Exceptions\InvalidPlayerConfigException;
use Hei\ScarlettPlayer\Player\PlayerConfigBuilder;
use Illuminate\Contracts\Config\Repository;

/**
 * The embed bundle location resolves. Fails when player.mode is embed (every
 * component needs it); warns when only the embed page (routes.embed) would need it,
 * since a host may never use that page; passes otherwise.
 */
class EmbedBundleCheck implements Check
{
    public function __construct(
        private readonly Repository $config,
    ) {}

    public function name(): string
    {
        return 'embed bundle';
    }

    public function run(): CheckResult
    {
        $embedMode = $this->config->get('scarlett-player.player.mode') === 'embed';
        $embedPage = (bool) $this->config->get('scarlett-player.routes.embed');

        if (! $embedMode && ! $embedPage) {
            return CheckResult::pass('embed page off and player.mode is module; no bundle needed');
        }

        try {
            $url = PlayerConfigBuilder::renderEmbedBundle($this->config);
        } catch (InvalidPlayerConfigException) {
            return $embedMode
                ? CheckResult::fail('player.mode is embed but player.cdn_url is empty: set SCARLETT_CDN_URL')
                : CheckResult::warn('the embed page (routes.embed) cannot load the player until SCARLETT_CDN_URL is set; turn routes.embed off if unused');
        }

        return CheckResult::pass("embed bundle at {$url}");
    }
}
