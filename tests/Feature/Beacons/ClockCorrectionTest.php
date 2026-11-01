<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Hei\ScarlettPlayer\Data\BeaconPayload;
use Hei\ScarlettPlayer\Doctor\CheckRegistry;
use Hei\ScarlettPlayer\Doctor\Checks\BeaconClockColumnsCheck;
use Hei\ScarlettPlayer\Doctor\CheckStatus;
use Hei\ScarlettPlayer\Jobs\ProcessBeacon;
use Hei\ScarlettPlayer\Stores\EloquentBeaconStore;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\Beacons;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Clock-skew correction at ingest (0.9.0, HEI-LSP-14). Every *_at column is the
 * beacon's timestamp shifted by (receipt - sentAt); received_at is the request
 * time; the raw client time is kept. Identity keys stay on the raw clock.
 */

function clockStamp(mixed $value): ?string
{
    return $value === null ? null : CarbonImmutable::parse((string) $value, 'UTC')->format('Y-m-d H:i:s.v');
}

function clockAt(int $ms): string
{
    return EloquentBeaconStore::clientTime($ms);
}

beforeEach(function (): void {
    $this->usesMigrations();
    $this->deliver = function (BeaconPayload ...$payloads): void {
        foreach ($payloads as $payload) {
            app()->call([new ProcessBeacon($payload), 'handle']);
        }
    };
    $this->view = fn (): object => DB::table('scarlett_views')->where('view_id', Beacons::VIEW)->sole();

    // A player whose clock is 120 s fast: it sends at client time X while the
    // server receives it 40 ms (network) after the true time X - 120 s.
    $this->fast = fn (string $event, int $offsetMs = 0, array $extra = []): BeaconPayload => Beacons::payload($event, $offsetMs, [
        'sentAt' => Beacons::T0 + $offsetMs + 120_000, ...$extra,
    ])->withReceivedAt(Beacons::T0 + $offsetMs + 40);
});

it('stores a fast clock 120 s earlier and keeps the raw time', function (): void {
    ($this->deliver)(($this->fast)('viewStart'));

    $view = ($this->view)();

    // timestamp T0, sentAt T0 + 120 000, received T0 + 40 => offset -119 960.
    expect(clockStamp($view->started_at))->toBe(clockAt(Beacons::T0 - 119_960))
        ->and(clockStamp($view->last_event_at))->toBe(clockAt(Beacons::T0 - 119_960))
        ->and($view->clock_source)->toBe('sentAt')
        ->and((int) $view->clock_offset_ms)->toBe(-119_960);

    $event = DB::table('scarlett_beacon_events')->where('view_id', Beacons::VIEW)->sole();

    expect(clockStamp($event->occurred_at))->toBe(clockAt(Beacons::T0 - 119_960))
        ->and(clockStamp($event->client_occurred_at))->toBe(clockAt(Beacons::T0))
        ->and((int) $event->clock_offset_ms)->toBe(-119_960)
        ->and(clockStamp($event->received_at))->toBe(clockAt(Beacons::T0 + 40));
});

it('stores the same stamps in either delivery order', function (bool $reverse): void {
    $beacons = [
        ($this->fast)('viewStart'),
        ($this->fast)('heartbeat', 10_000, ['watchTime' => 10_000, 'qoeScore' => 80.0]),
        ($this->fast)('viewEnd', 20_000, ['exitType' => 'ended', 'watchTime' => 20_000, 'qoeScore' => 90.0]),
    ];

    ($this->deliver)(...($reverse ? array_reverse($beacons) : $beacons));

    $view = ($this->view)();

    expect(clockStamp($view->started_at))->toBe(clockAt(Beacons::T0 - 119_960))
        ->and(clockStamp($view->ended_at))->toBe(clockAt(Beacons::T0 + 20_000 - 119_960))
        ->and(clockStamp($view->last_event_at))->toBe(clockAt(Beacons::T0 + 20_000 - 119_960))
        ->and((float) $view->qoe_score)->toBe(90.0);
})->with(['in order' => false, 'reversed' => true]);

it('gives each beacon its own offset when their clocks differ', function (): void {
    $slow = Beacons::payload('heartbeat', 10_000, ['watchTime' => 10_000, 'sentAt' => Beacons::T0 + 10_000 - 3_000])
        ->withReceivedAt(Beacons::T0 + 10_000 + 40);

    ($this->deliver)(($this->fast)('viewStart'), $slow);

    $events = DB::table('scarlett_beacon_events')->orderBy('id')->get();

    expect(clockStamp($events[0]->occurred_at))->toBe(clockAt(Beacons::T0 - 119_960))
        ->and(clockStamp($events[1]->occurred_at))->toBe(clockAt(Beacons::T0 + 10_000 + 3_040));
});

it('records the offset on the view only from the beacon that creates the row', function (): void {
    ($this->deliver)(
        ($this->fast)('heartbeat', 10_000, ['watchTime' => 10_000]),
        Beacons::payload('viewStart')->withReceivedAt(Beacons::T0 + 40), // no sentAt
    );

    $view = ($this->view)();

    expect($view->clock_source)->toBe('sentAt')
        ->and((int) $view->clock_offset_ms)->toBe(-119_960);
});

