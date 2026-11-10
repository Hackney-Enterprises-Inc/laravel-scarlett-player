<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Doctor\Checks;

use Hei\ScarlettPlayer\Doctor\Check;
use Hei\ScarlettPlayer\Doctor\CheckResult;
use Hei\ScarlettPlayer\Exceptions\InvalidEmbedConfigException;
use Hei\ScarlettPlayer\Models\Partner;
use Hei\ScarlettPlayer\Partners\PartnerGate;
use Hei\ScarlettPlayer\Partners\PartnerOrigin;
use Hei\ScarlettPlayer\Player\EmbedConfig;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Schema;

/**
 * The partner domain lock only holds in a deployment set up for it.
 *
 * With no partners (or no scarlett_partners table yet) there is nothing to check. With
 * partners: embed.always_sign must be on, or stripping scp_key and the signature from a
 * snippet leaves an unlocked page (FAIL); every stored origin list must hold only valid
 * origins and not be empty, or the page frames nobody and the partner sees a blank
 * iframe (FAIL); and embed.allowed_domains should not be empty, because then every
 * ordinary signed embed URL of the same media is still framed by '*' (WARN: the lock is
 * per deployment, not per media, and that limit is documented rather than closed).
 */
class PartnerEmbedCheck implements Check
{
    public function __construct(
        private readonly Repository $config,
        private readonly EmbedConfig $embedConfig,
    ) {}

    public function name(): string
    {
        return 'partner embeds';
    }

    public function run(): CheckResult
    {
        if (! Schema::hasTable('scarlett_partners')) {
            return CheckResult::pass('No scarlett_partners table: partner embeds are not in use');
        }

        $partners = Partner::query()->orderBy('id')->get();

        if ($partners->isEmpty()) {
            return CheckResult::pass('No partners: partner embeds are not in use');
        }

        $failures = [];

        if (! (bool) $this->config->get('scarlett-player.embed.always_sign')) {
            $failures[] = 'embed.always_sign is false: without it, removing scp_key and the signature from a partner snippet leaves an unlocked page. Set it to true';
        }

        foreach ($partners as $partner) {
            $problem = $this->originProblem($partner);

            if ($problem !== null) {
                $failures[] = "partner [{$partner->ref}] {$problem}";
            }
        }

        if ($failures !== []) {
            return CheckResult::fail(implode('; ', $failures));
        }

        try {
            $domains = $this->embedConfig->allowedDomains();
        } catch (InvalidEmbedConfigException $e) {
            return CheckResult::fail($e->getMessage());
        }

        if ($domains === []) {
            return CheckResult::warn('embed.allowed_domains is empty: ordinary signed embed URLs (share snippets, embedCode()) are still framed by *, so a site the partner lock excludes can embed the same media. Set it to your own sites; the lock is per deployment, not per media');
        }

        return CheckResult::pass(count($partners).' partner(s); embed.always_sign is on and embed.allowed_domains is set');
    }

    /** What is wrong with the stored origins of a partner, or null when they are usable. */
    private function originProblem(Partner $partner): ?string
    {
        $origins = PartnerGate::storedOrigins($partner);

        if ($origins === null) {
            return 'has a stored origins value that is not a list: its page frames nobody';
        }

        if ($origins === []) {
            return 'has no origins: its page frames nobody';
        }

        foreach ($origins as $origin) {
            if (! PartnerOrigin::isValid($origin)) {
                return 'has an invalid stored origin: '.(is_string($origin) ? '['.substr((string) preg_replace('/[^\x20-\x7e]/', '?', $origin), 0, 60).']' : get_debug_type($origin));
            }
        }

        return null;
    }
}
