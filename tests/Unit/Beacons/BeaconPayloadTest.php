<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Data\BeaconPayload;
use Hei\ScarlettPlayer\Exceptions\InvalidBeaconContextException;
use Hei\ScarlettPlayer\Facades\ScarlettPlayer;
use Hei\ScarlettPlayer\Jobs\ProcessBeacon;
use Hei\ScarlettPlayer\Stores\EloquentBeaconStore;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\Beacons;

/**
 * The v0.1.0 shape of BeaconPayload, for a job queued before an upgrade. Serialized,
 * then renamed to BeaconPayload in the string, it is exactly what an older worker put
 * on the queue.
 */
final class LegacyBeaconPayloadForUnserializeTest
{
    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $custom
     */
    public function __construct(
        public string $event,
        public int $timestamp,
        public string $viewId,
        public string $sessionId,
        public string $viewerId,
        public string $videoId,
        public array $context = [],
        public array $fields = [],
        public array $custom = [],
        public ?string $ip = null,
    ) {}
}

/*
 * BeaconPayload splits a body into identity, known context, known event fields and
 * custom dimensions.
 */

it('splits identity, context, event fields and custom dimensions', function (): void {
    $payload = BeaconPayload::fromArray(Beacons::body('heartbeat', 0, [
        'watchTime' => 10_000,
        'qoeScore' => 92.5,
        'plan' => 'ppv',
        'tenant' => 7,
    ]), '203.0.113.0');

    expect($payload->event)->toBe('heartbeat')
        ->and($payload->timestamp)->toBe(Beacons::T0)
        ->and($payload->viewId)->toBe(Beacons::VIEW)
        ->and($payload->context['playerVersion'])->toBe('1.19.1')
        ->and($payload->context['isLive'])->toBeFalse()
        ->and($payload->fields)->toBe(['watchTime' => 10_000, 'qoeScore' => 92.5])
        ->and($payload->custom)->toBe(['plan' => 'ppv', 'tenant' => 7])
        ->and($payload->ip)->toBe('203.0.113.0');
});

it('treats a known key sent as null as absent', function (): void {
    $payload = BeaconPayload::fromArray(Beacons::body('viewEnd', 0, ['startupTime' => null, 'videoTitle' => null]));

    expect($payload->has('startupTime'))->toBeFalse()
        ->and($payload->get('startupTime'))->toBeNull()
        ->and($payload->has('videoTitle'))->toBeFalse()
        ->and($payload->toArray())->not->toHaveKey('startupTime');
});

it('drops a custom dimension sent as null: present-but-null is absent for custom keys too', function (): void {
    $payload = BeaconPayload::fromArray(Beacons::body('heartbeat', 0, ['campaign' => null, 'plan' => 'ppv']));

    expect($payload->custom)->toBe(['plan' => 'ppv']);
});

it('moves a known name carrying another type into custom, where a host dimension of that name belongs', function (): void {
    // The player spreads customDimensions before the event data, so on a pause (which
    // sends no duration) a host dimension `duration: 'long'` arrives under a known name.
    $payload = BeaconPayload::fromArray(Beacons::body('pause', 0, ['currentTime' => 3.5, 'duration' => 'long', 'fatal' => 'no']));

    expect($payload->fields)->toBe(['currentTime' => 3.5])
        ->and($payload->custom)->toBe(['duration' => 'long', 'fatal' => 'no']);
});

it('round-trips to the stored array with custom dimensions spread at the top level', function (): void {
    $body = Beacons::body('pause', 5, ['currentTime' => 12.5, 'plan' => 'free']);

    expect(BeaconPayload::fromArray($body)->toArray())->toEqualCanonicalizing($body);
});

it('replaces custom dimensions and the address without touching anything else', function (): void {
    $payload = BeaconPayload::fromArray(Beacons::body('heartbeat', 0, ['email' => 'viewer@example.com']), '203.0.113.9');

    $redacted = $payload->withCustom([])->withIp(null);

    expect($redacted->custom)->toBe([])
        ->and($redacted->ip)->toBeNull()
        ->and($redacted->viewId)->toBe($payload->viewId)
        ->and($redacted->context)->toBe($payload->context)
        ->and($payload->custom)->toBe(['email' => 'viewer@example.com']);
});

