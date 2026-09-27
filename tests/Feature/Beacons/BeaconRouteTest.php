<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Http\Middleware\ScarlettApiKey;
use Hei\ScarlettPlayer\Jobs\ProcessBeacon;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\Beacons;
use Hei\ScarlettPlayer\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/*
 * POST {prefix}/beacons: the key from X-API-Key or ?api_key=, compared with
 * hash_equals, and OPTIONS never 401s.
 */

const BEACON_URI = '/api/scarlett/beacons';

beforeEach(function (): void {
    Queue::fake();
});

/**
 * POST a JSON body the way the plugin does, raw, so no framework helper reshapes it.
 *
 * @param  array<string, mixed>|list<mixed>  $body
 * @param  array<string, string>  $headers
 */
function postBeacon(TestCase $test, string $uri, array $body, array $headers = []): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => '*/*'];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return $test->call('POST', $uri, [], [], [], $server, (string) json_encode($body));
}

it('registers POST beacons as scarlett.beacons.store with the api group, the limiter and the key middleware', function (): void {
    Route::getRoutes()->refreshNameLookups();
    $route = Route::getRoutes()->getByName('scarlett.beacons.store');

    expect($route)->not->toBeNull()
        ->and($route->methods())->toContain('POST')
        ->and($route->uri())->toBe('api/scarlett/beacons')
        ->and($route->gatherMiddleware())->toBe(['api', 'throttle:scarlett-beacons', ScarlettApiKey::class]);
});

it('accepts the key from the X-API-Key header (the fetch transport)', function (): void {
    postBeacon($this, BEACON_URI, Beacons::body('viewStart'), ['X-API-Key' => TestCase::BEACON_KEY])
        ->assertNoContent();

    Queue::assertPushed(ProcessBeacon::class, 1);
});

it('accepts the key from ?api_key= with no header (the unload sendBeacon)', function (): void {
    postBeacon($this, BEACON_URI.'?api_key='.TestCase::BEACON_KEY, Beacons::body('viewEnd'))
        ->assertNoContent();

    Queue::assertPushed(ProcessBeacon::class, 1);
});

it('falls back to ?api_key= when the header is present but empty', function (): void {
    postBeacon($this, BEACON_URI.'?api_key='.TestCase::BEACON_KEY, Beacons::body('viewEnd'), ['X-API-Key' => ''])
        ->assertNoContent();
});

it('rejects a wrong or missing key with a JSON 401', function (string $uri, array $headers): void {
    postBeacon($this, $uri, Beacons::body('viewStart'), $headers)
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Invalid beacon key.']);

    Queue::assertNothingPushed();
})->with([
    'wrong header' => [BEACON_URI, ['X-API-Key' => 'wrong']],
    'wrong query' => [BEACON_URI.'?api_key=wrong', []],
    'a prefix of the key' => [BEACON_URI.'?api_key='.substr(TestCase::BEACON_KEY, 0, 4), []],
    'the key with a trailing space' => [BEACON_URI, ['X-API-Key' => TestCase::BEACON_KEY.' ']],
    'no key' => [BEACON_URI, []],
    'an array in the query' => [BEACON_URI.'?api_key[]='.TestCase::BEACON_KEY, []],
]);

it('refuses every beacon while beacons.key is unset (fails closed)', function (): void {
    config()->set('scarlett-player.beacons.key', null);

    postBeacon($this, BEACON_URI, Beacons::body('viewStart'), ['X-API-Key' => ''])->assertUnauthorized();
    postBeacon($this, BEACON_URI.'?api_key=', Beacons::body('viewStart'))->assertUnauthorized();
});

it('never 401s an OPTIONS preflight, with or without a CORS config covering the path', function (array $cors): void {
    config()->set('cors', $cors);

    $response = $this->call('OPTIONS', BEACON_URI, [], [], [], [
        'HTTP_ORIGIN' => 'https://embed.example.com',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-api-key',
    ]);

    expect($response->getStatusCode())->not->toBe(401)->toBeLessThan(400);

    if (($cors['supports_credentials'] ?? false) === true) {
        expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://embed.example.com')
            ->and($response->headers->get('Access-Control-Allow-Credentials'))->toBe('true');
    }
})->with([
    'the credentialed recipe' => [[
        'paths' => ['api/scarlett/beacons'],
        'allowed_methods' => ['POST', 'OPTIONS'],
        'allowed_origins' => ['https://embed.example.com'],
        'allowed_origins_patterns' => [],
        'allowed_headers' => ['Content-Type', 'X-API-Key'],
        'exposed_headers' => [],
        'max_age' => 600,
        'supports_credentials' => true,
    ]],
    'no path covered' => [['paths' => []]],
]);

it('lets the ScarlettApiKey middleware pass OPTIONS on its own, whatever runs before it', function (): void {
    $middleware = app(ScarlettApiKey::class);
    $request = Request::create(BEACON_URI, 'OPTIONS');

    $response = $middleware->handle($request, fn () => new Response('passed', 200));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('passed');
});

it('throttles with the scarlett-beacons limiter from beacons.throttle', function (): void {
    $this->withScarlettConfig(['beacons.throttle' => '2,1']);
    Queue::fake();

    $headers = ['X-API-Key' => TestCase::BEACON_KEY];

    postBeacon($this, BEACON_URI, Beacons::body('heartbeat', 1), $headers)->assertNoContent();
    postBeacon($this, BEACON_URI, Beacons::body('heartbeat', 2), $headers)->assertNoContent();
    postBeacon($this, BEACON_URI, Beacons::body('heartbeat', 3), $headers)->assertTooManyRequests();
});

it('registers no beacon route when routes.beacons is off', function (): void {
    $this->withScarlettConfig(['routes.beacons' => false]);

    Route::getRoutes()->refreshNameLookups();

    expect(Route::getRoutes()->getByName('scarlett.beacons.store'))->toBeNull();
});
