<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Data\BeaconPayload;
use Illuminate\Support\Facades\DB;

function signalCaptures(): array
{
    $captures = [];
    foreach (glob(__DIR__.'/../../Fixtures/wire/signals-candidate/*.json') ?: [] as $path) {
        $capture = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (isset($capture['request']['body']['event'])) {
            $captures[basename($path)] = $capture;
        }
    }

    return $captures;
}

beforeEach(function (): void {
    $this->usesMigrations();
    config()->set('queue.default', 'sync');
    config()->set('scarlett-player.beacons.key', 'wire-capture-key');
});

it('ingests each captured signals request without editing its body or transport', function (string $file): void {
    $request = signalCaptures()[$file]['request'];
    $server = [];
    foreach ($request['headers'] as $key => $value) {
        $key = strtolower($key);
        if (in_array($key, ['content-type', 'x-api-key', 'x-wire-token'], true)) {
            $server[$key === 'content-type' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $key))] = $value;
        }
    }
    $uri = $request['path'].($request['query'] ? '?'.http_build_query($request['query']) : '');
    foreach ([1, 2] as $delivery) {
        $this->call($request['method'], $uri, [], [], [], $server, json_encode($request['body']))->assertNoContent();
    }
    $stored = json_decode(DB::table('scarlett_beacon_events')->sole()->payload, true);
    $payload = BeaconPayload::fromArray($request['body']);
    expect($stored)->toEqual($payload->toArray());
    foreach (['qoeVersion', 'errorCategory', 'anonymous', 'pageUrl', 'segmentCount', 'decodedFrames'] as $field) {
        if (isset($request['body'][$field])) {
            expect($payload->has($field))->toBeTrue()->and($payload->custom)->not->toHaveKey($field);
        }
    }
})->with(fn (): array => array_keys(signalCaptures()));

it('records captured provenance and the measured signals assertions', function (): void {
    $directory = __DIR__.'/../../Fixtures/wire/signals-candidate/';
    $manifest = json_decode(file_get_contents($directory.'manifest.json'), true);
    $provenance = file_get_contents($directory.'PROVENANCE.md');
    expect(count(signalCaptures()))->toBeGreaterThan(18);
    foreach ($manifest['files'] as $file) {
        expect($provenance)->toContain('`'.$file.'`')->and(is_file($directory.$file))->toBeTrue();
    }
    foreach ($manifest['assertions'] as $assertion) {
        expect($assertion['ok'])->toBeTrue($assertion['name']);
    }
});
