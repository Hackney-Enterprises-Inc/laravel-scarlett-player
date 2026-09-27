<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Doctor\CheckRegistry;
use Hei\ScarlettPlayer\Doctor\CheckResult;
use Hei\ScarlettPlayer\Doctor\Checks\BeaconIpColumnCheck;
use Hei\ScarlettPlayer\Doctor\Checks\BeaconQueueCheck;
use Hei\ScarlettPlayer\Doctor\Checks\BeaconRouteCheck;
use Hei\ScarlettPlayer\Doctor\Checks\CorsCheck;
use Hei\ScarlettPlayer\Doctor\CheckStatus;
use Illuminate\Routing\RouteCollection;

/*
 * The beacons module's doctor checks: CORS for the unload beacon, the beacon queue,
 * the route.
 */

function beaconCheck(string $class): CheckResult
{
    return app($class)->run();
}

/**
 * The plan's CORS recipe for the default prefix.
 *
 * @return array<string, mixed>
 */
function corsRecipe(array $overrides = []): array
{
    return [
        'paths' => ['api/scarlett/beacons'],
        'allowed_methods' => ['POST', 'OPTIONS'],
        'allowed_origins' => ['https://embed.example.com'],
        'allowed_origins_patterns' => [],
        'allowed_headers' => ['Content-Type', 'X-API-Key'],
        'exposed_headers' => [],
        'max_age' => 600,
        'supports_credentials' => true,
        ...$overrides,
    ];
}

it('lists the beacon checks in the doctor registry', function (): void {
    $names = array_map(fn ($check): string => $check::class, app(CheckRegistry::class)->checks());

    expect($names)->toContain(CorsCheck::class, BeaconQueueCheck::class, BeaconRouteCheck::class, BeaconIpColumnCheck::class);
});

describe('beacon cors', function (): void {
    it('passes the documented recipe', function (): void {
        config()->set('cors', corsRecipe());

        expect(beaconCheck(CorsCheck::class)->status)->toBe(CheckStatus::Pass);
    });

    it('accepts a wildcard path pattern and wildcard headers', function (): void {
        config()->set('cors', corsRecipe(['paths' => ['api/*'], 'allowed_headers' => ['*']]));

        expect(beaconCheck(CorsCheck::class)->status)->toBe(CheckStatus::Pass);
    });

    it('warns on each way the unload beacon is lost', function (array $overrides, string $message): void {
        config()->set('cors', corsRecipe($overrides));

        $result = beaconCheck(CorsCheck::class);

        expect($result->status)->toBe(CheckStatus::Warn)
            ->and($result->message)->toContain($message);
    })->with([
        'path not covered' => [['paths' => ['api/other']], 'cors.paths does not cover [api/scarlett/beacons]'],
        'no credentials' => [['supports_credentials' => false], 'supports_credentials is false'],
        'a * origin with credentials' => [['allowed_origins' => ['*']], "contains '*'"],
        'no X-API-Key header' => [['allowed_headers' => ['Content-Type']], 'missing X-API-Key'],
    ]);

    it('follows a custom route prefix', function (): void {
        config()->set('scarlett-player.routes.prefix', 'analytics');
        config()->set('cors', corsRecipe());

        expect(beaconCheck(CorsCheck::class)->message)->toContain('[analytics/beacons]');
    });

    it('passes when beacons are off', function (): void {
        config()->set('scarlett-player.beacons.enabled', false);
        config()->set('cors', []);

        expect(beaconCheck(CorsCheck::class)->status)->toBe(CheckStatus::Pass);
    });
});

describe('beacon queue', function (): void {
    it('names the worker command for the beacon queue', function (): void {
        config()->set('queue.default', 'redis');

        $result = beaconCheck(BeaconQueueCheck::class);

        expect($result->status)->toBe(CheckStatus::Pass)
            ->and($result->message)->toBe('run: php artisan queue:work redis --queue=scarlett-beacons');
    });

    it('warns when beacons and clips share one queue on one connection', function (): void {
        config()->set('scarlett-player.clips.queue', 'scarlett-beacons');

        expect(beaconCheck(BeaconQueueCheck::class)->status)->toBe(CheckStatus::Warn);
    });

    it('does not warn when the same queue name is on different connections', function (): void {
        config()->set('scarlett-player.clips.queue', 'scarlett-beacons');
        config()->set('scarlett-player.clips.connection', 'redis');
        config()->set('scarlett-player.beacons.connection', 'database');

        expect(beaconCheck(BeaconQueueCheck::class)->status)->toBe(CheckStatus::Pass);
    });
});

describe('beacon route', function (): void {
    it('reports the registered path', function (): void {
        $result = beaconCheck(BeaconRouteCheck::class);

        expect($result->status)->toBe(CheckStatus::Pass)
            ->and($result->message)->toContain('POST /api/scarlett/beacons');
    });

    it('warns when the route is registered but beacons are disabled', function (): void {
        config()->set('scarlett-player.beacons.enabled', false);

        expect(beaconCheck(BeaconRouteCheck::class)->status)->toBe(CheckStatus::Warn);
    });

    it('fails when routes.beacons is on but the route is missing', function (): void {
        app('router')->setRoutes(new RouteCollection);

        expect(beaconCheck(BeaconRouteCheck::class)->status)->toBe(CheckStatus::Fail);
    });

    it('passes when routes.beacons is off', function (): void {
        config()->set('scarlett-player.routes.beacons', false);

        expect(beaconCheck(BeaconRouteCheck::class)->status)->toBe(CheckStatus::Pass);
    });
});

describe('beacon ip column', function (): void {
    it('passes while store_ip is off', function (): void {
        expect(beaconCheck(BeaconIpColumnCheck::class)->status)->toBe(CheckStatus::Pass);
    });

    it('fails when store_ip was turned on after the migration ran without the column', function (): void {
        $this->usesMigrations();
        config()->set('scarlett-player.beacons.store_ip', true);

        $result = beaconCheck(BeaconIpColumnCheck::class);

        expect($result->status)->toBe(CheckStatus::Fail)
            ->and($result->message)->toContain('ip_address');
    });

    it('passes when store_ip was on at migration time', function (): void {
        config()->set('scarlett-player.beacons.store_ip', true);
        $this->usesMigrations();

        expect(beaconCheck(BeaconIpColumnCheck::class)->status)->toBe(CheckStatus::Pass);
    });

    it('warns when the views table is not migrated yet', function (): void {
        config()->set('scarlett-player.beacons.store_ip', true);

        expect(beaconCheck(BeaconIpColumnCheck::class)->status)->toBe(CheckStatus::Warn);
    });
});
