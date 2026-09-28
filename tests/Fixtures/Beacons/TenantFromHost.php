<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Tests\Fixtures\Beacons;

use Hei\ScarlettPlayer\Contracts\ResolvesBeaconContext;
use Hei\ScarlettPlayer\Data\BeaconPayload;
use Illuminate\Http\Request;

/** The README's tenant recipe: the tenant from the host the beacon was posted to. */
final class TenantFromHost implements ResolvesBeaconContext
{
    private const TENANTS = ['acme.example.test' => 7, 'globex.example.test' => 9];

    public function resolve(Request $request, BeaconPayload $payload): array
    {
        return ['tenant_id' => self::TENANTS[$request->getHost()] ?? null];
    }
}
