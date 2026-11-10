<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Partners;

/**
 * The verified partner of one embed page request. The gate stores it on the request
 * under ATTRIBUTE after every check passed; the controller reads it to build the
 * frame-ancestors header and the beacon proof.
 *
 * The origins are exactly what the row holds, not trusted: they are run through
 * PartnerOrigin again when the header is built.
 */
final readonly class PartnerContext
{
    /** The request attribute the gate stores the context under. */
    public const ATTRIBUTE = 'scarlett.partner';

    /**
     * @param  list<mixed>  $origins  as stored, not yet validated
     */
    public function __construct(
        public string $ref,
        public string $keyId,
        public array $origins,
    ) {}
}
