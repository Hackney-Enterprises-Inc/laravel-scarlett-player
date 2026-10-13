<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Contracts\BeaconStore;
use Hei\ScarlettPlayer\Data\BeaconPayload;
use Hei\ScarlettPlayer\Jobs\ProcessBeacon;
use Hei\ScarlettPlayer\Stores\NullBeaconStore;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\Beacons;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

const INSPECT_MIGRATION = __DIR__.'/../../../database/migrations/0001_01_01_000008_add_diagnostics_to_scarlett_tables.php';

beforeEach(function (): void {
    $this->usesMigrations();
    config()->set('queue.default', 'sync');
    Date::setTestNow('2026-10-13 18:00:00');
    $this->deliver = function (BeaconPayload $payload): void {
        app()->call([new ProcessBeacon($payload), 'handle']);
    };

    // One troubled view for user 4182: a reconnecting error, frame drops, a viewEnd
    // sent 2.5s before the server received it by the viewer's clock.
    $received = Date::now()->getTimestampMs();
    foreach ([
        Beacons::payload('viewStart', 0, ['sentAt' => $received - 2400]),
        Beacons::payload('heartbeat', 10, ['watchTime' => 9000, 'rebufferCount' => 2, 'rebufferDuration' => 1500, 'decodedFramesTotal' => 400, 'droppedFramesTotal' => 40, 'sentAt' => $received - 2500]),
        Beacons::payload('error', 20, ['errorCode' => 'NETWORK_ERROR', 'errorMessage' => 'Segment failed', 'errorSeverity' => 'warning', 'errorCategory' => 'network', 'httpStatus' => 403, 'sourceHost' => 'cdn.example.test', 'reconnecting' => true, 'fatal' => true]),
        Beacons::payload('viewEnd', 30, ['exitType' => 'error', 'watchTime' => 9500, 'decodedFramesTotal' => 410, 'droppedFramesTotal' => 41]),
    ] as $payload) {
        ($this->deliver)($payload->withServer(['user_id' => 4182]));
    }

    ($this->deliver)(Beacons::payload('viewStart', 0, [], 'other-view')->withServer(['user_id' => 7]));
});

afterEach(function (): void {
    Date::setTestNow();
});

it('shows a viewer\'s views with errors, frame drops and the timeline', function (): void {
    expect(Artisan::call('scarlett:views:inspect', ['--ref' => '4182']))->toBe(0);
    $output = Artisan::output();

    expect($output)->toContain('View '.Beacons::VIEW, '41 of 410 (10%)', '2 (1.5s)', 'cdn.example.test', 'warning, reconnecting', 'Segment failed', 'exitType=error')
        ->toContain('Stored minus sentAt, median +2.450s over 2 beacons')
        ->toContain('| +2.400s')
        ->not->toContain('other-view');
});