it('knows every key the analytics plugin sends through player 1.19.3', function (): void {
    // index.ts sendBeacon()/sendUnloadBeacon() base keys, then every data object
    // the plugin passes (heartbeat, videoStart, rebufferEnd, pause, rebufferStart,
    // seeking, error, qualityChange, both viewEnd variants, the latency summary).
    $shipped = [
        'videoTitle', 'isLive', 'playerVersion', 'playerName', 'browser', 'os', 'deviceType',
        'screenSize', 'playerSize', 'connectionType', 'watchTime', 'playTime', 'currentTime',
        'duration', 'rebufferCount', 'rebufferDuration', 'avgBitrate', 'qoeScore', 'startupTime',
        'totalRebufferTime', 'seekCount', 'seekTo', 'errorType', 'errorMessage', 'errorCode',
        'fatal', 'bitrate', 'width', 'height', 'auto', 'rebufferRatio', 'maxBitrate',
        'qualityChanges', 'pauseCount', 'pauseDuration', 'errorCount', 'exitType',
        'completionRate', 'liveLatencySamples', 'liveLatencyMean', 'liveLatencyP95',
        'liveLatencyMax', 'lowLatency', 'beaconSeq', 'seekSource',
    ];

    expect(array_keys([...BeaconPayload::CONTEXT, ...BeaconPayload::FIELDS]))->toEqualCanonicalizing($shipped);
});

describe('player 1.19.3 fields', function (): void {
    it('keeps numeric beaconSeq in fields and unrelated host dimensions in custom', function (int|float $seq): void {
        $payload = Beacons::payload('heartbeat', 0, ['beaconSeq' => $seq, 'campaign' => 'spring', 'seq' => 'host']);

        expect($payload->fields)->toBe(['beaconSeq' => $seq])
            ->and($payload->custom)->toBe(['campaign' => 'spring', 'seq' => 'host']);
    })->with([1, 2.6, -3]);

    it('keeps a string beaconSeq as custom without rejecting or coercing it', function (): void {
        $payload = Beacons::payload('seeking', 0, ['beaconSeq' => '2', 'seekSource' => 'player']);

        expect($payload->fields)->toBe(['seekSource' => 'player'])
            ->and($payload->custom)->toBe(['beaconSeq' => '2']);
    });

    it('recognizes seekSource without imposing an enum on string host values', function (string $source): void {
        $payload = Beacons::payload('seeking', 0, ['seekSource' => $source]);

        expect($payload->fields)->toBe(['seekSource' => $source])
            ->and($payload->custom)->toBe([]);
    })->with(['player', 'element', 'host-value']);

    it('preserves wrong-type seekSource in custom and omits null ordering fields', function (): void {
        $payload = Beacons::payload('seeking', 0, ['beaconSeq' => 1, 'seekSource' => 7]);
        $nulls = Beacons::payload('seeking', 0, ['beaconSeq' => null, 'seekSource' => null]);

        expect($payload->fields)->toBe(['beaconSeq' => 1])
            ->and($payload->custom)->toBe(['seekSource' => 7])
            ->and($nulls->fields)->toBe([])
            ->and($nulls->custom)->toBe([])
            ->and($nulls->toArray())->not->toHaveKeys(['beaconSeq', 'seekSource']);
    });
});

