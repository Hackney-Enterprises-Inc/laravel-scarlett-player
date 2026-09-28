<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Data\BeaconPayload;
use Hei\ScarlettPlayer\Events\ViewStarted;
use Hei\ScarlettPlayer\Exceptions\InvalidBeaconContextException;
use Hei\ScarlettPlayer\Jobs\ProcessBeacon;
use Hei\ScarlettPlayer\Tests\Feature\Clips\Support\ClipTestSupport as T;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\Beacons;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\DropHeartbeats;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\RecordingContext;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\RedactEmail;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\TenantFromHost;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\UserFromSanctum;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\UserFromSession;
use Hei\ScarlettPlayer\Tests\TestCase;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Laravel\Sanctum\SanctumServiceProvider;

/*
 * beacons.context: the host's ResolvesBeaconContext runs in the beacon route, its
 * keys are server-owned on the beacon, and the store keeps them in scarlett_views.server,
 * merged per key. The store tests run on whatever DB_CONNECTION the run uses.
 */

/**
 * POST a beacon body the way the plugin does, with the key header.
 *
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $server
 */
function postContextBeacon(TestCase $test, array $body, array $server = [], string $url = '/api/scarlett/beacons'): TestResponse
{
    return $test->call('POST', $url, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => '*/*',
        'HTTP_X_API_KEY' => TestCase::BEACON_KEY,
        ...$server,
    ], (string) json_encode($body));
}

/**
 * The payload of the one ProcessBeacon the route queued.
 */
function queuedBeacon(): BeaconPayload
{
    $jobs = Queue::pushed(ProcessBeacon::class);

    expect($jobs)->toHaveCount(1);

    return $jobs->first()->payload;
}

beforeEach(function (): void {
    RecordingContext::reset();
});

describe('the beacon route', function (): void {
    beforeEach(function (): void {
        Queue::fake();
        config()->set('scarlett-player.beacons.context', RecordingContext::class);
    });

    it('gives the resolver the request and the validated payload', function (): void {
        postContextBeacon($this, Beacons::body('heartbeat', 0, ['watchTime' => 5_000, 'plan' => 'ppv']))->assertNoContent();

        expect(RecordingContext::$calls)->toHaveCount(1);

        [$request, $payload] = RecordingContext::$calls[0];

        expect($request->header('X-API-Key'))->toBe(TestCase::BEACON_KEY)
            ->and($payload->event)->toBe('heartbeat')
            ->and($payload->fields['watchTime'])->toBe(5_000)
            ->and($payload->custom)->toBe(['plan' => 'ppv']);
    });

    it('queues the beacon with its keys server-owned', function (): void {
        RecordingContext::$returns = ['user_id' => 42, 'tenant' => null];

        postContextBeacon($this, Beacons::body('heartbeat', 0, ['user_id' => 'spoofed', 'tenant' => 'spoofed', 'plan' => 'ppv']))->assertNoContent();

        $payload = queuedBeacon();

        expect($payload->server)->toBe(['user_id' => 42])
            ->and($payload->custom)->toBe(['plan' => 'ppv']);
    });

    it('queues the beacon unchanged when no resolver is configured', function (): void {
        config()->set('scarlett-player.beacons.context', null);

        postContextBeacon($this, Beacons::body('heartbeat', 0, ['user_id' => 'browser']))->assertNoContent();

        expect(queuedBeacon()->custom)->toBe(['user_id' => 'browser'])
            ->and(queuedBeacon()->server)->toBe([]);
    });

    it('does not call the resolver while beacons.enabled is off', function (): void {
        config()->set('scarlett-player.beacons.enabled', false);

        postContextBeacon($this, Beacons::body('heartbeat'))->assertNoContent();

        expect(RecordingContext::$calls)->toBe([]);
        Queue::assertNothingPushed();
    });

    it('does not call the resolver for a beacon that fails validation', function (): void {
        postContextBeacon($this, ['event' => 'heartbeat'])->assertStatus(422);

        expect(RecordingContext::$calls)->toBe([]);
    });

    it('throws when beacons.context is not a resolver', function (): void {
        config()->set('scarlett-player.beacons.context', DropHeartbeats::class);
        $this->withoutExceptionHandling();

        expect(fn () => postContextBeacon($this, Beacons::body('heartbeat')))
            ->toThrow(InvalidBeaconContextException::class, DropHeartbeats::class);

        Queue::assertNothingPushed();
    });

    it('answers 500 and queues nothing when the resolver throws', function (): void {
        RecordingContext::$throws = true;

        postContextBeacon($this, Beacons::body('heartbeat'))->assertStatus(500);

        Queue::assertNothingPushed();
    });

    it('answers 500 and queues nothing when the resolver returns an identity key', function (): void {
        RecordingContext::$returns = ['viewerId' => 'someone-else'];

        postContextBeacon($this, Beacons::body('heartbeat'))->assertStatus(500);

        Queue::assertNothingPushed();
    });
});

