<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Contracts\BeaconStore;
use Hei\ScarlettPlayer\Doctor\CheckResult;
use Hei\ScarlettPlayer\Doctor\Checks\BeaconKeyCheck;
use Hei\ScarlettPlayer\Doctor\Checks\BeaconStoreCheck;
use Hei\ScarlettPlayer\Doctor\Checks\MediaMappingCheck;
use Hei\ScarlettPlayer\Doctor\Checks\QueueConnectionCheck;
use Hei\ScarlettPlayer\Doctor\CheckStatus;
use Hei\ScarlettPlayer\Stores\NullBeaconStore;
use Hei\ScarlettPlayer\Testing\FakeBeaconStore;
use Hei\ScarlettPlayer\Tests\Fixtures\Models\ScarlettVideo;
use Hei\ScarlettPlayer\Tests\Fixtures\Models\Video;
use Hei\ScarlettPlayer\Tests\Fixtures\Provider\ArrayResolver;
use Hei\ScarlettPlayer\Tests\TestCase;

function runCheck(string $class): CheckResult
{
    return app($class)->run();
}

describe('media mapping', function (): void {
    it('fails when media.model is not configured', function (): void {
        $result = runCheck(MediaMappingCheck::class);

        expect($result->status)->toBe(CheckStatus::Fail)
            ->and($result->message)->toContain('media.model');
    });

    it('fails naming the missing attribute keys', function (): void {
        $this->withScarlettConfig([
            'media.model' => Video::class,
            'media.attributes' => ['playback_url' => 'hls_url', 'is_live' => 'is_live'],
        ]);

        $result = runCheck(MediaMappingCheck::class);

        expect($result->status)->toBe(CheckStatus::Fail)
            ->and($result->message)->toContain('media.attributes.is_protected');
    });

    it('passes for a ScarlettMedia model', function (): void {
        $this->withScarlettConfig(['media.model' => ScarlettVideo::class]);

        expect(runCheck(MediaMappingCheck::class)->status)->toBe(CheckStatus::Pass);
    });

    it('passes a custom resolver with a note', function (): void {
        $this->withScarlettConfig(['media.resolver' => ArrayResolver::class]);

        $result = runCheck(MediaMappingCheck::class);

        expect($result->status)->toBe(CheckStatus::Pass)
            ->and($result->message)->toContain(ArrayResolver::class);
    });
});

describe('beacon key', function (): void {
    it('passes when the key is set, and names no value', function (): void {
        $result = runCheck(BeaconKeyCheck::class);

        expect($result->status)->toBe(CheckStatus::Pass)
            ->and($result->message)->not->toContain(TestCase::BEACON_KEY);
    });

    it('fails when the key is empty', function (mixed $key): void {
        config()->set('scarlett-player.beacons.key', $key);

        $result = runCheck(BeaconKeyCheck::class);

        expect($result->status)->toBe(CheckStatus::Fail)
            ->and($result->message)->toContain('SCARLETT_BEACON_KEY');
    })->with([null, '']);

    it('fails on surrounding whitespace without printing the key', function (): void {
        config()->set('scarlett-player.beacons.key', "secret-value\n");

        $result = runCheck(BeaconKeyCheck::class);

        expect($result->status)->toBe(CheckStatus::Fail)
            ->and($result->message)->toContain('whitespace')
            ->and($result->message)->not->toContain('secret-value');
    });

    it('passes when beacons are off', function (string $key): void {
        config()->set('scarlett-player.beacons.key', null);
        config()->set("scarlett-player.{$key}", false);

        expect(runCheck(BeaconKeyCheck::class)->status)->toBe(CheckStatus::Pass);
    })->with(['routes.beacons', 'beacons.enabled']);
});

describe('queue connections', function (): void {
    it('warns on sync outside local', function (): void {
        config()->set('queue.default', 'sync');

        $result = runCheck(QueueConnectionCheck::class);

        expect($result->status)->toBe(CheckStatus::Warn)
            ->and($result->message)->toContain('beacons: [sync] is sync outside local')
            ->and($result->message)->toContain('clips: [sync]');
    });

    it('passes sync in the local environment', function (): void {
        config()->set('queue.default', 'sync');
        app()->detectEnvironment(fn (): string => 'local');

        expect(runCheck(QueueConnectionCheck::class)->status)->toBe(CheckStatus::Pass);
    });

    it('notes that database writes each job row synchronously', function (): void {
        config()->set('scarlett-player.beacons.connection', 'database');
        config()->set('scarlett-player.clips.connection', 'redis');

        $result = runCheck(QueueConnectionCheck::class);

        expect($result->status)->toBe(CheckStatus::Pass)
            ->and($result->message)->toContain('database writes each job row synchronously')
            ->and($result->message)->toContain('clips: [redis] (redis)');
    });

    it('fails on a connection that is not defined', function (): void {
        config()->set('scarlett-player.beacons.connection', 'database');
        config()->set('scarlett-player.clips.connection', 'nowhere');

        $result = runCheck(QueueConnectionCheck::class);

        expect($result->status)->toBe(CheckStatus::Fail)
            ->and($result->message)->toContain('clips: connection [nowhere] is not defined');
    });

    it('keeps a failure when a later module only warns', function (): void {
        config()->set('scarlett-player.beacons.connection', 'nowhere');
        config()->set('scarlett-player.clips.connection', 'sync');

        expect(runCheck(QueueConnectionCheck::class)->status)->toBe(CheckStatus::Fail);
    });

    it('skips a module that is off', function (): void {
        config()->set('scarlett-player.beacons.enabled', false);
        config()->set('scarlett-player.clips.enabled', false);

        $result = runCheck(QueueConnectionCheck::class);

        expect($result->status)->toBe(CheckStatus::Pass)
            ->and($result->message)->toBe('beacons: off; clips: off');
    });
});

describe('beacon store', function (): void {
    it('warns while the null store is bound and beacons are on', function (): void {
        app()->instance(BeaconStore::class, new NullBeaconStore);

        expect(runCheck(BeaconStoreCheck::class)->status)->toBe(CheckStatus::Warn);
    });

    it('passes the null store when beacons are off', function (): void {
        app()->instance(BeaconStore::class, new NullBeaconStore);
        config()->set('scarlett-player.beacons.enabled', false);

        expect(runCheck(BeaconStoreCheck::class)->status)->toBe(CheckStatus::Pass);
    });

    it('passes naming a real store', function (): void {
        $store = new FakeBeaconStore;
        app()->instance(BeaconStore::class, $store);

        $result = runCheck(BeaconStoreCheck::class);

        expect($result->status)->toBe(CheckStatus::Pass)
            ->and($result->message)->toContain($store::class);
    });

    it('fails when the binding does not resolve', function (): void {
        app()->bind(BeaconStore::class, fn () => throw new RuntimeException('no store here'));

        $result = runCheck(BeaconStoreCheck::class);

        expect($result->status)->toBe(CheckStatus::Fail)
            ->and($result->message)->toContain('no store here');
    });
});
