<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Data\BeaconPayload;
use Hei\ScarlettPlayer\Doctor\CheckRegistry;
use Hei\ScarlettPlayer\Doctor\Checks\BeaconStartStateColumnsCheck;
use Hei\ScarlettPlayer\Doctor\CheckStatus;
use Hei\ScarlettPlayer\Jobs\ProcessBeacon;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\Beacons;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Why a view never played (0.10.0): the playBlocked beacon, the viewEnd startState
 * and the stalled flag on the terminal error of the player's stall ceiling. The
 * player names are in its analytics types.ts: StartState, BeaconPayload.stalled,
 * AnalyticsEventType 'playBlocked'. Wire fixtures for these are not captured (the
 * capture harness's Chromium allows autoplay), so the bodies here are built by hand.
 */

function startStateMigration(): object
{
    return require __DIR__.'/../../../database/migrations/0001_01_01_000010_add_start_state_and_stalled_to_scarlett_tables.php';
}

beforeEach(function (): void {
    $this->usesMigrations();
    $this->deliver = function (BeaconPayload ...$payloads): void {
        foreach ($payloads as $payload) {
            app()->call([new ProcessBeacon($payload), 'handle']);
        }
    };
    $this->view = fn (): object => DB::table('scarlett_views')->where('view_id', Beacons::VIEW)->sole();
});

it('stores playBlocked as a raw event with its fields in custom', function (): void {
    ($this->deliver)(
        Beacons::payload('viewStart'),
        Beacons::payload('playBlocked', 10, ['reason' => 'autoplay', 'errorName' => 'NotAllowedError', 'readyState' => 1]),
    );

    $event = DB::table('scarlett_beacon_events')->where('event', 'playBlocked')->sole();
    $stored = json_decode((string) $event->payload, true);
    $custom = json_decode((string) ($this->view)()->custom, true);

    expect($stored)->toMatchArray(['reason' => 'autoplay', 'errorName' => 'NotAllowedError', 'readyState' => 1])
        ->and($custom)->toMatchArray(['reason' => 'autoplay', 'errorName' => 'NotAllowedError']);
});

it('fills start_state from the viewEnd, set once', function (string $state): void {
    ($this->deliver)(
        Beacons::payload('viewStart'),
        Beacons::payload('viewEnd', 20_000, ['exitType' => 'abandoned', 'startState' => $state]),
        // A second viewEnd does not move it.
        Beacons::payload('viewEnd', 21_000, ['exitType' => 'abandoned', 'startState' => 'played']),
    );

    expect(($this->view)()->start_state)->toBe($state);
})->with(['played', 'awaitingTap', 'failed', 'never']);

it('leaves start_state null for a view whose beacons never carry it', function (): void {
    ($this->deliver)(Beacons::payload('viewStart'), Beacons::payload('viewEnd', 5_000, ['exitType' => 'ended']));

    expect(($this->view)()->start_state)->toBeNull();
});

it('cuts a start_state longer than the column so a hostile value cannot fail the beacon', function (): void {
    ($this->deliver)(Beacons::payload('viewEnd', 5_000, ['exitType' => 'abandoned', 'startState' => str_repeat('x', 200)]));

    expect(mb_strlen((string) ($this->view)()->start_state))->toBe(16);
});

it('keeps a startState of another type as a custom dimension', function (): void {
    ($this->deliver)(Beacons::payload('viewEnd', 5_000, ['exitType' => 'abandoned', 'startState' => 7]));

    expect(($this->view)()->start_state)->toBeNull()
        ->and(json_decode((string) ($this->view)()->custom, true))->toHaveKey('startState', 7);
});

it('stores stalled on the error row beside reconnect_exhausted', function (): void {
    ($this->deliver)(
        Beacons::payload('viewStart'),
        Beacons::payload('error', 1_000, [
            'errorType' => 'MEDIA_NETWORK_ERROR', 'errorMessage' => 'Playback stalled for 120s and did not recover',
            'errorCode' => 'MEDIA_NETWORK_ERROR', 'fatal' => true, 'errorCategory' => 'network', 'errorSeverity' => 'fatal',
            'retriesExhausted' => true, 'reconnectExhausted' => true, 'stalled' => true,
        ]),
        Beacons::payload('error', 2_000, [
            'errorType' => 'MEDIA_NETWORK_ERROR', 'errorMessage' => 'HLS auto-reconnect gave up',
            'fatal' => true, 'errorSeverity' => 'fatal', 'reconnectExhausted' => true,
        ]),
    );

    $rows = DB::table('scarlett_view_errors')->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and((bool) $rows[0]->stalled)->toBeTrue()
        ->and((bool) $rows[0]->reconnect_exhausted)->toBeTrue()
        ->and($rows[1]->stalled)->toBeNull();
});

it('keeps ingesting on a schema without the start state columns', function (): void {
    startStateMigration()->down();

    try {
        ($this->deliver)(
            Beacons::payload('viewStart'),
            Beacons::payload('error', 1_000, ['errorType' => 'x', 'errorMessage' => 'y', 'fatal' => true, 'errorSeverity' => 'fatal', 'stalled' => true]),
            Beacons::payload('viewEnd', 5_000, ['exitType' => 'error', 'startState' => 'failed']),
        );

        expect(($this->view)()->exit_type)->toBe('error')
            ->and(Schema::hasColumn('scarlett_views', 'start_state'))->toBeFalse()
            ->and(DB::table('scarlett_view_errors')->count())->toBe(1);
    } finally {
        startStateMigration()->up();
    }
});

it('keeps startState and stalled only in the raw log, not in custom, while the columns are absent', function (): void {
    startStateMigration()->down();

    try {
        ($this->deliver)(
            Beacons::payload('viewStart'),
            Beacons::payload('error', 1_000, ['errorType' => 'x', 'errorMessage' => 'y', 'fatal' => true, 'errorSeverity' => 'fatal', 'reconnectExhausted' => true, 'stalled' => true]),
            Beacons::payload('viewEnd', 5_000, ['exitType' => 'error', 'startState' => 'failed']),
        );

        $view = ($this->view)();
        $custom = json_decode((string) $view->custom, true) ?? [];
        $raw = DB::table('scarlett_beacon_events')->pluck('payload')->map(fn ($p) => json_decode((string) $p, true))->all();

        expect($custom)->not->toHaveKey('startState')->not->toHaveKey('stalled')
            ->and(collect($raw)->pluck('startState')->filter()->values()->all())->toBe(['failed'])
            ->and(collect($raw)->pluck('stalled')->filter()->values()->all())->toBe([true]);
    } finally {
        startStateMigration()->up();
    }
});

it('is reported by the doctor until the start state migration runs', function (): void {
    expect(app(CheckRegistry::class)->classes())->toContain(BeaconStartStateColumnsCheck::class)
        ->and(app(BeaconStartStateColumnsCheck::class)->run()->status)->toBe(CheckStatus::Pass);

    startStateMigration()->down();

    try {
        $result = app(BeaconStartStateColumnsCheck::class)->run();
        expect($result->status)->toBe(CheckStatus::Warn)
            ->and($result->message)->toContain('scarlett_views.start_state', 'scarlett_view_errors.stalled', '--tag=scarlett-migrations-startstate')
            // Typed known fields are not custom dimensions: only the raw event log keeps them
            ->and($result->message)->toContain('known fields, not custom dimensions', 'raw event log')
            ->and($result->message)->not->toContain('and the custom dimensions');
    } finally {
        startStateMigration()->up();
    }
});

it('runs the start state migration twice and rolls it back', function (): void {
    $migration = startStateMigration();

    $migration->up(); // already applied by usesMigrations: a no-op
    expect(Schema::hasColumn('scarlett_views', 'start_state'))->toBeTrue()
        ->and(Schema::hasColumn('scarlett_view_errors', 'stalled'))->toBeTrue();

    $migration->down();
    expect(Schema::hasColumn('scarlett_views', 'start_state'))->toBeFalse()
        ->and(Schema::hasColumn('scarlett_view_errors', 'stalled'))->toBeFalse();

    $migration->up();
    expect(Schema::hasColumn('scarlett_views', 'start_state'))->toBeTrue();
});

describe('jobs queued across the upgrade', function (): void {
    /** A 1.26-era viewEnd as 0.9.x queued it: startState custom, serial version 2. */
    function queuedBy09(): BeaconPayload
    {
        $fresh = Beacons::payload('viewEnd', 5_000, ['exitType' => 'abandoned', 'startState' => 'awaitingTap', 'tenant' => 'wire']);
        $old = new BeaconPayload(
            $fresh->event, $fresh->timestamp, $fresh->viewId, $fresh->sessionId, $fresh->viewerId, $fresh->videoId,
            $fresh->context, ['exitType' => 'abandoned'], ['startState' => 'awaitingTap', 'tenant' => 'wire'],
            bodyHash: $fresh->bodyHash,
        );
        $serialized = str_replace('s:13:"serialVersion";i:3;', 's:13:"serialVersion";i:2;', serialize($old));
        expect($serialized)->toContain('s:13:"serialVersion";i:2;');

        return unserialize($serialized);
    }

    it('fills start_state from a 0.9.x job, keeping its queued classification and hash', function (): void {
        $restored = queuedBy09();
        $fresh = Beacons::payload('viewEnd', 5_000, ['exitType' => 'abandoned', 'startState' => 'awaitingTap', 'tenant' => 'wire']);

        expect($restored->knownValue('startState'))->toBe('awaitingTap')
            ->and($restored->custom)->toHaveKey('startState')
            ->and($restored->bodyHash)->toBe($fresh->bodyHash);

        ($this->deliver)($restored);
        ($this->deliver)($fresh);

        expect(($this->view)()->start_state)->toBe('awaitingTap')
            ->and(DB::table('scarlett_beacon_events')->count())->toBe(1);
    });

    it('fills start_state from the restored 0.9.x job alone, with no fresh copy to fill it', function (): void {
        ($this->deliver)(queuedBy09());

        expect(($this->view)()->start_state)->toBe('awaitingTap');
    });

    it('fills stalled on the error row from a restored 0.9.x job alone', function (): void {
        $fresh = Beacons::payload('error', 1_000, ['errorType' => 'x', 'errorMessage' => 'y', 'fatal' => true, 'errorSeverity' => 'fatal', 'reconnectExhausted' => true, 'stalled' => true]);
        $old = new BeaconPayload(
            $fresh->event, $fresh->timestamp, $fresh->viewId, $fresh->sessionId, $fresh->viewerId, $fresh->videoId,
            $fresh->context, ['errorType' => 'x', 'errorMessage' => 'y', 'fatal' => true, 'errorSeverity' => 'fatal', 'reconnectExhausted' => true],
            ['stalled' => true],
            bodyHash: $fresh->bodyHash,
        );
        $serialized = str_replace('s:13:"serialVersion";i:3;', 's:13:"serialVersion";i:2;', serialize($old));
        expect($serialized)->toContain('s:13:"serialVersion";i:2;');

        ($this->deliver)(Beacons::payload('viewStart'), unserialize($serialized));

        expect(DB::table('scarlett_view_errors')->sole()->stalled)->toBe(1);
    });

    it('never captures a pipeline dimension named startState', function (): void {
        $piped = unserialize(serialize(Beacons::payload('viewEnd', 5_000)->withCustom(['startState' => 'never'])));

        expect($piped->knownValue('startState'))->toBeNull()
            ->and($piped->custom)->toBe(['startState' => 'never']);
    });
});