describe('the store', function (): void {
    beforeEach(function (): void {
        $this->usesMigrations();

        $this->deliver = function (BeaconPayload ...$payloads): void {
            foreach ($payloads as $payload) {
                app()->call([new ProcessBeacon($payload), 'handle']);
            }
        };

        $this->view = fn (): object => DB::table('scarlett_views')->where('view_id', Beacons::VIEW)->sole();
        $this->map = fn (string $column): array => json_decode((string) ($this->view)()->{$column}, true) ?? [];
    });

    it('writes the server map beside custom when the first beacon inserts the view', function (): void {
        ($this->deliver)(Beacons::payload('viewStart', 0, ['plan' => 'ppv', 'user_id' => 'spoofed'])->withServer(['user_id' => 42, 'tenant_id' => 7]));

        expect(($this->map)('server'))->toBe(['user_id' => 42, 'tenant_id' => 7])
            ->and(($this->map)('custom'))->toBe(['plan' => 'ppv'])
            ->and(array_keys(($this->map)('server_stamps')))->toBe(['user_id', 'tenant_id']);
    });

    it('merges the server map per key, the newest beacon winning each key whatever the order', function (bool $reversed): void {
        $beacons = [
            Beacons::payload('viewStart')->withServer(['user_id' => 1]),
            Beacons::heartbeat(10_000, 10_000, 80.0)->withServer(['user_id' => 2, 'tenant_id' => 7]),
            Beacons::heartbeat(5_000, 5_000, 90.0)->withServer(['user_id' => 3, 'plan' => 'free']),
        ];

        ($this->deliver)(...($reversed ? array_reverse($beacons) : $beacons));

        expect(($this->map)('server'))->toEqualCanonicalizing(['user_id' => 2, 'tenant_id' => 7, 'plan' => 'free']);
    })->with(['in order' => false, 'reversed' => true]);

    it('never lets a browser user_id reach custom on a beacon whose resolver owned it', function (): void {
        ($this->deliver)(
            Beacons::payload('viewStart', 0, ['user_id' => 'spoofed'])->withServer(['user_id' => 42]),
            Beacons::heartbeat(10_000, 10_000, 80.0)->withCustom(['user_id' => 'spoofed', 'plan' => 'ppv'])->withServer(['user_id' => 42]),
        );

        expect(($this->map)('custom'))->toBe(['plan' => 'ppv'])
            ->and(($this->map)('server'))->toBe(['user_id' => 42]);
    });

    it('stores nothing for a null from the resolver, and strips the browser copy', function (): void {
        ($this->deliver)(Beacons::payload('viewStart', 0, ['user_id' => 'spoofed', 'plan' => 'ppv'])->withServer(['user_id' => null]));

        $view = ($this->view)();

        expect($view->server)->toBeNull()
            ->and($view->server_stamps)->toBeNull()
            ->and(($this->map)('custom'))->toBe(['plan' => 'ppv']);
    });

    it('never deletes a stored server value when a later beacon returns null for it', function (): void {
        ($this->deliver)(
            Beacons::payload('viewStart')->withServer(['user_id' => 42]),
            Beacons::heartbeat(10_000, 10_000, 80.0)->withServer(['user_id' => null, 'tenant_id' => 7]),
        );

        expect(($this->map)('server'))->toBe(['user_id' => 42, 'tenant_id' => 7]);
    });

    it('can be queried by key on every engine', function (): void {
        ($this->deliver)(Beacons::payload('viewStart')->withServer(['tenant_id' => 7, 'plan' => 'ppv']));

        expect(DB::table('scarlett_views')->where('server->plan', 'ppv')->count())->toBe(1)
            ->and(DB::table('scarlett_views')->where('server->tenant_id', 7)->count())->toBe(1)
            ->and(DB::table('scarlett_views')->where('server->tenant_id', 8)->count())->toBe(0);
    });

    it('writes the server value into the raw log payload, under a name the browser also used', function (): void {
        ($this->deliver)(Beacons::payload('viewStart', 0, ['user_id' => 'spoofed'])->withServer(['user_id' => 42]));

        $raw = json_decode((string) DB::table('scarlett_beacon_events')->sole()->payload, true);

        expect($raw['user_id'])->toBe(42);
    });

    it('keeps the event key of a beacon whatever server context it carries', function (): void {
        ($this->deliver)(
            Beacons::payload('viewStart')->withServer(['user_id' => 42]),
            Beacons::payload('viewStart')->withServer(['user_id' => 43]),
        );

        expect(DB::table('scarlett_beacon_events')->count())->toBe(1);
    });

    it('stores one raw row and one error row when a resolver starts claiming a key the browser sent', function (): void {
        $error = Beacons::payload('error', 1_000, ['errorType' => 'A', 'errorMessage' => 'a', 'fatal' => true, 'user_id' => 'browser']);

        ($this->deliver)($error, $error->withServer(['user_id' => 42]));

        expect(DB::table('scarlett_beacon_events')->count())->toBe(1)
            ->and(DB::table('scarlett_view_errors')->count())->toBe(1);
    });

    it('stores one raw row for a beacon delivered before and after a redacting step was added', function (): void {
        $beacon = Beacons::payload('heartbeat', 0, ['email' => 'viewer@example.com']);

        ($this->deliver)($beacon);
        config()->set('scarlett-player.beacons.pipeline', [RedactEmail::class]);
        ($this->deliver)($beacon);

        expect(DB::table('scarlett_beacon_events')->count())->toBe(1);
    });

    it('never stores a key owned as null that a later step put back into custom', function (): void {
        ($this->deliver)(
            Beacons::payload('viewStart', 0, ['user_id' => 'spoofed'])
                ->withServer(['user_id' => null])
                ->withCustom(['user_id' => 'browser', 'plan' => 'ppv']),
        );

        expect(($this->map)('custom'))->toBe(['plan' => 'ppv'])
            ->and(($this->view)()->server)->toBeNull();
    });

    it('announces ViewStarted with the server context on the payload', function (): void {
        Event::fake([ViewStarted::class]);

        ($this->deliver)(Beacons::payload('viewStart')->withServer(['user_id' => 42]));

        Event::assertDispatched(ViewStarted::class, fn (ViewStarted $event): bool => $event->payload->server === ['user_id' => 42]);
    });

    it('stores beacons without server keys on a 0.1.0 table that lacks the server columns', function (): void {
        Schema::table('scarlett_views', function ($table): void {
            $table->dropColumn(['server', 'server_stamps']);
        });

        ($this->deliver)(
            Beacons::payload('viewStart', 0, ['plan' => 'ppv']),
            Beacons::heartbeat(10_000, 10_000, 80.0),
            Beacons::payload('pause', 11_000, ['plan' => 'free']),
            Beacons::unloadViewEnd(12_000, 12_000),
        );

        $view = ($this->view)();

        expect((int) $view->watch_ms)->toBe(12_000)
            ->and(json_decode((string) $view->custom, true))->toBe(['plan' => 'free'])
            ->and($view->ended_at)->not->toBeNull();

        // Only a beacon that carries server keys needs the columns; scarlett:doctor
        // fails the beacon context check for this schema.
        expect(fn () => ($this->deliver)(Beacons::heartbeat(20_000, 20_000, 80.0)->withServer(['user_id' => 42])))
            ->toThrow(QueryException::class);

        // Put them back for the migration rollback that ends the test.
        Schema::table('scarlett_views', function ($table): void {
            $table->json('server')->nullable();
            $table->json('server_stamps')->nullable();
        });
    });

    it('stores the resolver keys end to end from the route', function (): void {
        config()->set('scarlett-player.beacons.context', RecordingContext::class);
        RecordingContext::$returns = ['user_id' => 42];

        postContextBeacon($this, Beacons::body('viewStart', 0, ['user_id' => 'spoofed', 'plan' => 'ppv']))->assertNoContent();

        expect(($this->map)('server'))->toBe(['user_id' => 42])
            ->and(($this->map)('custom'))->toBe(['plan' => 'ppv']);
    });
});

