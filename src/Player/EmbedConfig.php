<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Player;

use Hei\ScarlettPlayer\Exceptions\InvalidEmbedConfigException;
use Hei\ScarlettPlayer\Partners\PartnerParams;
use Illuminate\Contracts\Config\Repository;

/**
 * The embed.* config, read once and made safe, for everything that signs, checks or
 * frames an embed.
 */
final class EmbedConfig
{
    /**
     * Parameters a signature always covers, whatever unsigned_params says: the expiry and
     * the signature itself, and every partner parameter (PartnerParams::SIGNED), so a
     * partner lock can never be configured away.
     */
    public const ALWAYS_SIGNED = ['expires', 'signature', ...PartnerParams::SIGNED];

    private const HOST = '/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/D';

    public function __construct(
        private readonly Repository $config,
    ) {}

    /**
     * Whether a value is a lowercase host name: dot separated labels of letters, digits
     * and inner hyphens, at most 253 characters. The one host charset the package shares
     * (allowed_domains and partner origins).
     */
    public static function isHost(string $host): bool
    {
        return preg_match(self::HOST, $host) === 1;
    }

    /**
     * embed.allowed_domains as bare lowercase hosts: a scheme, port, path and a leading
     * '*.' or '.' are stripped, so 'https://Example.com/' and '*.example.com' both read
     * as 'example.com' (which also allows its subdomains).
     *
     * @return list<string>
     *
     * @throws InvalidEmbedConfigException for an entry that is not a host.
     */
    public function allowedDomains(): array
    {
        $entries = $this->config->get('scarlett-player.embed.allowed_domains', []);
        $hosts = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                continue;
            }

            $host = strtolower(trim($entry));
            $host = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $host);
            $host = (string) preg_replace('#[/?\#].*$#', '', $host);
            $host = (string) preg_replace('#:\d+$#', '', $host);
            $host = (string) preg_replace('#^(\*\.|\.)#', '', $host);

            if (! self::isHost($host)) {
                throw InvalidEmbedConfigException::notAHost($entry);
            }

            $hosts[] = $host;
        }

        return array_values(array_unique($hosts));
    }

    /**
     * Whether a host is an allowed domain or a subdomain of one. True for any host when
     * no domains are configured.
     *
     * @throws InvalidEmbedConfigException for a malformed allowed_domains entry.
     */
    public function allowsHost(string $host): bool
    {
        $domains = $this->allowedDomains();

        if ($domains === []) {
            return true;
        }

        $host = strtolower($host);

        foreach ($domains as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * embed.unsigned_params without expires or signature: letting either out of the
     * signature would let anyone strip an expiry from a temporary URL.
     *
     * @return list<string>
     */
    public function unsignedParams(): array
    {
        $params = $this->config->get('scarlett-player.embed.unsigned_params', []);

        return array_values(array_filter(
            is_array($params) ? $params : [],
            fn (mixed $param): bool => is_string($param) && $param !== '' && ! in_array($param, self::ALWAYS_SIGNED, true),
        ));
    }

    /**
     * Seconds a minted embed signature lasts when no expiry is given, or null for none.
     */
    public function signedTtl(): ?int
    {
        $ttl = $this->config->get('scarlett-player.embed.signed_ttl');

        return is_numeric($ttl) && (int) $ttl > 0 ? (int) $ttl : null;
    }
}