it('finds views by view id, viewer id and video, and prints JSON', function (): void {
    foreach ([['view' => Beacons::VIEW], ['--viewer' => 'viewer-1', '--video' => 'video-1']] as $selector) {
        Artisan::call('scarlett:views:inspect', [...$selector, '--json' => true]);
        $report = json_decode(Artisan::output(), true);

        $views = array_column(array_column($report, 'view'), 'view_id');
        expect($views)->toContain(Beacons::VIEW);
    }

    Artisan::call('scarlett:views:inspect', ['view' => Beacons::VIEW, '--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($report)->toHaveCount(1)
        ->and($report[0]['view']['viewer_ref'])->toBe('4182')
        ->and($report[0]['view']['server'])->toBe(['user_id' => 4182])
        ->and($report[0]['errors'])->toHaveCount(1)
        ->and(array_column($report[0]['events'], 'event'))->toBe(['viewStart', 'heartbeat', 'error', 'viewEnd'])
        ->and($report[0]['events'][0]['lag_ms'])->toBe(2400)
        ->and($report[0]['events'][1]['lag_ms'])->toBe(2500)
        ->and($report[0]['events'][2]['lag_ms'])->toBeNull();
});

it('limits views and timeline rows, and filters by recent activity', function (): void {
    Artisan::call('scarlett:views:inspect', ['--video' => 'video-1', '--limit' => '1', '--json' => true]);
    expect(array_column(array_column(json_decode(Artisan::output(), true), 'view'), 'view_id'))->toBe(['other-view']);

    Artisan::call('scarlett:views:inspect', ['--ref' => '4182', '--events' => '2', '--json' => true]);
    expect(json_decode(Artisan::output(), true)[0]['events'])->toHaveCount(2);

    Artisan::call('scarlett:views:inspect', ['--ref' => '4182', '--events' => '0', '--json' => true]);
    expect(json_decode(Artisan::output(), true)[0]['events'])->toBe([]);

    DB::table('scarlett_views')->where('view_id', 'other-view')->update(['updated_at' => '2026-10-01 00:00:00.000']);
    Artisan::call('scarlett:views:inspect', ['--video' => 'video-1', '--since' => '24h', '--json' => true]);
    expect(array_column(array_column(json_decode(Artisan::output(), true), 'view'), 'view_id'))->toBe([Beacons::VIEW]);

    Artisan::call('scarlett:views:inspect', ['--video' => 'video-1', '--since' => '2026-09-30', '--json' => true]);
    expect(json_decode(Artisan::output(), true))->toHaveCount(2);
});

it('says when nothing matches', function (): void {
    $this->artisan('scarlett:views:inspect', ['--ref' => 'nobody'])
        ->expectsOutputToContain('No views match.')
        ->assertSuccessful();

    expect(Artisan::call('scarlett:views:inspect', ['--ref' => 'nobody', '--json' => true]))->toBe(0)
        ->and(json_decode(Artisan::output(), true))->toBe([]);
});

it('keeps the newest events when --events cuts the timeline, in client order', function (): void {
    Artisan::call('scarlett:views:inspect', ['view' => Beacons::VIEW, '--events' => '1', '--json' => true]);
    expect(array_column(json_decode(Artisan::output(), true)[0]['events'], 'event'))->toBe(['viewEnd']);

    $this->artisan('scarlett:views:inspect', ['view' => Beacons::VIEW, '--events' => '1'])
        ->expectsOutputToContain('Timeline (last 1, raise --events for more)')
        ->assertSuccessful();
});

it('orders events stamped in the same millisecond by seq, not arrival', function (): void {
    $occurred = DB::table('scarlett_beacon_events')->where('view_id', Beacons::VIEW)->where('event', 'viewEnd')->value('occurred_at');
    DB::table('scarlett_beacon_events')->where('view_id', Beacons::VIEW)->where('event', 'heartbeat')->update(['occurred_at' => $occurred]);
    DB::table('scarlett_beacon_events')->where('view_id', Beacons::VIEW)->where('event', 'viewEnd')->update(['seq' => 1]);
    DB::table('scarlett_beacon_events')->where('view_id', Beacons::VIEW)->where('event', 'heartbeat')->update(['seq' => 2]);

    Artisan::call('scarlett:views:inspect', ['view' => Beacons::VIEW, '--json' => true]);
    expect(array_slice(array_column(json_decode(Artisan::output(), true)[0]['events'], 'event'), -2))->toBe(['viewEnd', 'heartbeat']);
});

it('prints a view from a table without the server columns', function (): void {
    DB::statement('alter table scarlett_views drop column server_stamps');
    DB::statement('alter table scarlett_views drop column server');

    $this->artisan('scarlett:views:inspect', ['view' => Beacons::VIEW])
        ->expectsOutputToContain('View '.Beacons::VIEW)
        ->assertSuccessful();
});

it('names the raw log settings that hide the timeline', function (): void {
    config()->set('scarlett-player.beacons.raw_events_except', ['heartbeat']);
    $this->artisan('scarlett:views:inspect', ['view' => Beacons::VIEW])
        ->expectsOutputToContain('Not in the raw log (beacons.raw_events_except): heartbeat.')
        ->assertSuccessful();

    config()->set('scarlett-player.beacons.store_raw_events', false);
    $this->artisan('scarlett:views:inspect', ['view' => Beacons::VIEW])
        ->expectsOutputToContain('No timeline: beacons.store_raw_events is off.')
        ->assertSuccessful();
});

it('refuses bad input', function (array $arguments, string $message): void {
    $this->artisan('scarlett:views:inspect', $arguments)
        ->expectsOutputToContain($message)
        ->assertFailed();
})->with([
    'no selector' => [[], 'Give a view id, --ref, --viewer or --video.'],
    'blank selector' => [['--ref' => ' '], 'Give a view id, --ref, --viewer or --video.'],
    'zero limit' => [['--ref' => '1', '--limit' => '0'], '--limit takes a whole number above 0'],
    'bad events' => [['--ref' => '1', '--events' => 'all'], '--limit takes a whole number above 0'],
    'bad since' => [['--ref' => '1', '--since' => 'last tuesday-ish'], '--since takes a duration'],
]);

it('asks for the diagnostics migration before searching by --ref', function (): void {
    $migration = require INSPECT_MIGRATION;
    $migration->down();

    try {
        $this->artisan('scarlett:views:inspect', ['--ref' => '4182'])
            ->expectsOutputToContain('--tag=scarlett-migrations-diagnostics')
            ->assertFailed();

        // Every other selector still works on the older schema.
        $this->artisan('scarlett:views:inspect', ['view' => Beacons::VIEW])
            ->expectsOutputToContain('View '.Beacons::VIEW)
            ->assertSuccessful();
    } finally {
        $migration->up();
    }
});

it('refuses a store other than the Eloquent one', function (): void {
    app()->instance(BeaconStore::class, new NullBeaconStore);

    $this->artisan('scarlett:views:inspect', ['--ref' => '4182'])
        ->expectsOutputToContain('not the Eloquent store')
        ->assertFailed();
});