describe('recipe: tenant from the request', function (): void {
    beforeEach(function (): void {
        Queue::fake();
        config()->set('scarlett-player.beacons.context', TenantFromHost::class);
    });

    it('resolves the tenant from the host the beacon was posted to, with no extra middleware', function (): void {
        postContextBeacon($this, Beacons::body('heartbeat', 0, ['tenant_id' => 9]), [], 'https://acme.example.test/api/scarlett/beacons')
            ->assertNoContent();

        expect(queuedBeacon()->server)->toBe(['tenant_id' => 7])
            ->and(queuedBeacon()->custom)->toBe([]);
    });

    it('strips a browser tenant_id on an unknown host and stores none', function (): void {
        postContextBeacon($this, Beacons::body('heartbeat', 0, ['tenant_id' => 9]), [], 'https://unknown.example.test/api/scarlett/beacons')
            ->assertNoContent();

        expect(queuedBeacon()->server)->toBe([])
            ->and(queuedBeacon()->custom)->toBe([]);
    });
});

describe('recipe: user from the session', function (): void {
    beforeEach(function (): void {
        $this->withScarlettConfig([
            'routes.middleware.beacons' => ['api', 'throttle:scarlett-beacons', EncryptCookies::class, StartSession::class],
            'beacons.context' => UserFromSession::class,
        ]);
        Queue::fake();
        T::createUsers();
        $this->user = T::user();
    });

    it('sees the signed-in user of a real session', function (): void {
        $this->withSession([Auth::guard('web')->getName() => $this->user->id]);

        postContextBeacon($this, Beacons::body('heartbeat', 0, ['user_id' => 999]))->assertNoContent();

        expect(queuedBeacon()->server)->toBe(['user_id' => $this->user->id])
            ->and(queuedBeacon()->custom)->toBe([]);
    });

    it('beacons for a guest too, with no user_id from anywhere', function (): void {
        postContextBeacon($this, Beacons::body('heartbeat', 0, ['user_id' => 999]))->assertNoContent();

        expect(queuedBeacon()->server)->toBe([])
            ->and(queuedBeacon()->custom)->toBe([]);
    });

    it('needs no CSRF token: the group has no CSRF middleware, and the unload beacon cannot send one', function (): void {
        $this->app->detectEnvironment(fn (): string => 'local');
        $this->withSession(['_token' => 'csrf-token', Auth::guard('web')->getName() => $this->user->id]);

        postContextBeacon($this, Beacons::body('heartbeat'))->assertNoContent();

        expect(queuedBeacon()->server)->toBe(['user_id' => $this->user->id]);
    });
});

