<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Data\BeaconPayload;
use Hei\ScarlettPlayer\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
 * The player 1.19.1 wire fixtures, CAPTURED by the player repo's harness
 * (scripts/capture-wire-fixtures.mjs), replayed through the real route into the real
 * store. Each file is { fixture: {...}, request: { method, path, query,
 * headers, body } } with the browser's headers recorded verbatim; the replay sends
 * the method, path, query and body as recorded with the content-type, x-api-key and
 * x-wire-token headers, and drops the browser-only ones. The harness used the literal
 * key 'wire-capture-key', so the test configures that key rather than rewriting the
 * files. Nothing under tests/Fixtures/wire/1.19.1/ is edited but PROVENANCE.md: when
 * a captured body disagrees with the package, the package changes.
 */

const WIRE_SET = '1.19.1';
const WIRE_KEY = 'wire-capture-key';

/** Headers the replay keeps; everything else was the browser's own. */
const WIRE_REPLAY_HEADERS = ['content-type', 'x-api-key', 'x-wire-token'];

function wireDir(): string
{
    return dirname(__DIR__, 2).'/Fixtures/wire/'.WIRE_SET;
}

/**
 * @return array<string, mixed>
 */
function wireJson(string $file): array
{
    return json_decode((string) file_get_contents(wireDir().'/'.$file), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * The beacon fixtures: files whose request body has the beacon shape. The clip pair,
 * the preflight, the session sequence and the manifest are not beacons.
 *
 * @return array<string, array<string, mixed>>
 */
function wireBeacons(): array
{
    $beacons = [];

    foreach (glob(wireDir().'/*.json') ?: [] as $path) {
        $json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $body = $json['request']['body'] ?? null;

        if (is_array($body) && isset($body['event'], $body['viewId'], $body['timestamp'])) {
            $beacons[basename($path)] = $json;
        }
    }

    ksort($beacons);

    return $beacons;
}

/**
 * Replay one recorded request (a fixture file's `request`, or a session sequence entry's).
 *
 * @param  array<string, mixed>  $request
 */
function replayWire(TestCase $test, array $request): TestResponse
{
    $server = [];

    foreach ($request['headers'] as $name => $value) {
        $name = strtolower((string) $name);

        if (! in_array($name, WIRE_REPLAY_HEADERS, true)) {
            continue;
        }

        $server[$name === 'content-type' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $name))] = (string) $value;
    }

    $query = (array) ($request['query'] ?? []);
    $uri = $request['path'].($query === [] ? '' : '?'.http_build_query($query));

    return $test->call($request['method'], $uri, [], [], [], $server, (string) json_encode($request['body']));
}

/**
 * Header names of a recorded request, lower-cased.
 *
 * @param  array<string, mixed>  $request
 * @return list<string>
 */
function wireHeaderNames(array $request): array
{
    return array_map(fn (int|string $name): string => strtolower((string) $name), array_keys($request['headers']));
}

beforeEach(function (): void {
    $this->usesMigrations();
    config()->set('queue.default', 'sync');
    config()->set('scarlett-player.beacons.key', WIRE_KEY);
});

it('lists every captured file in PROVENANCE.md as captured, with the manifest assertions', function (): void {
    $provenance = (string) file_get_contents(wireDir().'/PROVENANCE.md');
    $manifest = wireJson('manifest.json');

    expect($manifest['player'])->toBe(WIRE_SET)
        ->and($provenance)->not->toContain('owed')
        ->and($provenance)->not->toContain('derived');

    foreach ($manifest['files'] as $file) {
        expect(is_file(wireDir().'/'.$file))->toBeTrue("[{$file}] is in the manifest but not on disk")
            ->and($provenance)->toMatch('/^\| `'.preg_quote($file, '/').'` \|.*\| captured \|$/m');
    }

    foreach ($manifest['assertions'] as $assertion) {
        // The harness labels may carry a player-board card key in parentheses, which
        // PROVENANCE.md (a file that ships) leaves out.
        $label = trim((string) preg_replace('/\s*\([A-Z]+-[A-Z]+-\d+[^)]*\)/', '', $assertion['name']));

        expect($assertion['ok'])->toBeTrue('harness assertion failed: '.$assertion['name'])
            ->and($provenance)->toContain($label);
    }
});

