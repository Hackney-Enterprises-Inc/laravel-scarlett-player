<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Data\ClipOptions;
use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Enums\ClipAccuracy;
use Hei\ScarlettPlayer\Exceptions\ClipGenerationException;
use Hei\ScarlettPlayer\Generators\ClipGeneratorManager;
use Hei\ScarlettPlayer\Generators\LocalFfmpegGenerator;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

function source(?string $disk = null, ?string $path = null, bool $protected = false, string $playback = 'https://cdn.example.test/v.m3u8'): MediaSource
{
    return new MediaSource(id: 'vid-1', playbackUrl: $playback, isLive: false, isProtected: $protected, duration: 600.0, sourceDisk: $disk, sourcePath: $path);
}

describe('command', function (): void {
    test('keyframe seeks on the input and stream copies', function (): void {
        $command = (new LocalFfmpegGenerator('/usr/bin/ffmpeg'))->command('in.mp4', 9, 69, ClipAccuracy::Keyframe, 'out.mp4');

        expect($command)->toBe([
            '/usr/bin/ffmpeg', '-hide_banner', '-nostdin', '-loglevel', 'error', '-y',
            '-ss', '9.000', '-to', '69.000', '-i', 'in.mp4',
            '-map', '0:v:0', '-map', '0:a:0?', '-c', 'copy',
            '-movflags', '+faststart', 'out.mp4',
        ]);
    });

    test('exact opens the input first, then seeks on the output and re-encodes', function (): void {
        $command = (new LocalFfmpegGenerator)->command('in.mp4', 120.5, 150.5, ClipAccuracy::Exact, 'out.mp4');

        expect(array_search('-i', $command, true))->toBeLessThan(array_search('-ss', $command, true))
            ->and($command)->toContain('libx264')
            ->and($command)->not->toContain('copy')
            ->and(array_slice($command, 6, 6))->toBe(['-i', 'in.mp4', '-ss', '120.500', '-to', '150.500'])
            ->and($command[array_key_last($command)])->toBe('out.mp4');
    });
});

describe('input', function (): void {
    test('a local mezzanine is read by path', function (): void {
        $root = sys_get_temp_dir().'/scarlett-mezzanine';
        config()->set('filesystems.disks.mezzanine', ['driver' => 'local', 'root' => $root]);

        expect((new LocalFfmpegGenerator)->input(source('mezzanine', 'videos/a.mp4')))->toBe($root.'/videos/a.mp4');
    });

    test('a remote mezzanine is read through a temporary URL', function (): void {
        config()->set('filesystems.disks.remote', ['driver' => 'local', 'root' => sys_get_temp_dir()]);
        Storage::fake('remote');
        config()->set('filesystems.disks.remote.driver', 's3');
        Storage::disk('remote')->buildTemporaryUrlsUsing(fn (string $path): string => "https://s3.example.test/{$path}?sig=1");

        expect((new LocalFfmpegGenerator)->input(source('remote', 'videos/a.mp4')))->toBe('https://s3.example.test/videos/a.mp4?sig=1');
    });

    test('a remote disk without temporary URLs falls back to the playback URL', function (): void {
        config()->set('filesystems.disks.plain', ['driver' => 'local', 'root' => sys_get_temp_dir()]);
        Storage::fake('plain');
        config()->set('filesystems.disks.plain.driver', 'ftp');
        Storage::disk('plain')->buildTemporaryUrlsUsing(fn () => throw new RuntimeException('This driver does not support creating temporary URLs.'));

        expect((new LocalFfmpegGenerator)->input(source('plain', 'videos/a.mp4')))->toBe('https://cdn.example.test/v.m3u8');
    });

    test('no mezzanine uses the playback URL', function (): void {
        expect((new LocalFfmpegGenerator)->input(source()))->toBe('https://cdn.example.test/v.m3u8');
    });

    test('no mezzanine and no playback URL throws', function (): void {
        (new LocalFfmpegGenerator)->input(source(playback: ''));
    })->throws(ClipGenerationException::class, 'vid-1');
});

