<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Data\BeaconPayload;
use Hei\ScarlettPlayer\Stores\EloquentBeaconStore;
use Illuminate\Support\Facades\DB;

const PLAYER_127_KEY = 'wire-capture-key';

/** The full-session view of the 1.27.0 capture. */
const PLAYER_127_VIEW = '1793623719800-zlfr1xyvq';

/** The `autoplay-blocked` scenario's view: a refused autoplay that never played. */
const PLAYER_127_BLOCKED_VIEW = '1793623773856-bv2qu045a';

/** The `stall-ceiling` scenario's view: played, then the stall ceiling ended it. */
const PLAYER_127_STALLED_VIEW = '1793623776307-tgeaf37y8';

/** @return array<string, array<string, mixed>> */
function player127Fixtures(): array
{
    $fixtures = [];
    foreach (glob(dirname(__DIR__, 2).'/Fixtures/wire/1.27.0/*.json') ?: [] as $path) {
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
    config()->set('scarlett-player.beacons.key', PLAYER_127_KEY);
    $this->replay127 = function (array $request) {
        $server = [];
        foreach ($request['headers'] as $name => $value) {
            $server[$name === 'content-type' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $name))] = (string) $value;
        }
        $query = (array) $request['query'];

        return $this->call('POST', $request['path'].($query === [] ? '' : '?'.http_build_query($query)), [], [], [], $server, (string) json_encode($request['body']));
    };
    $this->replayAll127 = function (): void {
        $fixtures = player127Fixtures();
        expect($fixtures)->toHaveCount(30);
        foreach ($fixtures as $fixture) {
            expect($fixture['request']['body'])->toHaveKey('sentAt');
            ($this->replay127)($fixture['request'])->assertSuccessful();
        }

        $sequence = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/wire/1.27.0/_session.sequence.json'), true, 512, JSON_THROW_ON_ERROR);
        expect($sequence['beacons'])->toHaveCount(27);
        foreach ($sequence['beacons'] as $beacon) {
            ($this->replay127)($beacon['request'])->assertSuccessful();
        }
    };
});

it('accepts captured 1.27.0 beacons and persists the full captured session', function (): void {
    ($this->replayAll127)();

    $fixtures = player127Fixtures();
    $view = DB::table('scarlett_views')->where('view_id', PLAYER_127_VIEW)->sole();
    $ended = $fixtures['viewEnd.fetch.ended.json']['request']['body'];
    $custom = json_decode((string) $view->custom, true);

    expect($view->player_version)->toBe('1.27.0')
        ->and($view->start_state)->toBe('played')
        ->and((int) $view->qoe_version)->toBe(3)
        ->and((float) $view->completion_ratio)->toBe(1.0)
        ->and(abs((float) $view->rebuffer_fraction - ((float) $ended['rebufferRatio'] / 100)))->toBeLessThan(0.000000000001)
        ->and((int) $view->decoded_frames_total)->toBe(396)
        ->and((int) $view->dropped_frames_total)->toBe(2)
        // Known fields, so neither the start state nor sentAt is a custom dimension.
        ->and($custom)->toMatchArray(['tenant' => 'wire', 'gaugeScale' => 'percent'])
        ->and($custom)->not->toHaveKeys(['startState', 'stalled', 'sentAt']);
});

it('stores the captured refused autoplay as a raw playBlocked and an awaitingTap view', function (): void {
    ($this->replayAll127)();

    $blocked = player127Fixtures()['playBlocked.fetch.json']['request']['body'];
    $view = DB::table('scarlett_views')->where('view_id', PLAYER_127_BLOCKED_VIEW)->sole();
    $custom = json_decode((string) $view->custom, true);
    $raw = DB::table('scarlett_beacon_events')->where('view_id', PLAYER_127_BLOCKED_VIEW)->where('event', 'playBlocked')->sole();

    expect($view->start_state)->toBe('awaitingTap')
        ->and($view->exit_type)->toBe('abandoned')
        ->and((int) $view->qoe_version)->toBe(3)
        // A view that never asked to play, or is waiting for a tap, has no score.
        ->and($view->qoe_score)->toBeNull()
        ->and($view->first_frame_at)->toBeNull()
        ->and($custom)->toMatchArray(['reason' => 'autoplay', 'errorName' => 'NotAllowedError'])
        ->and(json_decode((string) $raw->payload, true))->toMatchArray([
            'reason' => 'autoplay',
            'errorName' => 'NotAllowedError',
            'readyState' => $blocked['readyState'],
        ]);
});

it('stores the captured stall ceiling as a stalled error row and a played view that errored', function (): void {
    ($this->replayAll127)();

    $view = DB::table('scarlett_views')->where('view_id', PLAYER_127_STALLED_VIEW)->sole();
    $error = DB::table('scarlett_view_errors')->where('view_id', PLAYER_127_STALLED_VIEW)->where('stalled', true)->sole();

    expect((bool) $error->stalled)->toBeTrue()
        ->and((bool) $error->reconnect_exhausted)->toBeTrue()
        ->and((bool) $error->fatal)->toBeTrue()
        ->and($view->start_state)->toBe('played')
        ->and($view->exit_type)->toBe('error')
        // An error exit scores 0 under QoE v3.
        ->and($view->qoe_score)->not->toBeNull()
        ->and((float) $view->qoe_score)->toBe(0.0);
});

it('stores one beacon delivered twice with different dispatch times once', function (string $name): void {
    $request = player127Fixtures()[$name]['request'];
    ($this->replay127)($request)->assertSuccessful();
    $request['body']['sentAt'] += 1500;
    ($this->replay127)($request)->assertSuccessful();

    $viewId = $request['body']['viewId'];
    expect(DB::table('scarlett_beacon_events')->where('view_id', $viewId)->count())->toBe(1);

    if ($request['body']['event'] === 'error') {
        expect(DB::table('scarlett_view_errors')->where('view_id', $viewId)->count())->toBe(1);
    }
})->with([
    'error.fetch.json',
    'heartbeat.fetch.json',
    'viewEnd.sendBeacon.unload.json',
    'playBlocked.fetch.json',
    'error.fetch.stalled.json',
    'viewEnd.fetch.awaiting-tap.json',
]);

it('hashes a 1.27.0 beacon as the same body without sentAt', function (): void {
    foreach (player127Fixtures() as $fixture) {
        $body = $fixture['request']['body'];
        $without = $body;
        unset($without['sentAt']);

        expect(EloquentBeaconStore::eventKey(BeaconPayload::fromArray($body)))
            ->toBe(EloquentBeaconStore::eventKey(BeaconPayload::fromArray($without)));
    }
});
