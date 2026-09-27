<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Doctor\Checks;

use Hei\ScarlettPlayer\Doctor\Check;
use Hei\ScarlettPlayer\Doctor\CheckResult;
use Hei\ScarlettPlayer\Exceptions\InvalidEmbedConfigException;
use Hei\ScarlettPlayer\Player\EmbedConfig;

/**
 * embed.allowed_domains is all host names. One bad entry is otherwise a 500 on every
 * embed page request, found by a viewer instead of here.
 */
class EmbedDomainsCheck implements Check
{
    public function __construct(
        private readonly EmbedConfig $embedConfig,
    ) {}

    public function name(): string
    {
        return 'embed domains';
    }

    public function run(): CheckResult
    {
        try {
            $domains = $this->embedConfig->allowedDomains();
        } catch (InvalidEmbedConfigException $e) {
            return CheckResult::fail($e->getMessage());
        }

        return $domains === []
            ? CheckResult::pass('embed.allowed_domains is empty: any site may frame the embed page')
            : CheckResult::pass('embed.allowed_domains: '.implode(', ', $domains));
    }
}