describe('recipe: Sanctum SPA', function (): void {
    beforeEach(function (): void {
        $this->withScarlettConfig([
            'routes.middleware.beacons' => ['api', EnsureFrontendRequestsAreStateful::class, 'throttle:scarlett-beacons'],
            'beacons.context' => UserFromSanctum::class,
        ]);
        $this->app->register(SanctumServiceProvider::class);
        config()->set('sanctum.stateful', ['spa.example.test']);
        Queue::fake();
        T::createUsers();
        $this->user = T::user();
        $this->spa = ['HTTP_ORIGIN' => 'https://spa.example.test', 'HTTP_REFERER' => 'https://spa.example.test/watch'];
    });

    afterEach(function (): void {
        ValidateCsrfToken::flushState();
    });

    it('sees the user of the stateful SPA session', function (): void {
        $this->withSession([Auth::guard('web')->getName() => $this->user->id]);

        postContextBeacon($this, Beacons::body('heartbeat', 0, ['user_id' => 999]), $this->spa)->assertNoContent();

        expect(queuedBeacon()->server)->toBe(['user_id' => $this->user->id]);
    });

    it('beacons for a guest too', function (): void {
        postContextBeacon($this, Beacons::body('heartbeat'), $this->spa)->assertNoContent();

        expect(queuedBeacon()->server)->toBe([]);
    });

    it('needs the beacon path exempt from CSRF once it is enforced: Sanctum adds the check to a stateful request', function (): void {
        $this->app->detectEnvironment(fn (): string => 'local');
        $this->withSession(['_token' => 'spa-csrf', Auth::guard('web')->getName() => $this->user->id]);

        postContextBeacon($this, Beacons::body('heartbeat'), $this->spa)->assertStatus(419);
        Queue::assertNothingPushed();

        ValidateCsrfToken::except(['api/scarlett/beacons']);

        postContextBeacon($this, Beacons::body('heartbeat'), $this->spa)->assertNoContent();

        expect(queuedBeacon()->server)->toBe(['user_id' => $this->user->id]);
    });
});

