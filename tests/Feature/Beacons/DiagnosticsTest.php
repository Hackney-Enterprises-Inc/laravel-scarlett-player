<?php

declare(strict_types=1);

use Hei\ScarlettPlayer\Data\BeaconPayload;
use Hei\ScarlettPlayer\Doctor\CheckRegistry;
use Hei\ScarlettPlayer\Doctor\Checks\BeaconDiagnosticColumnsCheck;
use Hei\ScarlettPlayer\Doctor\CheckStatus;
use Hei\ScarlettPlayer\Exceptions\UpgradeMigrationOrderException;
use Hei\ScarlettPlayer\Jobs\ProcessBeacon;
use Hei\ScarlettPlayer\Tests\Fixtures\Beacons\Beacons;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The diagnostics upgrade (migration 8, 0.8.0): the player 1.25.0 frame totals
 * as monotonic view columns, and viewer_ref, the host's id for the viewer copied
 * once from the server context and indexed for support lookups.
 */

const DIAGNOSTICS_MIGRATION = __DIR__.'/../../../database/migrations/0001_01_01_000008_add_diagnostics_to_scarlett_tables.php';

beforeEach(function (): void {
    $this->usesMigrations();
    config()->set('queue.default', 'sync');
    $this->deliver = function (BeaconPayload $payload): void {
        app()->call([new ProcessBeacon($payload), 'handle']);
    };
});

function diagnosticsRow(string $viewId = Beacons::VIEW): object
{
    return DB::table('scarlett_views')->where('view_id', $viewId)->sole();
}

describe('frame totals', function (): void {
    it('keeps the larger running total in any delivery order', function (bool $reverse): void {
        $early = Beacons::payload('heartbeat', 10, ['decodedFramesTotal' => 100, 'droppedFramesTotal' => 1]);
        $late = Beacons::payload('viewEnd', 20, ['exitType' => 'ended', 'decodedFramesTotal' => 397, 'droppedFramesTotal' => 4]);

        foreach ($reverse ? [$late, $early] : [$early, $late] as $payload) {
            ($this->deliver)($payload);
        }

        $row = diagnosticsRow();
        expect((int) $row->decoded_frames_total)->toBe(397)
            ->and((int) $row->dropped_frames_total)->toBe(4)
            ->and($row->custom)->toBeNull();
    })->with(['in order' => false, 'reversed' => true]);

    it('leaves the totals alone on a beacon without them and keeps a wrong type as custom', function (): void {
        ($this->deliver)(Beacons::payload('heartbeat', 10, ['decodedFramesTotal' => 50, 'droppedFramesTotal' => 2]));
        ($this->deliver)(Beacons::payload('pause', 20, ['droppedFramesTotal' => 'many']));

        $row = diagnosticsRow();
        expect((int) $row->decoded_frames_total)->toBe(50)
            ->and((int) $row->dropped_frames_total)->toBe(2)
            ->and(json_decode((string) $row->custom, true))->toBe(['droppedFramesTotal' => 'many']);
    });
});

