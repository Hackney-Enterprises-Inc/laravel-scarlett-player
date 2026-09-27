<?php

declare(strict_types=1);

namespace Hei\ScarlettPlayer\Testing;

use Closure;
use Hei\ScarlettPlayer\Contracts\BeaconStore;
use Hei\ScarlettPlayer\Data\BeaconPayload;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * A BeaconStore that records every payload it is given and stores nothing, for
 * tests. ScarlettPlayer::fake() binds one that also writes into the FakeScarlett
 * beacon ledger, so assertBeaconRecorded() sees beacons that went through the route.
 *
 * It records deliveries as they arrive, duplicates included: it proves what reached
 * the store, not what an idempotent store would keep.
 */
class FakeBeaconStore implements BeaconStore
{
    /** @var list<BeaconPayload> */
    protected array $recorded = [];

    /**
     * @param  (Closure(BeaconPayload): void)|null  $onRecord
     */
    public function __construct(
        protected ?Closure $onRecord = null,
    ) {}

    public function record(BeaconPayload $payload): void
    {
        $this->recorded[] = $payload;

        if ($this->onRecord !== null) {
            ($this->onRecord)($payload);
        }
    }

    /**
     * @return list<BeaconPayload>
     */
    public function recorded(): array
    {
        return $this->recorded;
    }

    /**
     * Assert a beacon was recorded, optionally one the callback accepts.
     *
     * @param  (Closure(BeaconPayload): bool)|null  $callback
     */
    public function assertRecorded(?Closure $callback = null): static
    {
        $matched = $callback === null
            ? $this->recorded !== []
            : array_filter($this->recorded, fn (BeaconPayload $payload): bool => $callback($payload) === true) !== [];

        PHPUnit::assertTrue(
            $matched,
            $callback === null ? 'No beacon was recorded.' : 'No recorded beacon matched the callback.',
        );

        return $this;
    }

    /**
     * Assert this many beacons were recorded, optionally of one event name.
     */
    public function assertRecordedCount(int $count, ?string $event = null): static
    {
        $recorded = $event === null
            ? $this->recorded
            : array_filter($this->recorded, fn (BeaconPayload $payload): bool => $payload->event === $event);

        PHPUnit::assertCount($count, $recorded, $event === null
            ? "Expected {$count} recorded beacons."
            : "Expected {$count} recorded [{$event}] beacons.");

        return $this;
    }

    public function assertNothingRecorded(): static
    {
        PHPUnit::assertSame([], $this->recorded, count($this->recorded).' beacons were recorded.');

        return $this;
    }
}
