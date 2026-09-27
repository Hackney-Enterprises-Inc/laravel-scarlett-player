<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Doctor\CheckRegistry;
use Hei\ScarlettPlayer\Tests\Fixtures\Provider\PassingCheck;
use Hei\ScarlettPlayer\Tests\Fixtures\Provider\ProbeServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * Register the probe provider, whose route files each define one `probe` route, and
 * return the route registered under the name, or null.
 */
function probeRoute(string $name): ?RoutingRoute
{
    Route::getRoutes()->refreshNameLookups();

    return Route::getRoutes()->getByName($name);
}

/**
 * The configured group middleware as it appears on the route, in order. A module may
 * add its own middleware around the group (clips adds ExpectsJson); only the configured
 * list is the provider's contract.
 *
 * @param  list<string>  $configured
 * @return list<string>
 */
function configuredMiddleware(RoutingRoute $route, array $configured): array
{
    return array_values(array_filter($route->gatherMiddleware(), fn (mixed $m): bool => in_array($m, $configured, true)));
}

beforeEach(function (): void {
    $this->registerProbes = function (array $config = []): void {
        foreach ($config as $key => $value) {
            config()->set("scarlett-player.{$key}", $value);
        }

        app()->register(new ProbeServiceProvider(app()), force: true);
    };
});

it('loads each module under the prefix with its own middleware and name prefix', function (string $module, array $middleware): void {
    ($this->registerProbes)();

    $route = probeRoute("scarlett.{$module}.probe");

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe("api/scarlett/probe-{$module}")
        ->and(configuredMiddleware($route, $middleware))->toBe($middleware);
})->with([
    'beacons' => ['beacons', ['api', 'throttle:scarlett-beacons']],
    'clips' => ['clips', ['web', 'auth', 'throttle:scarlett-clips']],
    'oembed' => ['oembed', ['api']],
]);

it('loads the embed routes outside the prefix', function (): void {
    ($this->registerProbes)();

    $route = probeRoute('scarlett.embed.probe');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('v/probe')
        ->and($route->gatherMiddleware())->toBe(['web']);
});

it('applies a changed prefix and one overridden middleware group only', function (): void {
    ($this->registerProbes)([
        'routes.prefix' => 'video',
        'routes.middleware.beacons' => ['api'],
    ]);

    expect(probeRoute('scarlett.beacons.probe')->uri())->toBe('video/probe-beacons')
        ->and(probeRoute('scarlett.beacons.probe')->gatherMiddleware())->toBe(['api'])
        ->and(configuredMiddleware(probeRoute('scarlett.clips.probe'), ['web', 'auth', 'throttle:scarlett-clips']))
        ->toBe(['web', 'auth', 'throttle:scarlett-clips']);
});

it('does not load a module whose routes switch is off', function (string $switch, array $absent, array $present): void {
    ($this->registerProbes)(["routes.{$switch}" => false]);

    foreach ($absent as $name) {
        expect(probeRoute("scarlett.{$name}.probe"))->toBeNull();
    }

    foreach ($present as $name) {
        expect(probeRoute("scarlett.{$name}.probe"))->not->toBeNull();
    }
})->with([
    'beacons off' => ['beacons', ['beacons'], ['clips', 'embed', 'oembed']],
    'clips off' => ['clips', ['clips'], ['beacons', 'embed', 'oembed']],
    'embed off takes oembed with it' => ['embed', ['embed', 'oembed'], ['beacons', 'clips']],
]);

it('adds the provider doctor checks to the registry', function (): void {
    ($this->registerProbes)();

    expect(app(CheckRegistry::class)->classes())->toContain(PassingCheck::class);
});

it('registers the schedule entries when the Schedule resolves', function (): void {
    ($this->registerProbes)();

    $commands = collect(app(Schedule::class)->events())->map->command->implode(' ');

    expect($commands)->toContain('scarlett:doctor');
});