it('stores a beacon without sentAt as received, tagged none', function (): void {
    ($this->deliver)(Beacons::payload('viewStart')->withReceivedAt(Beacons::T0 + 5_000));

    $view = ($this->view)();
    $event = DB::table('scarlett_beacon_events')->sole();

    expect(clockStamp($view->started_at))->toBe(clockAt(Beacons::T0))
        ->and($view->clock_source)->toBe('none')
        ->and($view->clock_offset_ms)->toBeNull()
        ->and(clockStamp($event->received_at))->toBe(clockAt(Beacons::T0 + 5_000))
        ->and($event->clock_offset_ms)->toBeNull();
});

it('stores an offset beyond the cap uncorrected and tagged rejected', function (): void {
    config()->set('scarlett-player.beacons.max_clock_offset', 3_600);

    ($this->deliver)(Beacons::payload('viewStart', 0, ['sentAt' => Beacons::T0 + 7_200_000])->withReceivedAt(Beacons::T0));

    $view = ($this->view)();

    expect(clockStamp($view->started_at))->toBe(clockAt(Beacons::T0))
        ->and($view->clock_source)->toBe('rejected');
});

it('stores a job queued by 0.8.0 (no receipt time) uncorrected, with received_at at job time', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00:00', 'UTC'));

    ($this->deliver)(Beacons::payload('viewStart', 0, ['sentAt' => Beacons::T0 + 120_000]));

    $view = ($this->view)();
    $event = DB::table('scarlett_beacon_events')->sole();

    expect(clockStamp($view->started_at))->toBe(clockAt(Beacons::T0))
        ->and($view->clock_source)->toBe('none')
        ->and(clockStamp($event->received_at))->toBe('2026-11-05 10:00:00.000');
});

it('keeps the event key and the custom stamps on the raw client clock', function (): void {
    $raw = Beacons::payload('viewStart', 0, ['sentAt' => Beacons::T0 + 120_000, 'plan' => 'pro']);
    $corrected = $raw->withReceivedAt(Beacons::T0 + 40);

    ($this->deliver)($corrected);

    $event = DB::table('scarlett_beacon_events')->sole();

    expect($event->event_key)->toBe(EloquentBeaconStore::eventKey($raw))
        ->and(EloquentBeaconStore::customStamp($corrected))->toBe(EloquentBeaconStore::customStamp($raw));
});

it('a redelivered beacon is still one row', function (): void {
    $beacon = ($this->fast)('viewStart');

    ($this->deliver)($beacon, $beacon);

    expect(DB::table('scarlett_beacon_events')->count())->toBe(1);
});

it('corrects an error row and keeps its raw time', function (): void {
    ($this->deliver)(($this->fast)('error', 1_000, [
        'errorType' => 'network', 'errorMessage' => 'boom', 'fatal' => false,
    ]));

    $error = DB::table('scarlett_view_errors')->sole();

    expect(clockStamp($error->occurred_at))->toBe(clockAt(Beacons::T0 + 1_000 - 119_960))
        ->and(clockStamp($error->client_occurred_at))->toBe(clockAt(Beacons::T0 + 1_000))
        ->and((int) $error->clock_offset_ms)->toBe(-119_960);
});

it('keeps ingesting before the clock migration has run', function (): void {
    Schema::table('scarlett_views', fn ($t) => $t->dropColumn(['clock_offset_ms', 'clock_source']));
    Schema::table('scarlett_beacon_events', fn ($t) => $t->dropColumn(['client_occurred_at', 'clock_offset_ms']));
    Schema::table('scarlett_view_errors', fn ($t) => $t->dropColumn(['client_occurred_at', 'clock_offset_ms']));

    ($this->deliver)(($this->fast)('viewStart'), ($this->fast)('error', 1_000, ['errorType' => 'x', 'errorMessage' => 'y']));

    // Still corrected: the stamps are existing columns; only the raw copies are skipped.
    expect(clockStamp(($this->view)()->started_at))->toBe(clockAt(Beacons::T0 - 119_960));
});

it('is reported by the doctor until the clock migration runs', function (): void {
    $migration = require __DIR__.'/../../../database/migrations/0001_01_01_000009_add_clock_correction_to_scarlett_tables.php';

    expect(app(CheckRegistry::class)->classes())->toContain(BeaconClockColumnsCheck::class)
        ->and(app(BeaconClockColumnsCheck::class)->run()->status)->toBe(CheckStatus::Pass);

    $migration->down();

    try {
        $result = app(BeaconClockColumnsCheck::class)->run();
        expect($result->status)->toBe(CheckStatus::Warn)
            ->and($result->message)->toContain('scarlett_views.clock_source', '--tag=scarlett-migrations-clock');
    } finally {
        $migration->up();
    }
});

it('runs the clock migration twice and rolls it back', function (): void {
    $migration = require __DIR__.'/../../../database/migrations/0001_01_01_000009_add_clock_correction_to_scarlett_tables.php';

    $migration->up(); // already applied by usesMigrations: a no-op
    expect(Schema::hasColumns('scarlett_views', ['clock_offset_ms', 'clock_source']))->toBeTrue();

    $migration->down();
    expect(Schema::hasColumn('scarlett_views', 'clock_source'))->toBeFalse()
        ->and(Schema::hasColumn('scarlett_beacon_events', 'client_occurred_at'))->toBeFalse();

    $migration->up();
    expect(Schema::hasColumn('scarlett_view_errors', 'clock_offset_ms'))->toBeTrue();
});

it('writes a signed offset the column can hold', function (): void {
    ($this->deliver)(Beacons::payload('viewStart', 0, ['sentAt' => Beacons::T0 + 80_000_000])->withReceivedAt(Beacons::T0));

    expect((int) ($this->view)()->clock_offset_ms)->toBe(-80_000_000);
});