describe('recipe: a token, for an ingest on another origin', function (): void {
    beforeEach(function (): void {
        $this->withScarlettConfig(['beacons.context' => UserFromSanctum::class]);
        $this->app->register(SanctumServiceProvider::class);
        Queue::fake();
        T::createUsers();
        $this->loadMigrationsFrom(dirname(__DIR__, 3).'/vendor/laravel/sanctum/database/migrations');
        $this->user = T::user();
        $this->token = $this->user->createToken('beacons')->plainTextToken;
    });

    it('sees the user of the Bearer token on the default beacon middleware', function (): void {
        postContextBeacon($this, Beacons::body('heartbeat', 0, ['user_id' => 999]), ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token])
            ->assertNoContent();

        expect(queuedBeacon()->server)->toBe(['user_id' => $this->user->id])
            ->and(queuedBeacon()->custom)->toBe([]);
    });

    it('treats the headerless unload beacon as a guest', function (): void {
        $this->call('POST', '/api/scarlett/beacons?api_key='.TestCase::BEACON_KEY, [], [], [], ['CONTENT_TYPE' => 'text/plain;charset=UTF-8'], (string) json_encode(Beacons::body('viewEnd', 0, ['user_id' => 999])))
            ->assertNoContent();

        expect(queuedBeacon()->server)->toBe([])
            ->and(queuedBeacon()->custom)->toBe([]);
    });
});

it('lets scarlett:beacon:test pass with a resolver configured', function (): void {
    config()->set('scarlett-player.beacons.context', RecordingContext::class);
    RecordingContext::$returns = ['user_id' => null];

    Http::fake(function (ClientRequest $request) {
        $uri = (string) parse_url($request->url(), PHP_URL_PATH).(($q = parse_url($request->url(), PHP_URL_QUERY)) ? '?'.$q : '');
        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($request->headers() as $name => $values) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $values[0];
        }

        $response = $this->call('POST', $uri, [], [], [], $server, $request->body());

        return Http::response($response->getContent(), $response->getStatusCode());
    });

    Queue::fake();

    $this->artisan('scarlett:beacon:test', ['--url' => 'http://localhost/api/scarlett/beacons'])->assertSuccessful();

    expect(RecordingContext::$calls)->not->toBe([]);
    Queue::assertPushed(ProcessBeacon::class, count(RecordingContext::$calls));
});
