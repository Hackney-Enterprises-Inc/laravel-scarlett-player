<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Contracts\BeaconStore;
use Hei\ScarlettPlayer\Contracts\ProcessesBeacon;
use Hei\ScarlettPlayer\Data\BeaconPayload;
use Hei\ScarlettPlayer\Events\BeaconReceived;
use Hei\ScarlettPlayer\Exceptions\InvalidBeaconPipelineException;
use Hei\ScarlettPlayer\Jobs\ProcessBeacon;
use Hei\ScarlettPlayer\Testing\FakeBeaconStore;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\Beacons;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\DropHeartbeats;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\RedactEmail;
use Hei\ScarlettPlayer\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

/*
 * The job, the ProcessesBeacon hook, BeaconReceived and the privacy switches.
 */

beforeEach(function (): void {
    $this->store = new FakeBeaconStore;
    app()->instance(BeaconStore::class, $this->store);

    $this->run = fn (BeaconPayload $payload) => app()->call([new ProcessBeacon($payload), 'handle']);
});

it('has the coordinated timeout, tries and backoff', function (): void {
    $job = new ProcessBeacon(Beacons::payload('heartbeat'));

    expect($job->timeout)->toBe(30)
        ->and($job->tries)->toBe(5)
        ->and($job->backoff())->toBe([5, 30, 120]);
});

it('hands the payload to the bound store', function (): void {
    ($this->run)(Beacons::payload('viewStart'));

    $this->store->assertRecordedCount(1, 'viewStart');
});

it('fires BeaconReceived on every delivery, duplicates included', function (): void {
    Event::fake([BeaconReceived::class]);
    $payload = Beacons::payload('heartbeat');

    ($this->run)($payload);
    ($this->run)($payload);

    Event::assertDispatchedTimes(BeaconReceived::class, 2);
});

it('runs beacons.pipeline steps in order and lets one redact before storage', function (): void {
    config()->set('scarlett-player.beacons.pipeline', [RedactEmail::class]);

    ($this->run)(Beacons::payload('heartbeat', 0, ['email' => 'viewer@example.com', 'plan' => 'ppv']));

    $this->store->assertRecorded(fn (BeaconPayload $payload): bool => $payload->custom === ['plan' => 'ppv']);
});

it('drops a beacon a step returns null for', function (): void {
    config()->set('scarlett-player.beacons.pipeline', [RedactEmail::class, DropHeartbeats::class]);

    ($this->run)(Beacons::payload('heartbeat'));
    ($this->run)(Beacons::payload('pause', 1, ['currentTime' => 1.5]));

    $this->store->assertRecordedCount(1)->assertRecordedCount(0, 'heartbeat');
});

it('refuses a pipeline step that does not implement ProcessesBeacon', function (): void {
    config()->set('scarlett-player.beacons.pipeline', [stdClass::class]);

    ($this->run)(Beacons::payload('heartbeat'));
})->throws(InvalidBeaconPipelineException::class, 'stdClass');

it('keeps the redacted payload in the raw event log, not the original', function (): void {
    $this->usesMigrations();
    app()->forgetInstance(BeaconStore::class);
    config()->set('scarlett-player.beacons.pipeline', [RedactEmail::class]);

    ($this->run)(Beacons::payload('viewStart', 0, ['email' => 'viewer@example.com']));

    expect((string) DB::table('scarlett_beacon_events')->value('payload'))->not->toContain('viewer@example.com')
        ->and((string) DB::table('scarlett_views')->value('custom'))->not->toContain('viewer@example.com');
});

it('stores the anonymised address end to end when store_ip is on', function (): void {
    config()->set('scarlett-player.beacons.store_ip', true);
    $this->usesMigrations();
    app()->forgetInstance(BeaconStore::class);

    $this->call('POST', '/api/scarlett/beacons', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_API_KEY' => TestCase::BEACON_KEY,
        'REMOTE_ADDR' => '2001:db8:85a3::8a2e:370:7334',
    ], (string) json_encode(Beacons::body('viewStart')))->assertNoContent();

    expect(DB::table('scarlett_views')->value('ip_address'))->toBe('2001:db8:85a3::');
});

it('writes no address when store_ip is off, even if a step supplies one', function (): void {
    $this->usesMigrations();
    app()->forgetInstance(BeaconStore::class);

    ($this->run)(Beacons::payload('viewStart')->withIp('203.0.113.1'));

    expect(Schema::hasColumn('scarlett_views', 'ip_address'))->toBeFalse()
        ->and(DB::table('scarlett_views')->count())->toBe(1);
});

it('fires BeaconReceived after the pipeline, with the redacted payload, and never for a dropped beacon', function (): void {
    Event::fake([BeaconReceived::class]);
    config()->set('scarlett-player.beacons.pipeline', [RedactEmail::class, DropHeartbeats::class]);

    ($this->run)(Beacons::payload('pause', 1, ['currentTime' => 1.0, 'email' => 'viewer@example.com']));
    ($this->run)(Beacons::payload('heartbeat', 2, ['email' => 'viewer@example.com']));

    Event::assertDispatchedTimes(BeaconReceived::class, 1);
    Event::assertDispatched(BeaconReceived::class, fn (BeaconReceived $event): bool => $event->payload->event === 'pause'
        && ! array_key_exists('email', $event->payload->custom));
});
