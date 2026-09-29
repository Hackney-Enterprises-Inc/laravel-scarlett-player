<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Data\BeaconPayload;
use Hei\ScarlettPlayer\Events\PlaybackErrorReported;
use Hei\ScarlettPlayer\Jobs\ProcessBeacon;
use Hei\ScarlettPlayer\Stores\EloquentBeaconStore;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\Beacons;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->usesMigrations();
    Event::fake([PlaybackErrorReported::class]);
});

it('deduplicates an old queued error and a fresh parse across the promotion upgrade', function (bool $freshFirst, bool $raw, bool $storedHash): void {
    config()->set('scarlett-player.beacons.store_raw_events', $raw);

    $identity = [
        'event' => 'error', 'timestamp' => Beacons::T0, 'viewId' => Beacons::VIEW,
        'sessionId' => 'session-1', 'viewerId' => 'viewer-1', 'videoId' => 'video-1',
    ];
    $body = array_replace($identity, [
        'tenant' => 'browser', 'beaconSeq' => 2, 'browser' => 'Chrome',
        'plan' => 'ppv', 'errorType' => 'network', 'seekSource' => 'player',
        'errorMessage' => 'failure', 'fatal' => true, 'last' => 'dimension',
    ]);
    // Explicit v0.2.1 normalization: context, old fields, then custom keys in
    // received order. Never derive the old hash from the current DTO's output.
    $context = ['browser' => 'Chrome'];
    $fields = ['errorType' => 'network', 'errorMessage' => 'failure', 'fatal' => true];
    $custom = ['tenant' => 'browser', 'beaconSeq' => 2, 'plan' => 'ppv', 'seekSource' => 'player', 'last' => 'dimension'];
    $legacyBody = array_replace($identity, $context, $fields, $custom);
    $hash = sha1((string) json_encode($legacyBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $state = array_replace($identity, ['context' => $context, 'fields' => $fields, 'custom' => $custom, 'ip' => null]);
    if ($storedHash) {
        $state['bodyHash'] = $hash;
    }
    $serialized = str_replace('O:8:"stdClass"', 'O:'.strlen(BeaconPayload::class).':"'.BeaconPayload::class.'"', serialize((object) $state));
    $old = unserialize($serialized);
    $fresh = BeaconPayload::fromArray($body);
    $eventKey = sha1(Beacons::VIEW.'error'.Beacons::T0.$hash);

    expect($old)->toBeInstanceOf(BeaconPayload::class)
        ->and($old->custom)->toBe($custom)
        ->and($old->fields)->toBe($fields)
        ->and($fresh->get('beaconSeq'))->toBe(2)
        ->and($fresh->get('seekSource'))->toBe('player')
        ->and(EloquentBeaconStore::eventKey($old))->toBe($eventKey)
        ->and(EloquentBeaconStore::eventKey($fresh))->toBe($eventKey);

    foreach ($freshFirst ? [$fresh, $old] : [$old, $fresh] as $payload) {
        app()->call([new ProcessBeacon($payload), 'handle']);
    }

    expect(DB::table('scarlett_views')->count())->toBe(1)
        ->and(DB::table('scarlett_beacon_events')->count())->toBe($raw ? 1 : 0)
        ->and(DB::table('scarlett_view_errors')->count())->toBe(1)
        ->and(DB::table('scarlett_view_errors')->sole()->event_key)->toBe($eventKey);

    if ($raw) {
        expect(DB::table('scarlett_beacon_events')->sole()->event_key)->toBe($eventKey);
    }

    Event::assertDispatchedTimes(PlaybackErrorReported::class, 1);
})->with(['old then fresh' => false, 'fresh then old' => true])
    ->with(['raw enabled' => true, 'raw disabled' => false])
    ->with(['old stored hash' => true, 'old missing hash' => false]);
