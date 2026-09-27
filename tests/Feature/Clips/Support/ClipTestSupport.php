<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Tests\Feature\Clips\Support;

use Hei\ScarlettPlayer\Contracts\ResolvesMedia;
use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Models\Clip;
use Hei\ScarlettPlayer\Tests\Fixtures\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared setup for the clips tests: users, the clips table, media and payloads.
 */
final class ClipTestSupport
{
    public const MEDIA_ID = 'vid-1';

    /**
     * Create the users table the clip tests authenticate against.
     */
    public static function createUsers(): void
    {
        config()->set('auth.providers.users.model', User::class);

        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->rememberToken();
                $table->timestamps();
            });
        }
    }

    public static function user(string $name = 'viewer'): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $name.'-'.Str::random(6).'@example.test',
            'password' => 'not-a-real-hash',
        ]);
    }

    /**
     * Bind a resolver that answers from the given sources.
     */
    public static function media(MediaSource ...$sources): void
    {
        $map = [];

        foreach ($sources as $source) {
            $map[$source->id] = $source;
        }

        app()->instance(ResolvesMedia::class, new class($map) implements ResolvesMedia
        {
            /**
             * @param  array<string, MediaSource>  $map
             */
            public function __construct(private readonly array $map) {}

            public function resolve(string $mediaId): ?MediaSource
            {
                return $this->map[$mediaId] ?? null;
            }
        });
    }

    public static function source(
        string $id = self::MEDIA_ID,
        bool $isProtected = false,
        bool $isLive = false,
        ?string $sourceDisk = 'mezzanine',
        ?string $sourcePath = 'videos/vid-1.mp4',
    ): MediaSource {
        return new MediaSource(
            id: $id,
            playbackUrl: "https://cdn.example.test/{$id}.m3u8",
            isLive: $isLive,
            isProtected: $isProtected,
            duration: $isLive ? null : 600.0,
            sourceDisk: $sourceDisk,
            sourcePath: $sourcePath,
            title: 'Opening night',
        );
    }

    /**
     * The ClipRange the player posts, camelCase, as submit.ts sends it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function payload(array $overrides = []): array
    {
        return array_merge([
            'startTime' => 120.5,
            'endTime' => 150.5,
            'duration' => 30,
            'mediaId' => self::MEDIA_ID,
            'clientRequestId' => 'c7f1a2b3-0000-4000-8000-000000000001',
            'title' => 'The knockout',
            'isLive' => false,
            'seekableStart' => null,
            'seekableEnd' => null,
            'startDate' => null,
            'endDate' => null,
            'capturedAt' => '2026-09-07T18:04:11.000Z',
        ], $overrides);
    }

    /**
     * A stored clip, pending unless overridden.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function clip(array $attributes = []): Clip
    {
        return Clip::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'media_id' => self::MEDIA_ID,
            'client_request_id' => (string) Str::uuid(),
            'title' => 'The knockout',
            'start_seconds' => 120.5,
            'end_seconds' => 150.5,
            'duration_seconds' => 30.0,
            'status' => 'pending',
            'visibility' => 'pending_review',
        ], $attributes));
    }

    /**
     * POST a clip the way submit.ts does: a JSON body, Content-Type application/json,
     * and no Accept header.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers  Extra server variables (HTTP_*).
     * @return TestResponse<Response>
     */
    public static function post(TestCase $test, array $payload, array $headers = []): TestResponse
    {
        return $test->call('POST', route('scarlett.clips.store'), [], [], [], array_merge([
            'CONTENT_TYPE' => 'application/json',
        ], $headers), (string) json_encode($payload));
    }

    /**
     * Point the clips module at a faked private disk.
     */
    public static function fakeDisk(string $disk = 'clips'): void
    {
        config()->set('scarlett-player.clips.disk', $disk);
        config()->set("filesystems.disks.{$disk}", ['driver' => 'local', 'root' => sys_get_temp_dir().'/scarlett-clips-disk', 'visibility' => 'private']);
        Storage::fake($disk);
        Storage::disk($disk)->buildTemporaryUrlsUsing(
            fn (string $path, \DateTimeInterface $expiration): string => "https://objects.example.test/{$path}?expires={$expiration->getTimestamp()}",
        );
    }
}
