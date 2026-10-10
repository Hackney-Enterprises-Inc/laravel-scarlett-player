<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Migrations 5 and 6 were edited after release to be idempotent. On a schema without
 * their columns they must do exactly what the published 0.5.0 files did.
 */

/** @return array<string, mixed> */
function equivalenceSnapshot(): array
{
    return [
        'views' => Schema::getColumns('scarlett_views'),
        'errors' => Schema::getColumns('scarlett_view_errors'),
        'rows' => DB::table('scarlett_views')->orderBy('view_id')->get()->map(fn (object $row): array => (array) $row)->all(),
    ];
}

function equivalenceDropTables(): void
{
    foreach (['scarlett_clips', 'scarlett_view_errors', 'scarlett_beacon_events', 'scarlett_views'] as $table) {
        Schema::dropIfExists($table);
    }
}

it('changes the schema and data exactly as the 0.5.0 file did', function (string $file, array $before): void {
    $package = __DIR__.'/../../../database/migrations/';
    equivalenceDropTables();
    try {
        foreach (glob($package.'0001_01_01_00000[1234]_*.php') as $create) {
            (require $create)->up();
        }
        foreach ($before as $earlier) {
            (require $package.$earlier)->up();
        }
        DB::table('scarlett_views')->insert([
            ['view_id' => 'scored', 'session_id' => 's', 'viewer_id' => 'v', 'video_id' => 'm', 'qoe_score' => 65],
            ['view_id' => 'unscored', 'session_id' => 's', 'viewer_id' => 'v', 'video_id' => 'm', 'qoe_score' => null],
        ]);
        DB::table('scarlett_view_errors')->insert(['view_id' => 'scored', 'video_id' => 'm', 'event_key' => str_repeat('e', 40), 'code' => 'E1', 'fatal' => false, 'occurred_at' => '2026-10-06 12:00:00.000', 'received_at' => '2026-10-06 12:00:01.000']);

        $old = require __DIR__.'/../../Fixtures/migrations/0.5.0/'.$file;
        $new = require $package.$file;
        $initial = equivalenceSnapshot();

        $old->up();
        $upgraded = equivalenceSnapshot();
        $old->down();
        expect(equivalenceSnapshot())->toEqual($initial);

        $new->up();
        expect(equivalenceSnapshot())->toEqual($upgraded);
        $new->down();
        expect(equivalenceSnapshot())->toEqual($initial);
    } finally {
        equivalenceDropTables();
    }
})->with([
    'signals' => ['0001_01_01_000005_add_signals_to_scarlett_tables.php', []],
    'reconnects' => ['0001_01_01_000006_add_reconnects_to_scarlett_tables.php', ['0001_01_01_000005_add_signals_to_scarlett_tables.php']],
]);