describe('server context', function (): void {
    it('removes every key it owns from the custom dimensions and keeps the non-null ones', function (): void {
        $payload = Beacons::payload('heartbeat', 0, ['user_id' => 'spoofed', 'plan' => 'ppv', 'tenant' => 3])
            ->withServer(['user_id' => 42, 'tenant' => 7]);

        expect($payload->custom)->toBe(['plan' => 'ppv'])
            ->and($payload->server)->toBe(['user_id' => 42, 'tenant' => 7]);
    });

    it('strips the browser copy of a key it returns as null, without storing anything', function (): void {
        $payload = Beacons::payload('heartbeat', 0, ['user_id' => 'spoofed', 'plan' => 'ppv'])
            ->withServer(['user_id' => null]);

        expect($payload->custom)->toBe(['plan' => 'ppv'])
            ->and($payload->server)->toBe([])
            ->and($payload->toArray())->not->toHaveKey('user_id');
    });

    it('refuses the six identity keys', function (string $key): void {
        expect(fn () => Beacons::payload('heartbeat')->withServer([$key => 'x']))
            ->toThrow(InvalidBeaconContextException::class, "[{$key}]");
    })->with(BeaconPayload::IDENTITY);

    it('refuses an empty key', function (): void {
        expect(fn () => Beacons::payload('heartbeat')->withServer(['' => 'x']))
            ->toThrow(InvalidBeaconContextException::class);
    });

    it('takes a numeric key as the name it is, and strips the browser dimension of that name', function (): void {
        $payload = Beacons::payload('heartbeat', 0, ['5' => 'browser', 'plan' => 'ppv'])->withServer(['5' => 'server']);

        expect($payload->custom)->toBe(['plan' => 'ppv'])
            ->and($payload->server)->toBe(['5' => 'server'])
            ->and($payload->toArray()['5'])->toBe('server');
    });

    it('puts the server context last in toArray(), so it wins a name the browser also sent', function (): void {
        $payload = Beacons::payload('pause', 0, ['duration' => 12.5])->withServer(['duration' => 'server', 'user_id' => 42]);

        $array = $payload->toArray();

        expect($array['duration'])->toBe('server')
            ->and($array['user_id'])->toBe(42)
            ->and(array_slice(array_keys($array), -2))->toBe(['duration', 'user_id'])
            // A known name goes only to the server map and the raw log, never its column.
            ->and($payload->fields['duration'])->toBe(12.5);
    });

    it('leaves browserArray() and the event key exactly as without it', function (): void {
        $plain = Beacons::payload('heartbeat', 0, ['plan' => 'ppv']);
        $withServer = $plain->withServer(['user_id' => 42]);

        expect($withServer->browserArray())->toBe($plain->browserArray())
            ->and($plain->browserArray())->toBe($plain->toArray())
            ->and(EloquentBeaconStore::eventKey($withServer))->toBe(EloquentBeaconStore::eventKey($plain));
    });

    it('is carried through withCustom() and withIp(), and withCustom() cannot put an owned key back', function (): void {
        $payload = Beacons::payload('heartbeat', 0, ['plan' => 'ppv'])->withServer(['user_id' => 42]);

        $redacted = $payload->withCustom(['plan' => 'free', 'user_id' => 'spoofed'])->withIp('203.0.113.0');

        expect($redacted->server)->toBe(['user_id' => 42])
            ->and($redacted->custom)->toBe(['plan' => 'free'])
            ->and($redacted->ip)->toBe('203.0.113.0');
    });

    it('replaces the map on a second call, like withCustom()', function (): void {
        $payload = Beacons::payload('heartbeat')->withServer(['user_id' => 42])->withServer(['tenant' => 7]);

        expect($payload->server)->toBe(['tenant' => 7]);
    });

    it('survives the queue: the job carries the server map', function (): void {
        $payload = Beacons::payload('heartbeat')->withServer(['user_id' => 42]);

        $job = unserialize(serialize(new ProcessBeacon($payload)));

        expect($job->payload->server)->toBe(['user_id' => 42]);
    });

    it('shows the server value in the fake ledger', function (): void {
        $fake = ScarlettPlayer::fake();

        app()->call([new ProcessBeacon(Beacons::payload('heartbeat', 0, ['user_id' => 'spoofed'])->withServer(['user_id' => 42])), 'handle']);

        $fake->assertBeaconRecorded(fn (array $beacon): bool => $beacon['user_id'] === 42);
    });
});