describe('viewer_ref', function (): void {
    it('copies the server context user_id once, and a guest beacon leaves it', function (): void {
        ($this->deliver)(Beacons::payload('viewStart', 0)->withServer(['user_id' => 4182, 'site_id' => 3]));
        ($this->deliver)(Beacons::payload('heartbeat', 10, ['watchTime' => 5]));
        ($this->deliver)(Beacons::payload('heartbeat', 20, ['watchTime' => 9])->withServer(['user_id' => 99]));

        expect(diagnosticsRow()->viewer_ref)->toBe('4182');
    });

    it('fills from a later beacon when the view started as a guest', function (): void {
        ($this->deliver)(Beacons::payload('viewStart', 0)->withServer(['user_id' => null]));
        expect(diagnosticsRow()->viewer_ref)->toBeNull();

        ($this->deliver)(Beacons::payload('heartbeat', 10)->withServer(['user_id' => 'u-77']));
        expect(diagnosticsRow()->viewer_ref)->toBe('u-77');
    });

    it('never takes a browser dimension of the same name', function (): void {
        ($this->deliver)(Beacons::payload('viewStart', 0, ['user_id' => 'spoofed']));

        $row = diagnosticsRow();
        expect($row->viewer_ref)->toBeNull()
            ->and(json_decode((string) $row->custom, true))->toBe(['user_id' => 'spoofed']);
    });

    it('reads the configured key, trims and bounds the value, and skips unusable ones', function (mixed $value, ?string $expected): void {
        config()->set('scarlett-player.beacons.viewer_ref_key', 'account');
        ($this->deliver)(Beacons::payload('viewStart', 0)->withServer(['account' => $value, 'user_id' => 1]));

        expect(diagnosticsRow()->viewer_ref)->toBe($expected);
    })->with([
        'string' => ['acct-9', 'acct-9'],
        'integer' => [12, '12'],
        'padded' => ['  acct-9 ', 'acct-9'],
        'long' => [str_repeat('a', 300), str_repeat('a', 191)],
        'empty' => ['', null],
        'boolean' => [true, null],
        'float' => [1.5, null],
        'array' => [['id' => 1], null],
    ]);

    it('defaults to user_id for a published config from before 0.8.0', function (): void {
        $beacons = config('scarlett-player.beacons');
        unset($beacons['viewer_ref_key']);
        config()->set('scarlett-player.beacons', $beacons);
        ($this->deliver)(Beacons::payload('viewStart', 0)->withServer(['user_id' => 4182]));

        expect(diagnosticsRow()->viewer_ref)->toBe('4182');
    });

    it('is off when no key is configured', function (): void {
        config()->set('scarlett-player.beacons.viewer_ref_key', null);
        ($this->deliver)(Beacons::payload('viewStart', 0)->withServer(['user_id' => 4182]));

        $row = diagnosticsRow();
        expect($row->viewer_ref)->toBeNull()
            ->and(json_decode((string) $row->server, true))->toBe(['user_id' => 4182]);
    });
});

describe('the diagnostics migration', function (): void {
    it('keeps ingesting on an older schema without the diagnostic columns', function (): void {
        $migration = require DIAGNOSTICS_MIGRATION;
        $migration->down();

        try {
            expect(Schema::hasColumn('scarlett_views', 'viewer_ref'))->toBeFalse();
            ($this->deliver)(Beacons::payload('heartbeat', 10, ['watchTime' => 5, 'decodedFramesTotal' => 50, 'droppedFramesTotal' => 2])->withServer(['user_id' => 4182]));
            ($this->deliver)(Beacons::payload('viewEnd', 20, ['exitType' => 'ended', 'decodedFramesTotal' => 60, 'droppedFramesTotal' => 2])->withServer(['user_id' => 4182]));

            $row = diagnosticsRow();
            $raw = json_decode((string) DB::table('scarlett_beacon_events')->where('event', 'viewEnd')->sole()->payload, true);
            expect((int) $row->watch_ms)->toBe(5)
                ->and(json_decode((string) $row->server, true))->toBe(['user_id' => 4182])
                ->and($raw)->toMatchArray(['decodedFramesTotal' => 60, 'droppedFramesTotal' => 2]);
        } finally {
            $migration->up();
        }
    });

    it('reverses only its own columns and index, and migrates again as a no-op', function (): void {
        $migration = require DIAGNOSTICS_MIGRATION;
        $migration->down();

        try {
            expect(Schema::hasColumns('scarlett_views', ['decoded_frames_total', 'dropped_frames_total', 'viewer_ref']))->toBeFalse()
                ->and(Schema::hasColumns('scarlett_views', ['completion_ratio', 'reconnect_count', 'qoe_version', 'viewer_id']))->toBeTrue();
        } finally {
            $migration->up();
        }

        $migration->up();
        expect(Schema::hasColumns('scarlett_views', ['decoded_frames_total', 'dropped_frames_total', 'viewer_ref']))->toBeTrue()
            ->and(Schema::hasIndex('scarlett_views', ['viewer_ref']))->toBeTrue();
    });

    it('stops before changing anything when the copy sorts before the views table', function (): void {
        Schema::dropIfExists('scarlett_views');
        $migration = require DIAGNOSTICS_MIGRATION;

        try {
            expect(fn (): mixed => $migration->up())->toThrow(UpgradeMigrationOrderException::class, 'scarlett_views');
        } finally {
            foreach (['000001_create_scarlett_views_table', '000005_add_signals_to_scarlett_tables', '000006_add_reconnects_to_scarlett_tables', '000007_add_gauges_to_scarlett_tables'] as $name) {
                (require __DIR__.'/../../../database/migrations/0001_01_01_'.$name.'.php')->up();
            }
            $migration->up();
        }
    });

    it('is reported by the doctor until it runs', function (): void {
        expect(app(CheckRegistry::class)->classes())->toContain(BeaconDiagnosticColumnsCheck::class)
            ->and(app(BeaconDiagnosticColumnsCheck::class)->run()->status)->toBe(CheckStatus::Pass);

        $migration = require DIAGNOSTICS_MIGRATION;
        $migration->down();

        try {
            $result = app(BeaconDiagnosticColumnsCheck::class)->run();
            expect($result->status)->toBe(CheckStatus::Warn)
                ->and($result->message)->toContain('scarlett_views.viewer_ref', '--tag=scarlett-migrations-diagnostics');
        } finally {
            $migration->up();
        }
    });
});

