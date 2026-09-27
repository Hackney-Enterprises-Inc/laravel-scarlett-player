<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Jobs\RenderClip;
use Hei\ScarlettPlayer\Models\Clip;
use Hei\ScarlettPlayer\Tests\Feature\Clips\Support\ClipTestSupport as T;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\SessionGuard;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Laravel\Sanctum\SanctumServiceProvider;

/*
 * The three authentication recipes for the clip routes, each proven in a bare
 * testbench app: the default web + auth group, the Sanctum SPA recipe and the Sanctum
 * token recipe. Every request is sent the way submit.ts sends it: JSON body, no Accept.
 */

function prepareClipApp(): void
{
    T::fakeDisk();
    T::media(T::source());
    Bus::fake([RenderClip::class]);
}

function sessionLogin(int $userId): array
{
    return ['login_web_'.sha1(SessionGuard::class) => $userId];
}

describe('default recipe: web + auth + throttle', function (): void {
    beforeEach(function (): void {
        T::createUsers();
        $this->usesMigrations();
        prepareClipApp();
        $this->user = T::user();
    });

    test('an authenticated session with a valid CSRF token gets 202', function (): void {
        $this->app->detectEnvironment(fn (): string => 'local');

        $this->withSession(['_token' => 'csrf-token'] + sessionLogin($this->user->id));

        T::post($this, T::payload(), ['HTTP_X_CSRF_TOKEN' => 'csrf-token'])->assertStatus(202);
        expect(Clip::query()->count())->toBe(1);
    });

    test('no session gets a JSON 401, not a redirect to a login route', function (): void {
        T::post($this, T::payload())
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    });

    test('a valid session with no X-CSRF-TOKEN gets 419', function (): void {
        $this->app->detectEnvironment(fn (): string => 'local');

        $this->withSession(['_token' => 'csrf-token'] + sessionLogin($this->user->id));

        T::post($this, T::payload())->assertStatus(419);
        expect(Clip::query()->count())->toBe(0);
    });

    test('a valid session with the wrong X-CSRF-TOKEN gets 419', function (): void {
        $this->app->detectEnvironment(fn (): string => 'local');

        $this->withSession(['_token' => 'csrf-token'] + sessionLogin($this->user->id));

        T::post($this, T::payload(), ['HTTP_X_CSRF_TOKEN' => 'forged'])->assertStatus(419);
    });

    test('the create route is throttled by scarlett-clips', function (): void {
        config()->set('scarlett-player.clips.throttle', '2,1');
        $this->actingAs($this->user);

        T::post($this, T::payload(['clientRequestId' => 'a']))->assertStatus(202);
        T::post($this, T::payload(['clientRequestId' => 'b']))->assertStatus(202);
        T::post($this, T::payload(['clientRequestId' => 'c']))->assertStatus(429);
    });

    test('status and play run without auth and throttle; create keeps both', function (): void {
        $routes = app('router')->getRoutes();

        $create = app('router')->gatherRouteMiddleware($routes->getByName('scarlett.clips.store'));
        $status = app('router')->gatherRouteMiddleware($routes->getByName('scarlett.clips.show'));
        $play = app('router')->gatherRouteMiddleware($routes->getByName('scarlett.clips.play'));
        $preview = app('router')->gatherRouteMiddleware($routes->getByName('scarlett.clips.preview'));

        $hasAuth = fn (array $middleware): bool => collect($middleware)->contains(fn ($m): bool => is_string($m) && ($m === 'auth' || str_starts_with($m, 'auth:') || str_starts_with($m, Authenticate::class)));
        $hasThrottle = fn (array $middleware): bool => collect($middleware)->contains(fn ($m): bool => is_string($m) && str_contains($m, 'scarlett-clips'));

        expect($hasAuth($create))->toBeTrue()
            ->and($hasThrottle($create))->toBeTrue()
            ->and($hasAuth($status) || $hasThrottle($status))->toBeFalse()
            ->and($hasAuth($play) || $hasThrottle($play))->toBeFalse()
            ->and($hasAuth($preview) || $hasThrottle($preview))->toBeFalse()
            ->and($preview)->toContain(ValidateSignature::class);
    });
});

