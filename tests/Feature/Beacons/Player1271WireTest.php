<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Data\BeaconPayload;
use Hei\ScarlettPlayer\Stores\EloquentBeaconStore;
use Illuminate\Support\Facades\DB;

const PLAYER_1271_KEY = 'wire-capture-key';

/** The full-session view of the 1.27.1 capture. */
const PLAYER_1271_VIEW = '1794244889820-s1q94rqib';

/** The `autoplay-blocked` scenario's view: a refused autoplay that never played. */
const PLAYER_1271_BLOCKED_VIEW = '1794244943338-27ur8ps9p';

/** The `stall-ceiling` scenario's view: played, then the stall ceiling ended it. */
const PLAYER_1271_STALLED_VIEW = '1794244945785-atblumeg9';

/*
 * The real 1.27.1 wire capture, replayed through the route. 1.27.1 changes no key and no
 * event on the wire (the analytics plugin is untouched), so this is the 1.27.0 contract
 * seen again at the new version. The native-reload error combination the release adds
 * cannot be captured (the harness runs MSE in Chromium): see Player1271Test.
 */

/** @return array<string, array<string, mixed>> */
function player1271Fixtures(): array
{
    $fixtures = [];
    foreach (glob(dirname(__DIR__, 2).'/Fixtures/wire/1.27.1/*.json') ?: [] as $path) {
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
    config()->set('scarlett-player.beacons.key', PLAYER_1271_KEY);
    $this->replay1271 = function (array $request) {
        $server = [];
        foreach ($request['headers'] as $name => $value) {
            $server[$name === 'content-type' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $name))] = (string) $value;
        }
        $query = (array) $request['query'];

        return $this->call('POST', $request['path'].($query === [] ? '' : '?'.http_build_query($query)), [], [], [], $server, (string) json_encode($request['body']));
    };
    $this->replayAll1271 = function (): void {
        $fixtures = player1271Fixtures();
        expect($fixtures)->toHaveCount(30);
        foreach ($fixtures as $fixture) {
            expect($fixture['request']['body'])->toHaveKey('sentAt');
            ($this->replay1271)($fixture['request'])->assertSuccessful();
        }

        $sequence = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/wire/1.27.1/_session.sequence.json'), true, 512, JSON_THROW_ON_ERROR);
        expect($sequence['beacons'])->toHaveCount(27);
        foreach ($sequence['beacons'] as $beacon) {
            ($this->replay1271)($beacon['request'])->assertSuccessful();
        }
    };
});

it('accepts captured 1.27.1 beacons and persists the full captured session', function (): void {
    ($this->replayAll1271)();

    $fixtures = player1271Fixtures();
    $view = DB::table('scarlett_views')->where('view_id', PLAYER_1271_VIEW)->sole();
    $ended = $fixtures['viewEnd.fetch.ended.json']['request']['body'];
    $custom = json_decode((string) $view->custom, true);

    expect($view->player_version)->toBe('1.27.1')
        ->and($view->start_state)->toBe('played')
        ->and((int) $view->qoe_version)->toBe(3)
        ->and((float) $view->completion_ratio)->toBe(1.0)
        ->and(abs((float) $view->rebuffer_fraction - ((float) $ended['rebufferRatio'] / 100)))->toBeLessThan(0.000000000001)
        ->and((int) $view->decoded_frames_total)->toBe((int) $ended['decodedFramesTotal'])
        ->and((int) $view->dropped_frames_total)->toBe((int) $ended['droppedFramesTotal'])
        ->and($custom)->toMatchArray(['tenant' => 'wire', 'gaugeScale' => 'percent'])
        ->and($custom)->not->toHaveKeys(['startState', 'stalled', 'sentAt']);
});

it('stores the captured refused autoplay as a raw playBlocked and an awaitingTap view', function (): void {
    ($this->replayAll1271)();

    $blocked = player1271Fixtures()['playBlocked.fetch.json']['request']['body'];
    $view = DB::table('scarlett_views')->where('view_id', PLAYER_1271_BLOCKED_VIEW)->sole();
    $custom = json_decode((string) $view->custom, true);
    $raw = DB::table('scarlett_beacon_events')->where('view_id', PLAYER_1271_BLOCKED_VIEW)->where('event', 'playBlocked')->sole();

    expect($view->start_state)->toBe('awaitingTap')
        ->and($view->exit_type)->toBe('abandoned')
        ->and((int) $view->qoe_version)->toBe(3)
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
    ($this->replayAll1271)();

    $view = DB::table('scarlett_views')->where('view_id', PLAYER_1271_STALLED_VIEW)->sole();
    $error = DB::table('scarlett_view_errors')->where('view_id', PLAYER_1271_STALLED_VIEW)->where('stalled', true)->sole();

    expect((bool) $error->stalled)->toBeTrue()
        ->and((bool) $error->reconnect_exhausted)->toBeTrue()
        ->and((bool) $error->fatal)->toBeTrue()
        ->and($view->start_state)->toBe('played')
        ->and($view->exit_type)->toBe('error')
        ->and((float) $view->qoe_score)->toBe(0.0);
});

it('stores one 1.27.1 beacon delivered twice with different dispatch times once', function (string $name): void {
    $request = player1271Fixtures()[$name]['request'];
    ($this->replay1271)($request)->assertSuccessful();
    $request['body']['sentAt'] += 1500;
    ($this->replay1271)($request)->assertSuccessful();

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

it('hashes a 1.27.1 beacon as the same body without sentAt', function (): void {
    foreach (player1271Fixtures() as $fixture) {
        $body = $fixture['request']['body'];
        $without = $body;
        unset($without['sentAt']);

        expect(EloquentBeaconStore::eventKey(BeaconPayload::fromArray($body)))
            ->toBe(EloquentBeaconStore::eventKey(BeaconPayload::fromArray($without)));
    }
});
