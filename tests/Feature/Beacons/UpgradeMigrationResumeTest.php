<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * MySQL DDL is not transactional: a signals run can die between its ALTERs and leave
 * some columns behind. The rerun must finish the schema and still owe the QoE v1
 * backfill when the first run provably stopped before it, and must never stamp
 * scores written under a complete schema.
 */

const RESUME_SIGNALS = __DIR__.'/../../../database/migrations/0001_01_01_000005_add_signals_to_scarlett_tables.php';

function resumeDropTables(): void
{
    foreach (['scarlett_clips', 'scarlett_view_errors', 'scarlett_beacon_events', 'scarlett_views'] as $table) {
        Schema::dropIfExists($table);
    }
}

/** The create migrations run, with one legacy scored view and one unscored view. */
function resumeLegacySchema(): void
{
    resumeDropTables();
    foreach (glob(__DIR__.'/../../../database/migrations/0001_01_01_00000[1234]_*.php') as $create) {
        (require $create)->up();
    }
    DB::table('scarlett_views')->insert([
        ['view_id' => 'scored', 'session_id' => 's', 'viewer_id' => 'v', 'video_id' => 'm', 'qoe_score' => 65],
        ['view_id' => 'unscored', 'session_id' => 's', 'viewer_id' => 'v', 'video_id' => 'm', 'qoe_score' => null],
    ]);
}

function resumeVersion(string $viewId): ?int
{
    $value = DB::table('scarlett_views')->where('view_id', $viewId)->value('qoe_version');

    return $value === null ? null : (int) $value;
}

/** Count write statements and throw right after the given one, as a killed run would stop. */
function resumeInterruptAfter(int &$writes, int &$failAt): void
{
    DB::listen(function (QueryExecuted $query) use (&$writes, &$failAt): void {
        if (preg_match('/^\s*(alter|update)\b/i', $query->sql) !== 1) {
            return;
        }
        $writes++;
        if ($writes === $failAt) {
            throw new RuntimeException('interrupted after write '.$writes);
        }
    });
}

it('resumes the signals upgrade and its backfill after an interruption at every write boundary', function (): void {
    $writes = 0;
    $failAt = 0;
    resumeInterruptAfter($writes, $failAt);

    try {
        // A clean run counts the boundaries (MySQL creates indexes with ALTER too).
        resumeLegacySchema();
        $writes = 0;
        (require RESUME_SIGNALS)->up();
        $total = $writes;
        expect($total)->toBeGreaterThan(2);

        for ($boundary = 1; $boundary <= $total; $boundary++) {
            resumeLegacySchema();
            $writes = 0;
            $failAt = $boundary;
            try {
                (require RESUME_SIGNALS)->up();
                $this->fail('the run was not interrupted at write '.$boundary);
            } catch (RuntimeException $e) {
                expect($e->getMessage())->toBe('interrupted after write '.$boundary);
            }

            $failAt = 0;
            (require RESUME_SIGNALS)->up();

            expect(resumeVersion('scored'))->toBe(1, 'boundary '.$boundary)
                ->and(resumeVersion('unscored'))->toBeNull()
                ->and(Schema::hasColumns('scarlett_views', ['qoe_version', 'warning_count', 'dropped_frames_at']))->toBeTrue()
                ->and(Schema::hasColumns('scarlett_view_errors', ['category', 'timed_out']))->toBeTrue();
        }
    } finally {
        $failAt = 0;
        resumeDropTables();
    }
});

it('never backfills again on a complete schema', function (): void {
    try {
        resumeLegacySchema();
        (require RESUME_SIGNALS)->up();

        // A score the store kept with an unusable version stays unversioned.
        DB::table('scarlett_views')->where('view_id', 'unscored')->update(['qoe_score' => 80]);
        (require RESUME_SIGNALS)->up();

        expect(resumeVersion('scored'))->toBe(1)
            ->and(resumeVersion('unscored'))->toBeNull();
    } finally {
        resumeDropTables();
    }
});

it('owes the backfill only when qoe_version is the sole signals column present', function (array $handAdded, ?int $expected): void {
    try {
        resumeLegacySchema();
        Schema::table('scarlett_views', function ($table) use ($handAdded): void {
            foreach ($handAdded as $column) {
                $column === 'qoe_version' || $column === 'warning_count'
                    ? $table->unsignedInteger($column)->nullable()
                    : $table->double($column)->nullable();
            }
        });

        (require RESUME_SIGNALS)->up();

        expect(resumeVersion('scored'))->toBe($expected)
            ->and(resumeVersion('unscored'))->toBeNull()
            ->and(Schema::hasColumns('scarlett_views', ['qoe_version', 'warning_count', 'dropped_frames_at']))->toBeTrue();
    } finally {
        resumeDropTables();
    }
})->with([
    // Indistinguishable from a run that died before its backfill: owed.
    'only qoe_version' => [['qoe_version'], 1],
    'qoe_version and warning_count' => [['qoe_version', 'warning_count'], null],
    'qoe_version and an interval' => [['qoe_version', 'segment_count'], null],
    'no qoe_version' => [['warning_count'], 1],
]);