describe('Sanctum SPA recipe', function (): void {
    beforeEach(function (): void {
        $this->withScarlettConfig(['routes.middleware.clips' => ['api', EnsureFrontendRequestsAreStateful::class, 'auth:sanctum', 'throttle:scarlett-clips']]);
        $this->app->register(SanctumServiceProvider::class);
        config()->set('sanctum.stateful', ['spa.example.test']);
        T::createUsers();
        $this->usesMigrations();
        prepareClipApp();
        $this->user = T::user();
    });

    test('a stateful session cookie from the SPA origin gets 202', function (): void {
        $this->withSession(sessionLogin($this->user->id));

        T::post($this, T::payload(), ['HTTP_ORIGIN' => 'https://spa.example.test', 'HTTP_REFERER' => 'https://spa.example.test/watch'])
            ->assertStatus(202);
    });

    test('no session gets 401', function (): void {
        T::post($this, T::payload(), ['HTTP_ORIGIN' => 'https://spa.example.test', 'HTTP_REFERER' => 'https://spa.example.test/watch'])
            ->assertStatus(401);
    });

    test('with CSRF enforced, the SPA must send X-XSRF-TOKEN from the XSRF-TOKEN cookie: 419 without it', function (): void {
        $this->app->detectEnvironment(fn (): string => 'local');
        $this->withSession(['_token' => 'spa-csrf'] + sessionLogin($this->user->id));

        T::post($this, T::payload(), ['HTTP_ORIGIN' => 'https://spa.example.test', 'HTTP_REFERER' => 'https://spa.example.test/watch'])
            ->assertStatus(419);
        expect(Clip::query()->count())->toBe(0);
    });

    test('with CSRF enforced, the XSRF-TOKEN cookie value sent back as X-XSRF-TOKEN gets 202', function (): void {
        $this->app->detectEnvironment(fn (): string => 'local');
        $this->withSession(['_token' => 'spa-csrf'] + sessionLogin($this->user->id));

        // What GET /sanctum/csrf-cookie leaves in the XSRF-TOKEN cookie: the session
        // token, prefixed and encrypted. The SPA echoes the cookie value verbatim.
        $cookie = app('encrypter')->encrypt(CookieValuePrefix::create('XSRF-TOKEN', app('encrypter')->getKey()).'spa-csrf', false);

        T::post($this, T::payload(), [
            'HTTP_ORIGIN' => 'https://spa.example.test',
            'HTTP_REFERER' => 'https://spa.example.test/watch',
            'HTTP_X_XSRF_TOKEN' => $cookie,
        ])->assertStatus(202);
    });
});

describe('Sanctum token recipe', function (): void {
    beforeEach(function (): void {
        $this->withScarlettConfig(['routes.middleware.clips' => ['api', 'auth:sanctum']]);
        $this->app->register(SanctumServiceProvider::class);
        T::createUsers();
        $this->usesMigrations();
        prepareClipApp();
        $this->loadMigrationsFrom(dirname(__DIR__, 3).'/vendor/laravel/sanctum/database/migrations');
        $this->user = T::user();
        $this->token = $this->user->createToken('player')->plainTextToken;
    });

    test('a Bearer token gets 202 with no CSRF involved', function (): void {
        T::post($this, T::payload(), ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token])->assertStatus(202);

        expect(Clip::query()->sole()->user_id)->toBe($this->user->id);
    });

    test('no token gets 401', function (): void {
        T::post($this, T::payload())->assertStatus(401);
    });

    test('a wrong token gets 401', function (): void {
        T::post($this, T::payload(), ['HTTP_AUTHORIZATION' => 'Bearer 1|not-a-token'])->assertStatus(401);
    });

    test('the status route authenticates the token through the configured sanctum guard', function (): void {
        $clip = T::clip(['user_id' => $this->user->id]);

        $this->getJson(route('scarlett.clips.show', ['uuid' => $clip->uuid]), ['Authorization' => 'Bearer '.$this->token])
            ->assertOk()
            ->assertJson(['uuid' => $clip->uuid]);

        $this->app['auth']->forgetGuards();

        $this->getJson(route('scarlett.clips.show', ['uuid' => $clip->uuid]))->assertUnauthorized();
    });
});
