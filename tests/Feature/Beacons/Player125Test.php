<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Data\BeaconPayload;
use Hei\ScarlettPlayer\Stores\EloquentBeaconStore;
use Illuminate\Support\Facades\DB;

const PLAYER_125_KEY = 'wire-capture-key';

const PLAYER_125_VIEW = '1791915129315-900kizszm';

/** @return array<string, array<string, mixed>> */
function player125Fixtures(): array
{
    $fixtures = [];
    foreach (glob(dirname(__DIR__, 2).'/Fixtures/wire/1.25.0/*.json') ?: [] as $path) {
        if (in_array(basename($path), ['manifest.json', '_session.sequence.json'], true)) {
            continue;
        }
        $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (isset($fixture['request']['body']['event'])) {
            $fixtures[basename($path)] = $fixture;
        }
    }
    ksort($fixtures);

    return $fixtures;
}

beforeEach(function (): void {
    $this->usesMigrations();
    config()->set('queue.default', 'sync');
    config()->set('scarlett-player.beacons.key', PLAYER_125_KEY);
    $this->replay125 = function (array $request) {
        $server = [];
        foreach ($request['headers'] as $name => $value) {
            $server[$name === 'content-type' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $name))] = (string) $value;
        }
        $query = (array) $request['query'];

        return $this->call('POST', $request['path'].($query === [] ? '' : '?'.http_build_query($query)), [], [], [], $server, (string) json_encode($request['body']));
    };
});

it('accepts captured 1.25.0 beacons and persists the full captured session', function (): void {
    $fixtures = player125Fixtures();
    expect($fixtures)->toHaveCount(26);
    foreach ($fixtures as $fixture) {
        expect($fixture['request']['body'])->toHaveKey('sentAt');
        ($this->replay125)($fixture['request'])->assertSuccessful();
    }

    $sequence = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/wire/1.25.0/_session.sequence.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($sequence['beacons'])->toHaveCount(27);
    foreach ($sequence['beacons'] as $beacon) {
        ($this->replay125)($beacon['request'])->assertSuccessful();
    }

    $view = DB::table('scarlett_views')->where('view_id', PLAYER_125_VIEW)->sole();
    $ended = $fixtures['viewEnd.fetch.ended.json']['request']['body'];
    $custom = json_decode((string) $view->custom, true);

    expect($view->player_version)->toBe('1.25.0')
        ->and((float) $view->completion_ratio)->toBe(1.0)
        ->and(abs((float) $view->rebuffer_fraction - ((float) $ended['rebufferRatio'] / 100)))->toBeLessThan(0.000000000001)
        // The frame totals are monotonic columns; neither they nor sentAt are custom.
        ->and((int) $view->decoded_frames_total)->toBe(397)
        ->and((int) $view->dropped_frames_total)->toBe(4)
        ->and($custom)->toMatchArray(['tenant' => 'wire', 'gaugeScale' => 'percent'])
        ->and($custom)->not->toHaveKeys(['sentAt', 'decodedFramesTotal', 'droppedFramesTotal']);

    // The raw log keeps the dispatch time.
    $raw = DB::table('scarlett_beacon_events')->where('view_id', PLAYER_125_VIEW)->where('event', 'viewEnd')->sole();
    expect(json_decode((string) $raw->payload, true)['sentAt'])->toBe($ended['sentAt']);
});

it('stores one beacon delivered twice with different dispatch times once', function (string $name): void {
    $request = player125Fixtures()[$name]['request'];
    ($this->replay125)($request)->assertSuccessful();
    $request['body']['sentAt'] += 1500;
    ($this->replay125)($request)->assertSuccessful();

    $viewId = $request['body']['viewId'];
    expect(DB::table('scarlett_beacon_events')->where('view_id', $viewId)->count())->toBe(1);

    if ($request['body']['event'] === 'error') {
        expect(DB::table('scarlett_view_errors')->where('view_id', $viewId)->count())->toBe(1);
    }
})->with(['error.fetch.json', 'heartbeat.fetch.json', 'viewEnd.sendBeacon.unload.json']);

it('hashes a 1.25.0 beacon as the same body without sentAt', function (): void {
    foreach (player125Fixtures() as $fixture) {
        $body = $fixture['request']['body'];
        $without = $body;
        unset($without['sentAt']);

        expect(EloquentBeaconStore::eventKey(BeaconPayload::fromArray($body)))
            ->toBe(EloquentBeaconStore::eventKey(BeaconPayload::fromArray($without)));
    }
});
