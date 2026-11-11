<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Partners;

/**
 * The partner signing contract, in one place: minting (the snippet and the beacon URL)
 * and verifying (the page gate and the beacon context) both read these names, lifetimes
 * and charsets, so the two sides cannot drift apart.
 *
 * Page URL: /v/{media}?scp_key=<key_id>[&expires=...], relative-signed.
 * Beacon URL: scarlett.beacons.store?scp_ref=<ref>&scp_media=<media>&expires=<now + BEACON_TTL>,
 * relative-signed, verified while ignoring BEACON_IGNORED.
 */
final class PartnerParams
{
    /** Page URL: the partner's random key id (32 hex), the only partner value on the page. */
    public const KEY = 'scp_key';

    /** Beacon URL: the partner ref the beacon is attributed to. */
    public const REF = 'scp_ref';

    /** Beacon URL: the media the proof is bound to; must equal the payload videoId. */
    public const MEDIA = 'scp_media';

    /**
     * Every partner parameter. Each is always covered by the signature, so none can be
     * listed in embed.unsigned_params.
     */
    public const SIGNED = [self::KEY, self::REF, self::MEDIA];

    /** Seconds a beacon proof (the signed beacon URL on the page) stays valid: 24 h. */
    public const BEACON_TTL = 86400;

    /** The furthest a signed partner page URL may expire from now, in seconds: 90 days. */
    public const MAX_EXPIRES = 7776000;

    /**
     * Query parameters the beacon signature ignores. The plugin appends api_key to the
     * beacon URL after it is signed; anything else appended breaks the signature.
     */
    public const BEACON_IGNORED = ['api_key'];

    /**
     * A media id a partner snippet may carry. The unload beacon re-serializes the beacon
     * URL's query through URLSearchParams, which leaves exactly these characters
     * byte-identical, so a signature over them survives it.
     */
    public const MEDIA_ID = '^[A-Za-z0-9._-]{1,191}$';

    /** A partner key id: 32 lowercase hex characters from random_bytes(16). */
    public const KEY_ID = '^[a-f0-9]{32}$';

    /** A partner ref: lowercase slug, at most 64 characters. */
    public const REF_PATTERN = '^[a-z0-9][a-z0-9-]{0,62}$';

    /** Whether the value is a partner key id: a string of 32 lowercase hex characters. */
    public static function isKeyId(mixed $value): bool
    {
        return self::matches(self::KEY_ID, $value);
    }

    /** Whether the value is a partner ref: a lowercase slug of at most 64 characters. */
    public static function isRef(mixed $value): bool
    {
        return self::matches(self::REF_PATTERN, $value);
    }

    /** Whether the value is a media id a partner snippet may carry (see MEDIA_ID). */
    public static function isMediaId(mixed $value): bool
    {
        return self::matches(self::MEDIA_ID, $value);
    }

    /**
     * The parameter name PHP's query parser would give one raw `name=value` pair: URL
     * decoded, leading spaces dropped, spaces and dots turned into underscores. The
     * duplicate guards of the page gate and the beacon proof both count names through
     * this, so `scp.key` and `scp_key` are one parameter to both.
     */
    public static function queryName(string $rawPair): string
    {
        return strtr(ltrim(urldecode(explode('=', $rawPair, 2)[0]), ' '), ' .', '__');
    }

    /** A new random key id. */
    public static function newKeyId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** Anchored, and `D` so `$` does not accept a trailing newline. */
    private static function matches(string $pattern, mixed $value): bool
    {
        return is_string($value) && preg_match('/'.$pattern.'/D', $value) === 1;
    }
}