describe('jobs queued across the upgrade', function (): void {
    /** A 1.25.0 heartbeat as 0.7.0 queued it: frame totals custom, serial version 1. */
    function queuedBy070(): BeaconPayload
    {
        $fresh = Beacons::payload('heartbeat', 10, ['watchTime' => 5, 'decodedFramesTotal' => 396, 'droppedFramesTotal' => 4, 'tenant' => 'wire']);
        $old = new BeaconPayload(
            $fresh->event, $fresh->timestamp, $fresh->viewId, $fresh->sessionId, $fresh->viewerId, $fresh->videoId,
            $fresh->context, ['watchTime' => 5], ['decodedFramesTotal' => 396, 'droppedFramesTotal' => 4, 'tenant' => 'wire'],
            bodyHash: $fresh->bodyHash,
        );
        $serialized = str_replace('s:13:"serialVersion";i:2;', 's:13:"serialVersion";i:1;', serialize($old));
        expect($serialized)->toContain('s:13:"serialVersion";i:1;');

        return unserialize($serialized);
    }

    it('fills the frame columns from a 0.7.0 job, keeping its queued classification and hash', function (): void {
        $restored = queuedBy070();
        $fresh = Beacons::payload('heartbeat', 10, ['watchTime' => 5, 'decodedFramesTotal' => 396, 'droppedFramesTotal' => 4, 'tenant' => 'wire']);

        expect($restored->knownValue('decodedFramesTotal'))->toBe(396)
            ->and($restored->custom)->toHaveKey('decodedFramesTotal')
            ->and($restored->bodyHash)->toBe($fresh->bodyHash);

        ($this->deliver)($restored);
        ($this->deliver)($fresh);

        $row = diagnosticsRow();
        expect((int) $row->decoded_frames_total)->toBe(396)
            ->and((int) $row->dropped_frames_total)->toBe(4)
            ->and(DB::table('scarlett_beacon_events')->count())->toBe(1);
    });

    it('carries the capture through a requeue and never captures a pipeline dimension', function (): void {
        $requeued = unserialize(serialize(queuedBy070()));
        expect($requeued->knownValue('droppedFramesTotal'))->toBe(4);

        $piped = unserialize(serialize(Beacons::payload('heartbeat', 10)->withCustom(['decodedFramesTotal' => 9])));
        expect($piped->knownValue('decodedFramesTotal'))->toBeNull()
            ->and($piped->custom)->toBe(['decodedFramesTotal' => 9]);
    });
});
