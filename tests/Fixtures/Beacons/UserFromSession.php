<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Tests\Fixtures\Beacons;

use Hei\ScarlettPlayer\Contracts\ResolvesBeaconContext;
use Hei\ScarlettPlayer\Data\BeaconPayload;
use Illuminate\Http\Request;

/** The README's session recipe: the signed-in user, or null for a guest. */
final class UserFromSession implements ResolvesBeaconContext
{
    public function resolve(Request $request, BeaconPayload $payload): array
    {
        return ['user_id' => $request->user('web')?->getAuthIdentifier()];
    }
}
