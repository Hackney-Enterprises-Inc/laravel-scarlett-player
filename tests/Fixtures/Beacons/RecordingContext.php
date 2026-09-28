<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Tests\Fixtures\Beacons;

use Hei\ScarlettPlayer\Contracts\ResolvesBeaconContext;
use Hei\ScarlettPlayer\Data\BeaconPayload;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A context resolver that records what it was given and returns $returns, or throws
 * while $throws is set. Reset in the test's beforeEach.
 */
final class RecordingContext implements ResolvesBeaconContext
{
    /** @var array<array-key, mixed> */
    public static array $returns = [];

    public static bool $throws = false;

    /** @var list<array{0: Request, 1: BeaconPayload}> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$returns = [];
        self::$throws = false;
        self::$calls = [];
    }

    public function resolve(Request $request, BeaconPayload $payload): array
    {
        self::$calls[] = [$request, $payload];

        if (self::$throws) {
            throw new RuntimeException('context resolver failed');
        }

        /** @var array<string, mixed> */
        return self::$returns;
    }
}
