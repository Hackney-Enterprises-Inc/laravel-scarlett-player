<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Commands\BeaconTestCommand;
use Hei\ScarlettPlayer\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * scarlett:beacon:test and scarlett:views:prune with its schedule hook.
 */

it('posts viewStart, heartbeat and viewEnd through the header and the query key paths', function (): void {
    Http::fake(['*' => Http::response(status: 204)]);

    $this->artisan('scarlett:beacon:test', ['--url' => 'https://ingest.example.test/api/scarlett/beacons'])
        ->expectsOutputToContain('It does not prove browser CORS')
        ->expectsOutputToContain('Every beacon was answered 204.')
        ->assertSuccessful();

    Http::assertSentCount(6);

    $sent = Http::recorded()->map(fn (array $pair): Request => $pair[0]);
    $header = $sent->filter(fn (Request $request): bool => $request->header('X-API-Key') === [TestCase::BEACON_KEY]);
    $query = $sent->filter(fn (Request $request): bool => str_contains($request->url(), 'api_key='.TestCase::BEACON_KEY));

    expect($header->map(fn (Request $request): string => $request['event'])->values()->all())->toBe(['viewStart', 'heartbeat', 'viewEnd'])
        ->and($query->map(fn (Request $request): string => $request['event'])->values()->all())->toBe(['viewStart', 'heartbeat', 'viewEnd'])
        ->and($query->every(fn (Request $request): bool => $request->header('X-API-Key') === []))->toBeTrue()
        ->and($sent->every(fn (Request $request): bool => str_starts_with((string) $request['viewId'], BeaconTestCommand::VIEW_PREFIX)))->toBeTrue();
});

it('defaults to the scarlett.beacons.store route and warns when it is not https', function (): void {
    Http::fake(['*' => Http::response(status: 204)]);

    $this->artisan('scarlett:beacon:test')
        ->expectsOutputToContain('is not https')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/scarlett/beacons'));
});

it('fails when any beacon is not answered 204', function (): void {
    Http::fake(['*api_key=*' => Http::response(['message' => 'Invalid beacon key.'], 401), '*' => Http::response(status: 204)]);

    $this->artisan('scarlett:beacon:test', ['--url' => 'https://ingest.example.test/beacons'])
        ->expectsOutputToContain('One or more beacons were not answered 204.')
        ->assertFailed();
});

it('fails without calling anything when beacons.key is empty', function (): void {
    config()->set('scarlett-player.beacons.key', '');
    Http::fake();

    $this->artisan('scarlett:beacon:test')->expectsOutputToContain('SCARLETT_BEACON_KEY')->assertFailed();

    Http::assertNothingSent();
});

it('passes the real ingest route end to end', function (): void {
    // The command's own client posts to this app through the fake, so the route,
    // the key middleware and the validation all run.
    Http::fake(function (Request $request) {
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
});

it('prunes raw events, errors and views past their retention', function (): void {
    $this->usesMigrations();
    Date::setTestNow('2026-09-27 12:00:00');

    $old = '2026-06-01 00:00:00.000';
    $recent = '2026-09-26 00:00:00.000';

    foreach (['old' => $old, 'recent' => $recent] as $name => $at) {
        DB::table('scarlett_beacon_events')->insert([
            'view_id' => $name, 'event' => 'heartbeat', 'event_key' => sha1($name), 'occurred_at' => $at, 'payload' => '{}', 'received_at' => $at,
        ]);
        DB::table('scarlett_view_errors')->insert([
            'view_id' => $name, 'video_id' => 'v', 'event_key' => sha1($name), 'occurred_at' => $at, 'received_at' => $at,
        ]);
        DB::table('scarlett_views')->insert([
            'view_id' => $name, 'session_id' => 's', 'viewer_id' => 'x', 'video_id' => 'v', 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    // Views keep 365 days by default, so only the raw event is past retention.
    $this->artisan('scarlett:views:prune')->assertSuccessful();

    expect(DB::table('scarlett_beacon_events')->pluck('view_id')->all())->toBe(['recent'])
        ->and(DB::table('scarlett_views')->count())->toBe(2);

    config()->set('scarlett-player.beacons.retention.views', 30);
    $this->artisan('scarlett:views:prune')->assertSuccessful();

    expect(DB::table('scarlett_views')->pluck('view_id')->all())->toBe(['recent'])
        ->and(DB::table('scarlett_view_errors')->pluck('view_id')->all())->toBe(['recent']);

    // --days overrides the raw event retention; 0 clears everything received before now.
    $this->artisan('scarlett:views:prune', ['--days' => '0'])->assertSuccessful();

    expect(DB::table('scarlett_beacon_events')->count())->toBe(0);
});

it('keeps a table forever when its retention is null', function (): void {
    $this->usesMigrations();
    config()->set('scarlett-player.beacons.retention', ['events' => null, 'views' => null]);
    DB::table('scarlett_beacon_events')->insert([
        'view_id' => 'v', 'event' => 'e', 'event_key' => sha1('v'), 'occurred_at' => '2000-01-01 00:00:00.000', 'payload' => '{}', 'received_at' => '2000-01-01 00:00:00.000',
    ]);

    $this->artisan('scarlett:views:prune')->expectsOutputToContain('kept (no retention)')->assertSuccessful();

    expect(DB::table('scarlett_beacon_events')->count())->toBe(1);
});

it('refuses a --days that is not a whole number', function (): void {
    $this->artisan('scarlett:views:prune', ['--days' => 'ten'])->assertFailed();
});

it('schedules the prune daily, unless schedule_prune is off', function (bool $enabled, int $expected): void {
    $this->withScarlettConfig(['beacons.schedule_prune' => $enabled]);

    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'scarlett:views:prune'));

    expect($events)->toHaveCount($expected);

    if ($expected === 1) {
        expect($events->first()->expression)->toBe('0 0 * * *');
    }
})->with(['on' => [true, 1], 'off' => [false, 0]]);

it('registers both commands', function (): void {
    expect(array_keys(Artisan::all()))
        ->toContain('scarlett:beacon:test')
        ->toContain('scarlett:views:prune');
});
