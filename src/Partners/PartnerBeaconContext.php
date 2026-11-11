<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Partners;

use Hei\ScarlettPlayer\Data\BeaconPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

/**
 * A verified partner proof on a beacon request. The only way to attribute a beacon to
 * a partner: the constructor is private and verify() builds one only from a beacon URL
 * the page signed (BeaconPayload::withVerifiedPartner() takes this type), so nothing a
 * browser or a host pipeline sends can name a partner by itself.
 *
 * The proof is the URL's query: scp_ref, scp_media and expires, covered by a relative
 * Laravel signature that ignores only api_key (the player appends it after signing).
 * The server clock decides expiry, URL root and scheme do not matter, and no database
 * row is read: the beacon path stays DB-free, so a revoked partner keeps attributing
 * until the proof's own expiry (at most PartnerParams::BEACON_TTL from the page load).
 *
 * Fails closed to null, never to an exception: a beacon without a valid proof is still
 * ingested, just not attributed.
 */
final readonly class PartnerBeaconContext
{
    /** Clock slack, in seconds, allowed on top of the proof lifetime when checking an expiry ceiling. */
    private const SLACK = 300;

    private function __construct(public string $ref) {}

    /**
     * The verified proof of this beacon request, or null when it carries none or any check
     * fails: ref, media and expires shapes, no repeated proof name, the media equal to the
     * payload videoId, an expiry no further than the proof lifetime plus clock slack, and a
     * valid relative signature ignoring api_key. Never reads the database, never throws.
     */
    public static function verify(Request $request, BeaconPayload $payload): ?self
    {
        $query = $request->query();

        // Cheap exit for every beacon without a proof.
        if (! isset($query[PartnerParams::REF]) && ! isset($query[PartnerParams::MEDIA])) {
            return null;
        }

        $ref = $query[PartnerParams::REF] ?? null;
        $media = $query[PartnerParams::MEDIA] ?? null;
        $expires = $query['expires'] ?? null;

        if (! PartnerParams::isRef($ref) || ! PartnerParams::isMediaId($media) || ! is_string($expires) || preg_match('/^[0-9]{1,12}$/D', $expires) !== 1) {
            return null;
        }

        // Defence in depth. The signature below already covers every raw pair except
        // api_key, so a repeated or array form of a proof name breaks it on its own; this
        // guard keeps the verifier safe if that framework behaviour ever changes, because
        // the parsed query above keeps only the last value of a repeated name.
        if (self::repeated($request, [PartnerParams::REF, PartnerParams::MEDIA, 'expires'])) {
            return null;
        }

        // The proof is bound to one media: the one the beacon reports.
        if ($media !== $payload->videoId) {
            return null;
        }

        $now = Date::now()->getTimestamp();

        // The page mints now + BEACON_TTL; a longer proof was not minted by this package.
        if ((int) $expires > $now + PartnerParams::BEACON_TTL + self::SLACK) {
            return null;
        }

        // Relative, ignoring only api_key. Also rejects an expired proof (server clock).
        if (! $request->hasValidRelativeSignatureWhileIgnoring(PartnerParams::BEACON_IGNORED)) {
            return null;
        }

        return new self($ref);
    }

    /**
     * Whether the raw query string carries any of the names more than once, or in an
     * array form (`name[]`, `name[k]`).
     *
     * @internal public only so the guard branch can be tested without a valid signature.
     *
     * @param  list<string>  $names
     */
    public static function repeated(Request $request, array $names): bool
    {
        $raw = (string) $request->server->get('QUERY_STRING', '');
        $seen = [];

        foreach (explode('&', $raw) as $pair) {
            if ($pair === '') {
                continue;
            }

            // PHP's parser folds `scp.ref` into `scp_ref`; so does the page gate's count.
            $name = PartnerParams::queryName($pair);

            foreach ($names as $watched) {
                if ($name === $watched) {
                    if (isset($seen[$watched])) {
                        return true;
                    }

                    $seen[$watched] = true;
                } elseif (str_starts_with($name, $watched.'[')) {
                    return true;
                }
            }
        }

        return false;
    }
}
