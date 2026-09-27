<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * The beacons-databases CI job sets DB_CONNECTION. If a misconfiguration let the run
 * fall back to SQLite, every merge test would still pass and the job would report the
 * MySQL or Postgres leg green without touching either. This fails it instead.
 */

it('runs on the engine DB_CONNECTION names', function (): void {
    $wanted = getenv('DB_CONNECTION');

    if ($wanted === false || $wanted === '') {
        $this->markTestSkipped('DB_CONNECTION is not set: the default testbench SQLite run.');
    }

    expect(DB::connection()->getDriverName())->toBe($wanted);
});