describe('event key stability', function (): void {
    it('keeps the v0.1.0 event key for a beacon with no redaction and no server context', function (): void {
        $payload = Beacons::payload('error', 1_000, ['errorType' => 'A', 'errorMessage' => 'a', 'fatal' => true, 'plan' => 'ppv']);
        $v010 = sha1($payload->viewId.$payload->event.$payload->timestamp.sha1((string) json_encode($payload->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));

        expect(EloquentBeaconStore::eventKey($payload))->toBe($v010);
    });

    it('keeps the key when the server context claims a key the browser sent', function (): void {
        $payload = Beacons::payload('error', 1_000, ['errorType' => 'A', 'errorMessage' => 'a', 'fatal' => true, 'user_id' => 'browser']);

        expect(EloquentBeaconStore::eventKey($payload->withServer(['user_id' => 42])))->toBe(EloquentBeaconStore::eventKey($payload))
            ->and(EloquentBeaconStore::eventKey($payload->withServer(['user_id' => null])))->toBe(EloquentBeaconStore::eventKey($payload));
    });

    it('keeps the key through a redaction and an address change', function (): void {
        $payload = Beacons::payload('heartbeat', 0, ['email' => 'viewer@example.com']);

        expect(EloquentBeaconStore::eventKey($payload->withCustom([])->withIp('203.0.113.0')))->toBe(EloquentBeaconStore::eventKey($payload));
    });

    it('still tells two different beacons apart', function (): void {
        expect(EloquentBeaconStore::eventKey(Beacons::payload('heartbeat', 0, ['plan' => 'a'])))
            ->not->toBe(EloquentBeaconStore::eventKey(Beacons::payload('heartbeat', 0, ['plan' => 'b'])));
    });
});

describe('legacy hashes after field promotion', function (): void {
    it('hashes the explicit legacy normalization without changing classification', function (array $received, array $normalized, array $fields, array $custom): void {
        // Deliberately scramble identity order and exercise the original casts.
        $payload = BeaconPayload::fromArray(array_replace([
            'videoId' => 4, 'viewerId' => 3, 'timestamp' => (string) Beacons::T0,
            'sessionId' => 2, 'event' => 'error', 'viewId' => 1,
        ], $received));
        // These expected maps are explicit pre-promotion output, independent of
        // FIELDS, browserArray() and fromArray()'s classification algorithm.
        $legacy = array_replace([
            'event' => 'error', 'timestamp' => Beacons::T0, 'viewId' => '1',
            'sessionId' => '2', 'viewerId' => '3', 'videoId' => '4',
        ], $normalized);
        $hash = sha1((string) json_encode($legacy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        expect($payload->bodyHash)->toBe($hash)
            ->and(EloquentBeaconStore::eventKey($payload))->toBe(sha1('1error'.Beacons::T0.$hash))
            ->and($payload->fields)->toBe($fields)
            ->and($payload->custom)->toBe($custom);
    })->with([
        'sequence alone before an old field' => [
            ['beaconSeq' => 2, 'errorType' => 'network'],
            ['errorType' => 'network', 'beaconSeq' => 2],
            ['beaconSeq' => 2, 'errorType' => 'network'], [],
        ],
        'source alone before an old field' => [
            ['seekSource' => 'element', 'errorType' => 'network'],
            ['errorType' => 'network', 'seekSource' => 'element'],
            ['seekSource' => 'element', 'errorType' => 'network'], [],
        ],
        'both promotions without other keys' => [
            ['seekSource' => 'player', 'beaconSeq' => 2.6],
            ['seekSource' => 'player', 'beaconSeq' => 2.6],
            ['seekSource' => 'player', 'beaconSeq' => 2.6], [],
        ],
        'custom before between and after promotions' => [
            ['first' => 1, 'beaconSeq' => 2, 'middle' => 3, 'errorType' => 'network', 'seekSource' => 'player', 'last' => 4],
            ['errorType' => 'network', 'first' => 1, 'beaconSeq' => 2, 'middle' => 3, 'seekSource' => 'player', 'last' => 4],
            ['beaconSeq' => 2, 'errorType' => 'network', 'seekSource' => 'player'],
            ['first' => 1, 'middle' => 3, 'last' => 4],
        ],
        'reverse promotion and custom order' => [
            ['last' => 4, 'seekSource' => 'player', 'middle' => 3, 'beaconSeq' => 2, 'first' => 1, 'errorType' => 'network'],
            ['errorType' => 'network', 'last' => 4, 'seekSource' => 'player', 'middle' => 3, 'beaconSeq' => 2, 'first' => 1],
            ['seekSource' => 'player', 'beaconSeq' => 2, 'errorType' => 'network'],
            ['last' => 4, 'middle' => 3, 'first' => 1],
        ],
        'context and old fields retain received order' => [
            ['fatal' => false, 'os' => 'macOS', 'first' => 1, 'seekSource' => 'element', 'errorType' => 'network', 'browser' => 'Chrome', 'beaconSeq' => 2],
            ['os' => 'macOS', 'browser' => 'Chrome', 'fatal' => false, 'errorType' => 'network', 'first' => 1, 'seekSource' => 'element', 'beaconSeq' => 2],
            ['fatal' => false, 'seekSource' => 'element', 'errorType' => 'network', 'beaconSeq' => 2],
            ['first' => 1],
        ],
        'wrong types remain interleaved custom' => [
            ['first' => 1, 'beaconSeq' => '2', 'duration' => 'long', 'seekSource' => ['element'], 'isLive' => 'unknown', 'fatal' => false],
            ['fatal' => false, 'first' => 1, 'beaconSeq' => '2', 'duration' => 'long', 'seekSource' => ['element'], 'isLive' => 'unknown'],
            ['fatal' => false],
            ['first' => 1, 'beaconSeq' => '2', 'duration' => 'long', 'seekSource' => ['element'], 'isLive' => 'unknown'],
        ],
        'one wrong type and one promoted field' => [
            ['seekSource' => 7, 'first' => 1, 'beaconSeq' => 2, 'fatal' => true],
            ['fatal' => true, 'seekSource' => 7, 'first' => 1, 'beaconSeq' => 2],
            ['beaconSeq' => 2, 'fatal' => true], ['seekSource' => 7, 'first' => 1],
        ],
        'nulls are absent in every category' => [
            ['beaconSeq' => null, 'first' => null, 'seekSource' => null, 'fatal' => null, 'isLive' => null, 'last' => 4],
            ['last' => 4], [], ['last' => 4],
        ],
        'numeric keys nesting Unicode and slashes' => [
            ['5' => ['9' => "caf\u{00e9}/path", 'a' => [true, null]], 'beaconSeq' => 2, '0' => 'zero', 'seekSource' => 'player', '05' => 'padded'],
            ['5' => ['9' => "caf\u{00e9}/path", 'a' => [true, null]], 'beaconSeq' => 2, '0' => 'zero', 'seekSource' => 'player', '05' => 'padded'],
            ['beaconSeq' => 2, 'seekSource' => 'player'],
            ['5' => ['9' => "caf\u{00e9}/path", 'a' => [true, null]], '0' => 'zero', '05' => 'padded'],
        ],
        'no promoted names' => [
            ['plan' => 'ppv', 'fatal' => false, 'os' => 'macOS', 'errorType' => 'network', 'browser' => 'Chrome', 'duration' => 'long'],
            ['os' => 'macOS', 'browser' => 'Chrome', 'fatal' => false, 'errorType' => 'network', 'plan' => 'ppv', 'duration' => 'long'],
            ['fatal' => false, 'errorType' => 'network'], ['plan' => 'ppv', 'duration' => 'long'],
        ],
    ]);

    it('retains the legacy order sensitivity rather than sorting keys', function (): void {
        $first = Beacons::payload('error', 0, ['plan' => 'ppv', 'beaconSeq' => 2, 'seekSource' => 'player']);
        $second = Beacons::payload('error', 0, ['beaconSeq' => 2, 'plan' => 'ppv', 'seekSource' => 'player']);

        expect($first->browserArray())->toBe($second->browserArray())
            ->and($first->bodyHash)->not->toBe($second->bodyHash);
    });

    it('keeps the legacy hash through ownership redaction address changes and a current queued job', function (): void {
        $body = [
            'event' => 'error', 'timestamp' => 123, 'viewId' => 'v',
            'sessionId' => 's', 'viewerId' => 'u', 'videoId' => 'm',
            'user_id' => 'spoofed', 'beaconSeq' => 2, 'tenant' => 'browser',
            'seekSource' => 'player', 'errorType' => 'network',
        ];
        // Frozen legacy JSON, not the current DTO's reconstructed browser array.
        $hash = sha1('{"event":"error","timestamp":123,"viewId":"v","sessionId":"s","viewerId":"u","videoId":"m","errorType":"network","user_id":"spoofed","beaconSeq":2,"tenant":"browser","seekSource":"player"}');
        $plain = BeaconPayload::fromArray($body, '203.0.113.0');
        $owned = $plain->withServer(['user_id' => null, 'tenant' => 7]);
        $redacted = $owned->withCustom(['user_id' => 'again', 'tenant' => 'again', 'plan' => 'free']);
        $moved = $redacted->withIp('203.0.113.1');
        $anonymous = $moved->withIp(null);
        $job = unserialize(serialize(new ProcessBeacon($anonymous)));

        foreach ([$plain, $owned, $redacted, $moved, $anonymous, $job->payload] as $payload) {
            expect($payload->bodyHash)->toBe($hash)
                ->and(EloquentBeaconStore::eventKey($payload))->toBe(sha1('verror123'.$hash));
        }

        expect($plain->bodyHash)->not->toBe(sha1((string) json_encode($plain->browserArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)))
            ->and($job->payload)->toEqual($anonymous)
            ->and($job->payload->custom)->toBe(['plan' => 'free'])
            ->and($job->payload->server)->toBe(['tenant' => 7])
            ->and($job->payload->owned)->toBe(['user_id', 'tenant'])
            ->and($job->payload->ip)->toBeNull();
    });

    it('restores old queued classification with or without a stored hash', function (bool $hasHash, bool $hasServer): void {
        $identity = [
            'event' => 'error', 'timestamp' => 123, 'viewId' => 'v',
            'sessionId' => 's', 'viewerId' => 'u', 'videoId' => 'm',
        ];
        $context = ['browser' => 'Chrome'];
        $fields = ['errorType' => 'network'];
        $custom = ['plan' => 'ppv', 'beaconSeq' => 2, 'seekSource' => 'player'];
        $legacy = array_replace($identity, $context, $fields, $custom);
        $hash = sha1((string) json_encode($legacy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $state = array_replace($identity, ['context' => $context, 'fields' => $fields, 'custom' => $custom, 'ip' => null]);
        if ($hasHash) {
            $state['bodyHash'] = $hash;
        }
        if ($hasServer) {
            // Older queues with server but no owned property infer ownership.
            $state['server'] = ['tenant' => 7];
        }
        $serialized = str_replace('O:8:"stdClass"', 'O:'.strlen(BeaconPayload::class).':"'.BeaconPayload::class.'"', serialize((object) $state));
        $restored = unserialize($serialized);
        $fresh = BeaconPayload::fromArray($legacy);

        expect($restored)->toBeInstanceOf(BeaconPayload::class)
            ->and($restored->bodyHash)->toBe($hash)
            ->and($restored->bodyHash)->toBe($fresh->bodyHash)
            ->and($restored->fields)->toBe($fields)
            ->and($restored->custom)->toBe($custom)
            ->and($restored->has('beaconSeq'))->toBeFalse()
            ->and($restored->has('seekSource'))->toBeFalse()
            ->and($restored->server)->toBe($hasServer ? ['tenant' => 7] : [])
            ->and($restored->owned)->toBe($hasServer ? ['tenant'] : [])
            ->and($restored->withCustom(['tenant' => 'spoofed'])->custom)->toBe($hasServer ? [] : ['tenant' => 'spoofed']);
    })->with(['stored hash' => true, 'missing hash' => false])
        ->with(['server without owned' => true, 'missing server and owned' => false]);

    it('treats an explicitly supplied hash as authoritative including on queue restoration', function (): void {
        $payload = new BeaconPayload('error', 123, 'v', 's', 'u', 'm',
            fields: ['beaconSeq' => 2], custom: ['plan' => 'ppv'], bodyHash: str_repeat('a', 40));
        $restored = unserialize(serialize($payload));

        expect($restored->bodyHash)->toBe(str_repeat('a', 40))
            ->and($restored->withServer(['plan' => null])->withCustom([])->withIp(null)->bodyHash)->toBe(str_repeat('a', 40));
    });

    it('leaves the direct constructor fallback based on its supplied classification', function (): void {
        $payload = new BeaconPayload('error', 123, 'v', 's', 'u', 'm',
            fields: ['beaconSeq' => 2], custom: ['plan' => 'ppv']);

        expect($payload->bodyHash)->toBe(sha1('{"event":"error","timestamp":123,"viewId":"v","sessionId":"s","viewerId":"u","videoId":"m","beaconSeq":2,"plan":"ppv"}'));
    });
});

describe('null ownership', function (): void {
    it('remembers a key owned as null, so withCustom() cannot put it back', function (): void {
        $payload = Beacons::payload('heartbeat', 0, ['user_id' => 'spoofed'])
            ->withServer(['user_id' => null, 'tenant' => 7])
            ->withCustom(['user_id' => 'browser', 'plan' => 'ppv']);

        expect($payload->custom)->toBe(['plan' => 'ppv'])
            ->and($payload->server)->toBe(['tenant' => 7])
            ->and($payload->owned)->toBe(['user_id', 'tenant'])
            ->and($payload->toArray())->not->toHaveKey('user_id');
    });

    it('carries ownership through withIp(), and a second withServer() replaces it', function (): void {
        $payload = Beacons::payload('heartbeat')->withServer(['user_id' => null])->withIp(null);

        expect($payload->owned)->toBe(['user_id'])
            ->and($payload->withServer(['tenant' => 7])->owned)->toBe(['tenant']);
    });
});

describe('a job queued before the upgrade', function (): void {
    it('unserializes a v0.1.0 payload with an empty server context and its v0.1.0 event key', function (): void {
        $legacy = serialize(new LegacyBeaconPayloadForUnserializeTest(
            'heartbeat', Beacons::T0, Beacons::VIEW, 'session-1', 'viewer-1', 'video-1',
            ['playerVersion' => '1.17.0'], ['watchTime' => 10_000], ['plan' => 'ppv'], '203.0.113.0',
        ));
        $from = 'O:'.strlen(LegacyBeaconPayloadForUnserializeTest::class).':"'.LegacyBeaconPayloadForUnserializeTest::class.'"';
        $to = 'O:'.strlen(BeaconPayload::class).':"'.BeaconPayload::class.'"';

        $payload = unserialize(str_replace($from, $to, $legacy));
        $fresh = new BeaconPayload('heartbeat', Beacons::T0, Beacons::VIEW, 'session-1', 'viewer-1', 'video-1',
            ['playerVersion' => '1.17.0'], ['watchTime' => 10_000], ['plan' => 'ppv'], '203.0.113.0');

        expect($payload)->toBeInstanceOf(BeaconPayload::class)
            ->and($payload->server)->toBe([])
            ->and($payload->owned)->toBe([])
            ->and($payload->custom)->toBe(['plan' => 'ppv'])
            ->and($payload->ip)->toBe('203.0.113.0')
            ->and(EloquentBeaconStore::eventKey($payload))->toBe(EloquentBeaconStore::eventKey($fresh));
    });

    it('round-trips a current payload unchanged, event key included', function (): void {
        $payload = Beacons::payload('heartbeat', 0, ['user_id' => 'x', 'plan' => 'ppv'])->withServer(['user_id' => null, 'tenant' => 7]);

        $restored = unserialize(serialize($payload));

        expect($restored)->toEqual($payload)
            ->and($restored->bodyHash)->toBe($payload->bodyHash);
    });
});