it('holds one fixture per event, transport and variant, from player 1.19.1', function (): void {
    $keys = [];

    foreach (wireBeacons() as $file => $json) {
        expect($json['fixture']['player'])->toBe(WIRE_SET)
            ->and($json['fixture']['transport'])->toBeIn(['fetch', 'sendBeacon'])
            ->and($json['request']['body']['event'])->toBe($json['fixture']['event'], $file)
            ->and($json['request']['body']['playerVersion'])->toBe(WIRE_SET);

        $keys[] = $json['fixture']['event'].'/'.$json['fixture']['transport'].'/'.($json['fixture']['variant'] ?? '-');
    }

    expect(wireBeacons())->toHaveCount(18)
        ->and($keys)->toBe(array_values(array_unique($keys)));
});

it('answers every captured beacon 204 on its own transport and stores it', function (string $file): void {
    $json = wireBeacons()[$file];

    replayWire($this, $json['request'])->assertNoContent();

    expect(DB::table('scarlett_beacon_events')->where('event', $json['fixture']['event'])->count())->toBe(1)
        ->and(DB::table('scarlett_views')->where('view_id', $json['request']['body']['viewId'])->exists())->toBeTrue();
})->with(fn (): array => array_keys(wireBeacons()));

it('carries the key only where the harness recorded it: the header on fetch, the query on sendBeacon', function (): void {
    foreach (wireBeacons() as $file => $json) {
        $request = $json['request'];
        $headers = wireHeaderNames($request);

        if ($json['fixture']['transport'] === 'fetch') {
            expect($headers)->toContain('x-api-key')
                ->and($request['query'])->not->toHaveKey('api_key');
        } else {
            expect($headers)->not->toContain('x-api-key')
                ->and($headers)->not->toContain('x-wire-token')
                ->and($request['query'])->toHaveKey('api_key');
        }
    }
});

it('refuses a captured beacon without its key: the header is what authenticates fetch', function (): void {
    $request = wireBeacons()['heartbeat.fetch.json']['request'];
    unset($request['headers']['x-api-key']);

    replayWire($this, $request)->assertUnauthorized();
});

it('sends the unload viewEnd as the subset of the ended one', function (): void {
    $unload = wireBeacons()['viewEnd.sendBeacon.unload.json']['request']['body'];
    $ended = wireBeacons()['viewEnd.fetch.ended.json']['request']['body'];

    expect(array_keys(array_diff_key($ended, $unload)))->toEqualCanonicalizing([
        'qoeScore', 'rebufferRatio', 'qualityChanges', 'pauseCount', 'pauseDuration', 'seekCount', 'errorCount', 'completionRate',
    ]);
});

it('stores every key the package does not know as a custom dimension, types intact, whatever the names', function (): void {
    $known = [...BeaconPayload::IDENTITY, ...array_keys(BeaconPayload::CONTEXT), ...array_keys(BeaconPayload::FIELDS)];

    foreach (wireBeacons() as $file => $json) {
        $body = $json['request']['body'];
        $expected = array_filter(
            array_diff_key($body, array_flip($known)),
            fn (mixed $value): bool => $value !== null,
        );

        expect(BeaconPayload::fromArray($body)->custom)->toBe($expected, $file);
    }
});

it('stores the ABR qualityChange with its bitrate and resolution', function (): void {
    $body = wireBeacons()['qualityChange.fetch.json']['request']['body'];

    replayWire($this, wireBeacons()['qualityChange.fetch.json']['request'])->assertNoContent();

    $raw = json_decode((string) DB::table('scarlett_beacon_events')->where('event', 'qualityChange')->value('payload'), true);

    expect($raw)->toMatchArray(array_intersect_key($body, array_flip(['bitrate', 'width', 'height', 'auto'])));
});

it('records the HLS fatal error, then the viewEnd with exitType error', function (): void {
    $error = wireBeacons()['error.fetch.json']['request'];
    $viewEnd = wireBeacons()['viewEnd.fetch.error.json']['request'];

    replayWire($this, $error)->assertNoContent();
    replayWire($this, $viewEnd)->assertNoContent();

    $stored = DB::table('scarlett_view_errors')->sole();
    $view = DB::table('scarlett_views')->where('view_id', $viewEnd['body']['viewId'])->sole();

    expect($stored->type)->toBe($error['body']['errorType'])
        ->and($stored->code)->toBe((string) $error['body']['errorCode'])
        ->and((bool) $stored->fatal)->toBe($error['body']['fatal'])
        ->and($view->exit_type)->toBe('error')
        ->and($view->ended_at)->not->toBeNull();
});

