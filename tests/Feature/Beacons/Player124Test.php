<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

const PLAYER_124_KEY = 'wire-capture-key';

/** @return array<string, array<string, mixed>> */
function player124Fixtures(): array
{
    $fixtures = [];
    foreach (glob(dirname(__DIR__, 2).'/Fixtures/wire/1.24.0/*.json') ?: [] as $path) {
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

/** @return array<string, array<string, mixed>> */
function player124DerivedFixtures(): array
{
    $fixtures = [];
    foreach (glob(dirname(__DIR__, 2).'/Fixtures/wire/1.24.0-derived/*.json') ?: [] as $path) {
        if (basename($path) === 'PROVENANCE.md') {
            continue;
        }
        $fixtures[basename($path)] = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    return $fixtures;
}

beforeEach(function (): void {
    $this->usesMigrations();
    config()->set('queue.default', 'sync');
    config()->set('scarlett-player.beacons.key', PLAYER_124_KEY);
    $this->replay124 = function (array $request) {
        $server = [];
        foreach ($request['headers'] as $name => $value) {
            $server[$name === 'content-type' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $name))] = (string) $value;
        }
        $query = (array) $request['query'];

        return $this->call('POST', $request['path'].($query === [] ? '' : '?'.http_build_query($query)), [], [], [], $server, (string) json_encode($request['body']));
    };
});

it('accepts captured 1.24.0 beacons and persists the full captured session', function (): void {
    $fixtures = player124Fixtures();
    expect($fixtures)->toHaveCount(26);
    foreach ($fixtures as $fixture) {
        ($this->replay124)($fixture['request'])->assertSuccessful();
    }

    $sequence = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/wire/1.24.0/_session.sequence.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($sequence['beacons'])->toHaveCount(27);
    foreach ($sequence['beacons'] as $beacon) {
        ($this->replay124)($beacon['request'])->assertSuccessful();
    }

    $view = DB::table('scarlett_views')->where('view_id', '1791898889301-cat14yyw6')->sole();
    $ended = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/wire/1.24.0/viewEnd.fetch.ended.json'), true, 512, JSON_THROW_ON_ERROR)['request']['body'];
    expect($view->player_version)->toBe('1.24.0')
        ->and((float) $view->completion_ratio)->toBe(1.0)
        ->and($view->completion_ratio_at)->not->toBeNull()
        ->and(abs((float) $view->rebuffer_fraction - ((float) $ended['rebufferRatio'] / 100)))->toBeLessThan(0.000000000001)
        ->and($view->rebuffer_fraction_at)->not->toBeNull()
        ->and((float) $view->completion_rate)->toBe((float) $ended['completionRate'])
        ->and(json_decode((string) $view->custom, true))->toMatchArray(['tenant' => 'wire', 'planTier' => 'free', 'experiment' => 42, 'beta' => true, 'gaugeScale' => 'percent'])
        ->and(DB::table('scarlett_beacon_events')->count())->toBeGreaterThan(0);
});

it('stores unavailable completion for the live-ended view with its availability stamp', function (): void {
    $fixture = player124Fixtures()['viewEnd.fetch.live-ended.json'];
    ($this->replay124)($fixture['request'])->assertSuccessful();
    $row = DB::table('scarlett_views')->where('view_id', $fixture['request']['body']['viewId'])->sole();

    expect($row->completion_ratio)->toBeNull()
        ->and($row->completion_ratio_at)->not->toBeNull()
        ->and($row->completion_rate)->toBeNull();
});

it('stores the startup failure view, fatal error and error exit type', function (): void {
    foreach (player124DerivedFixtures() as $name => $fixture) {
        if (str_starts_with($name, 'startup-failure.')) {
            ($this->replay124)($fixture['request'])->assertSuccessful();
        }
    }
    $viewId = 'derived-1.24-startup-failure';
    $view = DB::table('scarlett_views')->where('view_id', $viewId)->sole();

    expect($view->player_version)->toBe('1.24.0')
        ->and($view->startup_ms)->toBeNull()
        // Store merges absent monotonic playTime as no update; new rows use the schema default zero.
        ->and((int) $view->play_ms)->toBe(0)
        ->and($view->exit_type)->toBe('error')
        ->and((bool) DB::table('scarlett_view_errors')->where('view_id', $viewId)->sole()->fatal)->toBeTrue()
        ->and(DB::table('scarlett_beacon_events')->where('view_id', $viewId)->whereIn('event', ['playRequest', 'videoStart'])->count())->toBe(0);
});

it('accepts trailing seeking over sendBeacon query-key authentication', function (): void {
    $fixture = player124DerivedFixtures()['trailing-coalesced-seeking.sendBeacon.json'];
    expect($fixture['request']['query'])->toHaveKey('api_key')
        ->and($fixture['request']['headers'])->not->toHaveKey('x-api-key')
        ->and($fixture['request']['body']['seekSource'])->toBe('player');
    ($this->replay124)($fixture['request'])->assertSuccessful();
    expect(DB::table('scarlett_beacon_events')->where('event', 'seeking')->count())->toBe(1)
        ->and((int) DB::table('scarlett_views')->where('view_id', 'derived-1.24-trailing-seek')->value('seek_count'))->toBe(4);
});

it('allows seek_count to exceed stored seeking events', function (): void {
    $sequence = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/wire/1.24.0/_session.sequence.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ($sequence['beacons'] as $beacon) {
        $request = $beacon['request'];
        if (in_array($request['body']['event'] ?? null, ['heartbeat', 'viewEnd'], true) && ($request['body']['viewId'] ?? null) === '1791898889301-cat14yyw6') {
            $request['body']['seekCount'] = 12;
        }
        ($this->replay124)($request)->assertSuccessful();
    }
    $seeks = DB::table('scarlett_beacon_events')->where('view_id', '1791898889301-cat14yyw6')->where('event', 'seeking')->count();
    $view = DB::table('scarlett_views')->where('view_id', '1791898889301-cat14yyw6')->sole();

    expect((int) $view->seek_count)->toBeGreaterThan($seeks)
        ->and($seeks)->toBe(3);
});

it('stamps null rebuffer fraction and preserves the prior legacy wire value', function (): void {
    $captured = player124Fixtures();
    foreach (['heartbeat.fetch.json', 'viewEnd.fetch.ended.json'] as $name) {
        ($this->replay124)($captured[$name]['request'])->assertSuccessful();
    }
    $prior = DB::table('scarlett_views')->where('view_id', '1791898889301-cat14yyw6')->value('rebuffer_ratio');
    $fixture = player124DerivedFixtures()['viewEnd.fetch.null-rebuffer.json'];
    ($this->replay124)($fixture['request'])->assertSuccessful();
    $row = DB::table('scarlett_views')->where('view_id', '1791898889301-cat14yyw6')->sole();

    expect($row->rebuffer_fraction)->toBeNull()
        ->and(Carbon::parse($row->rebuffer_fraction_at)->format('Y-m-d H:i:s.v'))->toBe('2026-10-13 13:41:45.000')
        ->and(abs((float) $prior - 2.4599902159480047))->toBeLessThan(0.000000001)
        ->and((float) $row->rebuffer_ratio)->toBe((float) $prior);
});
