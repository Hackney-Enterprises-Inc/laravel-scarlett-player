<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Doctor\CheckRegistry;
use Hei\ScarlettPlayer\Doctor\Checks\ClipDiskCheck;
use Hei\ScarlettPlayer\Doctor\Checks\ClipLockStoreCheck;
use Hei\ScarlettPlayer\Doctor\Checks\FfmpegCheck;
use Hei\ScarlettPlayer\Doctor\Checks\QueueTimeoutCheck;
use Hei\ScarlettPlayer\Doctor\CheckStatus;
use Hei\ScarlettPlayer\Tests\Feature\Clips\Support\ClipTestSupport as T;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

test('the clips module registers its three doctor checks', function (): void {
    expect(app(CheckRegistry::class)->classes())->toContain(FfmpegCheck::class, ClipDiskCheck::class, QueueTimeoutCheck::class);
});

describe('ffmpeg', function (): void {
    test('passes when ffmpeg and ffprobe run', function (): void {
        Process::fake();

        expect((new FfmpegCheck)->run()->status)->toBe(CheckStatus::Pass);
    });

    test('fails naming the binary that does not run', function (): void {
        Process::fake(['*ffprobe*' => Process::result(exitCode: 127), '*' => Process::result()]);

        $result = (new FfmpegCheck)->run();

        expect($result->status)->toBe(CheckStatus::Fail)
            ->and($result->message)->toContain('ffprobe')
            ->and($result->message)->not->toContain('ffmpeg (');
    });

    test('a custom driver needs only ffprobe, for the verifier', function (): void {
        config()->set('scarlett-player.clips.generator', 'fleet');
        config()->set('scarlett-player.clips.generators.fleet', ['driver' => 'fleet', 'ffprobe' => 'ffprobe']);
        Process::fake();

        expect((new FfmpegCheck)->run()->message)->toBe('ffprobe run.');
    });
});

describe('clip disk', function (): void {
    test('passes for a disk that keeps writes private and issues temporary URLs', function (string $delivery): void {
        config()->set('scarlett-player.clips.public_delivery', $delivery);
        T::fakeDisk();

        expect((new ClipDiskCheck)->run()->status)->toBe(CheckStatus::Pass);
        expect(Storage::disk('clips')->allFiles())->toBe([]);
    })->with(['signed-redirect', 'disk-public']);

    test('fails without temporary URLs in either delivery mode, because previews need them', function (string $delivery): void {
        config()->set('scarlett-player.clips.public_delivery', $delivery);
        T::fakeDisk();
        Storage::disk('clips')->buildTemporaryUrlsUsing(fn () => throw new RuntimeException('no temporary URLs'));

        $result = (new ClipDiskCheck)->run();

        expect($result->status)->toBe(CheckStatus::Fail)
            ->and($result->message)->toContain('temporary URLs');
    })->with(['signed-redirect', 'disk-public']);

    test('fails for a disk that is not configured', function (): void {
        config()->set('scarlett-player.clips.disk', 'nowhere');

        expect((new ClipDiskCheck)->run()->status)->toBe(CheckStatus::Fail);
    });
});

describe('worker timeout', function (): void {
    test('warns when pcntl is missing and clips are on', function (): void {
        $check = new class extends QueueTimeoutCheck
        {
            protected function pcntlLoaded(): bool
            {
                return false;
            }
        };

        $result = $check->run();

        expect($result->status)->toBe(CheckStatus::Warn)
            ->and($result->message)->toContain('pcntl')
            ->and($result->message)->toContain('330 s');
    });

    test('passes when pcntl is loaded', function (): void {
        $check = new class extends QueueTimeoutCheck
        {
            protected function pcntlLoaded(): bool
            {
                return true;
            }
        };

        expect($check->run()->status)->toBe(CheckStatus::Pass);
    });

    test('passes when clips are off', function (): void {
        config()->set('scarlett-player.clips.enabled', false);
        $check = new class extends QueueTimeoutCheck
        {
            protected function pcntlLoaded(): bool
            {
                return false;
            }
        };

        expect($check->run()->status)->toBe(CheckStatus::Pass);
    });
});

describe('lock store', function (): void {
    test('warns on the array, null and file drivers, passes on shared stores', function (string $driver, CheckStatus $expected): void {
        config()->set('cache.default', 'probe');
        config()->set('cache.stores.probe', ['driver' => $driver]);

        expect((new ClipLockStoreCheck)->run()->status)->toBe($expected);
    })->with([
        'array' => ['array', CheckStatus::Warn],
        'null' => ['null', CheckStatus::Warn],
        'file' => ['file', CheckStatus::Warn],
        'redis' => ['redis', CheckStatus::Pass],
        'database' => ['database', CheckStatus::Pass],
    ]);

    test('passes when clips are off, and is registered', function (): void {
        config()->set('scarlett-player.clips.enabled', false);
        config()->set('cache.default', 'array');

        expect((new ClipLockStoreCheck)->run()->status)->toBe(CheckStatus::Pass)
            ->and(app(CheckRegistry::class)->classes())->toContain(ClipLockStoreCheck::class);
    });
});