it('accepts an error beacon without errorCode, which 1.17.0 makes optional', function (): void {
    $request = wireBeacons()['error.fetch.json']['request'];
    unset($request['body']['errorCode']);

    replayWire($this, $request)->assertNoContent();

    expect(DB::table('scarlett_view_errors')->sole()->code)->toBeNull();
});

it('stores the live latency summary from the live heartbeat and the live unload viewEnd', function (string $file): void {
    $body = wireBeacons()[$file]['request']['body'];

    replayWire($this, wireBeacons()[$file]['request'])->assertNoContent();

    $view = DB::table('scarlett_views')->where('view_id', $body['viewId'])->sole();

    expect($body)->toHaveKeys(['liveLatencySamples', 'liveLatencyMean', 'liveLatencyP95', 'liveLatencyMax', 'lowLatency'])
        ->and((int) $view->live_latency_samples)->toBe((int) $body['liveLatencySamples'])
        ->and((float) $view->live_latency_mean)->toEqualWithDelta((float) $body['liveLatencyMean'], 0.0001)
        ->and((float) $view->live_latency_p95)->toEqualWithDelta((float) $body['liveLatencyP95'], 0.0001)
        ->and((float) $view->live_latency_max)->toEqualWithDelta((float) $body['liveLatencyMax'], 0.0001)
        ->and((bool) $view->low_latency)->toBe($body['lowLatency']);
})->with(['heartbeat.fetch.live.json', 'viewEnd.sendBeacon.live-unload.json']);

it('marks the live view live though its captured viewStart carries isLive null (absent; true wins), in either order', function (bool $reversed): void {
    $viewStart = wireBeacons()['viewStart.fetch.live.json']['request'];
    $heartbeat = wireBeacons()['heartbeat.fetch.live.json']['request'];

    // From 1.18 a viewStart sent before the playlist is read says null, not false.
    expect($viewStart['body'])->toHaveKey('isLive')
        ->and($viewStart['body']['isLive'])->toBeNull()
        ->and($heartbeat['body']['isLive'])->toBeTrue()
        ->and($viewStart['body']['viewId'])->toBe($heartbeat['body']['viewId']);

    foreach ($reversed ? [$heartbeat, $viewStart] : [$viewStart, $heartbeat] as $request) {
        replayWire($this, $request)->assertNoContent();
    }

    expect((bool) DB::table('scarlett_views')->where('view_id', $viewStart['body']['viewId'])->sole()->is_live)->toBeTrue();
})->with(['viewStart first' => false, 'heartbeat first' => true]);

it('merges the captured full session into one view, the same whichever order it arrives in', function (): void {
    $sequence = wireJson('_session.sequence.json');
    $beacons = array_column($sequence['beacons'], 'request');

    expect($sequence['fixture']['scenario'])->toBe('full-session')
        ->and($beacons)->toHaveCount(26)
        ->and($beacons)->toHaveCount(wireJson('manifest.json')['scenarios']['full-session']['beacons']);

    $replay = function (array $requests): array {
        DB::table('scarlett_views')->delete();
        DB::table('scarlett_beacon_events')->delete();
        DB::table('scarlett_view_errors')->delete();

        foreach ($requests as $request) {
            replayWire($this, $request)->assertNoContent();
        }

        // The order-independent classes: monotonic, true-wins, latest-by-timestamp,
        // custom. Set-once columns keep the first arrival by definition.
        return (array) DB::table('scarlett_views')->sole([
            'watch_ms', 'play_ms', 'rebuffer_ms', 'rebuffer_count', 'seek_count', 'max_bitrate', 'startup_ms',
            'qoe_score', 'avg_bitrate', 'current_position', 'is_live', 'last_event_at', 'custom',
        ]);
    };

    $inOrder = $replay($beacons);
    $reversed = $replay(array_reverse($beacons));

    $inOrder['custom'] = json_decode((string) $inOrder['custom'], true);
    $reversed['custom'] = json_decode((string) $reversed['custom'], true);

    expect($reversed)->toEqual($inOrder)
        ->and((int) $inOrder['watch_ms'])->toBeGreaterThan(0)
        ->and(DB::table('scarlett_beacon_events')->count())->toBe(26);
});
