<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Contracts\BeaconStore;
use Hei\ScarlettPlayer\Data\BeaconPayload;
use Hei\ScarlettPlayer\Exceptions\InvalidBeaconStoreException;
use Hei\ScarlettPlayer\Facades\ScarlettPlayer;
use Hei\ScarlettPlayer\Jobs\ProcessBeacon;
use Hei\ScarlettPlayer\Stores\EloquentBeaconStore;
use Hei\ScarlettPlayer\Stores\NullBeaconStore;
use Hei\ScarlettPlayer\Testing\FakeBeaconStore;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\Beacons;
use Hei\ScarlettPlayer\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;

/*
 * beacons.store picks the bound BeaconStore; ScarlettPlayer::fake() swaps in a
 * recording one that fills the fake's beacon ledger.
 */

it('binds the store beacons.store names', function (mixed $store, string $class): void {
    $this->withScarlettConfig(['beacons.store' => $store]);

    expect(app(BeaconStore::class))->toBeInstanceOf($class);
})->with([
    'eloquent' => ['eloquent', EloquentBeaconStore::class],
    'null' => ['null', NullBeaconStore::class],
    'a host class' => [FakeBeaconStore::class, FakeBeaconStore::class],
    'unset' => [null, EloquentBeaconStore::class],
]);

it('refuses a beacons.store that is not a BeaconStore', function (string $store): void {
    $this->withScarlettConfig(['beacons.store' => $store]);

    app(BeaconStore::class);
})->with(['an unknown name' => 'redis', 'a class that is not a store' => stdClass::class])
    ->throws(InvalidBeaconStoreException::class);

it('discards everything in the null store', function (): void {
    $this->withScarlettConfig(['beacons.store' => 'null']);
    $this->usesMigrations();

    app()->call([new ProcessBeacon(Beacons::payload('viewStart')), 'handle']);

    expect(DB::table('scarlett_views')->count())->toBe(0)
        ->and(DB::table('scarlett_beacon_events')->count())->toBe(0);
});

it('records beacons posted through the route into the facade fake, with the sync queue', function (): void {
    config()->set('queue.default', 'sync');
    $fake = ScarlettPlayer::fake();

    $this->call('POST', '/api/scarlett/beacons?api_key='.TestCase::BEACON_KEY, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], (string) json_encode(Beacons::body('viewEnd', 0, ['exitType' => 'abandoned', 'plan' => 'ppv'])))->assertNoContent();

    $fake->assertBeaconRecorded()
        ->assertBeaconRecorded(fn (array $beacon): bool => $beacon['event'] === 'viewEnd' && $beacon['plan'] === 'ppv');

    expect(app(BeaconStore::class))->toBeInstanceOf(FakeBeaconStore::class)
        ->and($fake->recordedBeacons())->toHaveCount(1);
});

it('reports a missing beacon through the fake assertion', function (): void {
    $fake = ScarlettPlayer::fake();

    expect(fn () => $fake->assertBeaconRecorded())->toThrow(AssertionFailedError::class, 'No Scarlett beacon was recorded.');
});

it('asserts on what the FakeBeaconStore recorded', function (): void {
    $store = new FakeBeaconStore;
    $store->assertNothingRecorded();

    $store->record(Beacons::payload('viewStart'));
    $store->record(Beacons::payload('viewStart'));

    $store->assertRecorded()
        ->assertRecorded(fn (BeaconPayload $payload): bool => $payload->viewId === Beacons::VIEW)
        ->assertRecordedCount(2)
        ->assertRecordedCount(2, 'viewStart')
        ->assertRecordedCount(0, 'viewEnd');

    expect($store->recorded())->toHaveCount(2)
        ->and(fn () => $store->assertRecorded(fn (): bool => false))->toThrow(AssertionFailedError::class)
        ->and(fn () => $store->assertNothingRecorded())->toThrow(AssertionFailedError::class);
});
