<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Testing;

use Closure;
use Hei\ScarlettPlayer\Contracts\ResolvesMedia;
use Hei\ScarlettPlayer\Data\MediaSource;
use Hei\ScarlettPlayer\Events\ClipRequested;
use Hei\ScarlettPlayer\Exceptions\MediaNotFoundException;
use Hei\ScarlettPlayer\ScarlettPlayer;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * The test double `ScarlettPlayer::fake()` swaps in.
 *
 * It records every mediaId the app resolved and answers from media registered with
 * withMedia() before falling back to the bound resolver. It also carries the beacon
 * and clip ledgers; the beacons and clips modules fill them, and the assertion names
 * are fixed so tests written against them never need renaming.
 */
class FakeScarlett extends ScarlettPlayer
{
    /** @var array<string, MediaSource> */
    protected array $media = [];

    /** @var list<string> */
    protected array $resolved = [];

    /** @var list<array<string, mixed>> */
    protected array $beacons = [];

    /** @var list<array<string, mixed>> */
    protected array $clips = [];

    /**
     * Every clip the app accepts while the fake is in place lands in the clip ledger:
     * the create endpoint fires ClipRequested, and the fake records it.
     */
    public function __construct(Container $container, ResolvesMedia $resolver, Repository $config)
    {
        parent::__construct($container, $resolver, $config);

        $container->make('events')->listen(ClipRequested::class, function (ClipRequested $event): void {
            $this->recordClip([
                'uuid' => $event->clip->uuid,
                'mediaId' => $event->clip->media_id,
                'clientRequestId' => $event->clip->client_request_id,
                'startTime' => $event->clip->start_seconds,
                'endTime' => $event->clip->end_seconds,
                'duration' => $event->clip->duration_seconds,
                'title' => $event->clip->title,
                'userId' => $event->clip->user_id,
            ]);
        });
    }

    /**
     * Answer these sources by id without touching the resolver or the database.
     */
    public function withMedia(MediaSource ...$sources): static
    {
        foreach ($sources as $source) {
            $this->media[$source->id] = $source;
        }

        return $this;
    }

    public function resolve(string $mediaId): MediaSource
    {
        $this->resolved[] = $mediaId;

        if (isset($this->media[$mediaId])) {
            return $this->media[$mediaId];
        }

        return $this->resolver->resolve($mediaId) ?? throw new MediaNotFoundException($mediaId);
    }

    /**
     * Every mediaId resolved, in order, including those that were not found.
     *
     * @return list<string>
     */
    public function resolved(): array
    {
        return $this->resolved;
    }

    /**
     * Record a beacon payload in the ledger.
     *
     * @param  array<string, mixed>  $payload
     */
    public function recordBeacon(array $payload): void
    {
        $this->beacons[] = $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recordedBeacons(): array
    {
        return $this->beacons;
    }

    /**
     * Record a clip request in the ledger.
     *
     * @param  array<string, mixed>  $request
     */
    public function recordClip(array $request): void
    {
        $this->clips[] = $request;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recordedClips(): array
    {
        return $this->clips;
    }

    public function assertResolved(string $mediaId): static
    {
        PHPUnit::assertContains($mediaId, $this->resolved, "Scarlett media [{$mediaId}] was not resolved.");

        return $this;
    }

    public function assertNothingResolved(): static
    {
        PHPUnit::assertSame([], $this->resolved, 'Scarlett media was resolved: ['.implode(', ', $this->resolved).'].');

        return $this;
    }

    /**
     * Assert a beacon was recorded, optionally one the callback accepts.
     *
     * @param  (Closure(array<string, mixed>): bool)|null  $callback
     */
    public function assertBeaconRecorded(?Closure $callback = null): static
    {
        PHPUnit::assertTrue(
            $this->matches($this->beacons, $callback),
            $callback === null ? 'No Scarlett beacon was recorded.' : 'No recorded Scarlett beacon matched the callback.',
        );

        return $this;
    }

    /**
     * Assert a clip was requested, optionally one the callback accepts.
     *
     * @param  (Closure(array<string, mixed>): bool)|null  $callback
     */
    public function assertClipRequested(?Closure $callback = null): static
    {
        PHPUnit::assertTrue(
            $this->matches($this->clips, $callback),
            $callback === null ? 'No Scarlett clip was requested.' : 'No requested Scarlett clip matched the callback.',
        );

        return $this;
    }

    /**
     * @param  list<array<string, mixed>>  $ledger
     * @param  (Closure(array<string, mixed>): bool)|null  $callback
     */
    protected function matches(array $ledger, ?Closure $callback): bool
    {
        if ($callback === null) {
            return $ledger !== [];
        }

        foreach ($ledger as $entry) {
            if ($callback($entry) === true) {
                return true;
            }
        }

        return false;
    }
}
