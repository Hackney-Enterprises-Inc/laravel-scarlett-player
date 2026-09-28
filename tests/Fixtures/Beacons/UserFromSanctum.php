<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Tests\Fixtures\Beacons;

use Hei\ScarlettPlayer\Contracts\ResolvesBeaconContext;
use Hei\ScarlettPlayer\Data\BeaconPayload;
use Illuminate\Http\Request;

/** The README's Sanctum SPA recipe: the user of the stateful SPA session. */
final class UserFromSanctum implements ResolvesBeaconContext
{
    public function resolve(Request $request, BeaconPayload $payload): array
    {
        return ['user_id' => $request->user('sanctum')?->getAuthIdentifier()];
    }
}
