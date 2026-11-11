<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Partners;

use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Models\Partner;
use Hei\ScarlettPlayer\Player\EmbedConfig;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Support\Carbon;

/**
 * The partner checks of the embed page, run whenever the request carries scp_key.
 *
 * Every refusal throws the framework's InvalidSignatureException (403, the same body for
 * every cause), so a caller cannot tell a revoked partner from a forged signature and no
 * branch reaches the controller. In order:
 *
 *  1. a valid RELATIVE signature, ignoring embed.unsigned_params. A partner URL is signed
 *     relative, so the absolute check is never consulted and a valid absolute signature
 *     can never stand in for it; this holds for unprotected media with always_sign off
 *     too (a new branch: nothing else refuses a missing signature there);
 *  2. scp_key is one string of 32 lowercase hex characters;
 *  3. a row with that key_id exists (looked up by key_id, never by ref);
 *  4. it is not revoked;
 *  5. its expires_at is null or in the future (a partner expiring exactly now is expired);
 *  6. protected media needs the URL to carry an `expires` no further than MAX_EXPIRES away
 *     (assertExpiry(), after the media resolved; it also catches media that became
 *     protected after the URL was issued).
 */
class PartnerGate
{
    public function __construct(
        private readonly EmbedConfig $embedConfig,
    ) {}

    /**
     * Checks 1 to 5. The partner row is returned only when every check passed.
     *
     * @throws InvalidSignatureException on any failure.
     */
    public function partner(Request $request): Partner
    {
        if (! $request->hasValidRelativeSignatureWhileIgnoring($this->embedConfig->unsignedParams())) {
            throw new InvalidSignatureException;
        }

        $key = $request->query->all()[PartnerParams::KEY] ?? null;

        // Defence in depth: a repeated scp_key reads as the last one here, but the signature
        // above already covers every raw pair, so a repeat normally fails it first.
        if (! PartnerParams::isKeyId($key) || self::keyCount($request) !== 1) {
            throw new InvalidSignatureException;
        }

        $partner = Partner::query()->where('key_id', $key)->first();

        if ($partner === null || $partner->isRevoked() || $partner->isExpired()) {
            throw new InvalidSignatureException;
        }

        return $partner;
    }

    /**
     * Check 6: protected media needs an expiry on the URL, within MAX_EXPIRES.
     *
     * @throws InvalidSignatureException when the media is protected and the URL has none
     *                                   or one too far away.
     */
    public function assertExpiry(Request $request, MediaSource $media): void
    {
        if (! $media->isProtected) {
            return;
        }

        $expires = $request->query('expires');

        if (! is_string($expires) || preg_match('/^[0-9]{1,12}$/D', $expires) !== 1
            || (int) $expires > Carbon::now()->getTimestamp() + PartnerParams::MAX_EXPIRES) {
            throw new InvalidSignatureException;
        }
    }

    /**
     * What the page needs from a verified partner. The origins are decoded from the raw
     * stored value, not through the model cast, so a stored value that is not a JSON list
     * (a string, a number, an object, broken JSON) is an empty list, which the page turns
     * into frame-ancestors 'none'. Members are not trusted: the header builder checks
     * each one again.
     */
    public function context(Partner $partner): PartnerContext
    {
        return new PartnerContext($partner->ref, $partner->key_id, self::storedOrigins($partner) ?? []);
    }

    /**
     * The origins list as stored, or null when the stored value is not a JSON list at the
     * top level (malformed). Members are returned as stored, whatever their type.
     *
     * @return list<mixed>|null
     */
    public static function storedOrigins(Partner $partner): ?array
    {
        $raw = $partner->getRawOriginal('origins');

        if (! is_string($raw)) {
            return null;
        }

        // Not associative: a JSON object decodes to stdClass and is refused, where the
        // associative form turns {"0":"https://x"} into a PHP list.
        $decoded = json_decode($raw, false);

        return is_array($decoded) ? array_values($decoded) : null;
    }

    /**
     * How many scp_key pairs the raw query string holds, counting names the way PHP's
     * parser (and PartnerBeaconContext::repeated()) does, so `scp.key` counts too.
     *
     * @internal public only so the guard can be tested without a valid signature.
     */
    public static function keyCount(Request $request): int
    {
        $count = 0;

        foreach (explode('&', (string) $request->server->get('QUERY_STRING')) as $pair) {
            if ($pair !== '' && PartnerParams::queryName($pair) === PartnerParams::KEY) {
                $count++;
            }
        }

        return $count;
    }
}