describe('generate', function (): void {
    test('it runs ffmpeg with the render timeout and returns the temp file it wrote', function (): void {
        Process::fake(function (PendingProcess $process) {
            $command = (array) $process->command;
            file_put_contents((string) end($command), 'video');

            return Process::result();
        });

        $file = (new LocalFfmpegGenerator('ffmpeg', 300))->generate(source(), 10, 40, new ClipOptions(ClipAccuracy::Keyframe, 'clip-uuid', 120));

        expect($file)->toEndWith('.mp4')->toContain('clip-uuid')
            ->and(file_get_contents($file))->toBe('video');
        Process::assertRan(fn (PendingProcess $process): bool => $process->timeout === 120 && ((array) $process->command)[0] === 'ffmpeg');

        @unlink($file);
    });

    test('two calls for one clip write two different files', function (): void {
        Process::fake(function (PendingProcess $process) {
            $command = (array) $process->command;
            file_put_contents((string) end($command), 'video');

            return Process::result();
        });
        $generator = new LocalFfmpegGenerator;
        $options = new ClipOptions(ClipAccuracy::Exact, 'same');

        $a = $generator->generate(source(), 0, 10, $options);
        $b = $generator->generate(source(), 0, 10, $options);

        expect($a)->not->toBe($b);
        @unlink($a);
        @unlink($b);
    });

    test('a failing ffmpeg throws and leaves no file', function (): void {
        Process::fake(fn () => Process::result(errorOutput: 'Invalid data found', exitCode: 1));

        expect(fn () => (new LocalFfmpegGenerator)->generate(source(), 0, 10, new ClipOptions(ClipAccuracy::Exact, 'x')))
            ->toThrow(ClipGenerationException::class, 'Invalid data found');
    });

    test('an ffmpeg that exits 0 without writing a file throws', function (): void {
        Process::fake();

        (new LocalFfmpegGenerator)->generate(source(), 0, 10, new ClipOptions(ClipAccuracy::Exact, 'x'));
    })->throws(ClipGenerationException::class);
});

describe('options and manager', function (): void {
    test('a protected source always gets exact', function (ClipAccuracy $configured): void {
        expect(ClipOptions::accuracyFor(source(protected: true), $configured))->toBe(ClipAccuracy::Exact)
            ->and(ClipOptions::accuracyFor(source(), $configured))->toBe($configured);
    })->with([ClipAccuracy::Keyframe, ClipAccuracy::Exact]);

    test('the manager builds the local-ffmpeg driver from its generator config', function (): void {
        $manager = app(ClipGeneratorManager::class);

        expect($manager->getDefaultDriver())->toBe('local-ffmpeg')
            ->and($manager->driver())->toBeInstanceOf(LocalFfmpegGenerator::class)
            ->and($manager->timeout())->toBe(300)
            ->and($manager->generatorConfig()['ffprobe'])->toBe('ffprobe');
    });

    test('a named generator picks its implementation from its driver key', function (): void {
        config()->set('scarlett-player.clips.generators.big-box', ['driver' => 'local-ffmpeg', 'binary' => '/opt/ffmpeg', 'timeout' => 900]);

        expect(app(ClipGeneratorManager::class)->driver('big-box'))->toBeInstanceOf(LocalFfmpegGenerator::class)
            ->and(app(ClipGeneratorManager::class)->timeout('big-box'))->toBe(900);
    });

    test('a custom driver receives its generator config', function (): void {
        config()->set('scarlett-player.clips.generators.farm', ['driver' => 'fleet', 'timeout' => 60, 'region' => 'east']);
        $received = null;

        app(ClipGeneratorManager::class)->extend('fleet', function ($app, array $config) use (&$received) {
            $received = $config;

            return new LocalFfmpegGenerator;
        });

        app(ClipGeneratorManager::class)->driver('farm');

        expect($received)->toMatchArray(['driver' => 'fleet', 'region' => 'east']);
    });

    test('an unknown generator or driver throws', function (): void {
        config()->set('scarlett-player.clips.generators.odd', ['driver' => 'nope']);

        expect(fn () => app(ClipGeneratorManager::class)->driver('missing'))->toThrow(ClipGenerationException::class)
            ->and(fn () => app(ClipGeneratorManager::class)->driver('odd'))->toThrow(ClipGenerationException::class);
    });
});
