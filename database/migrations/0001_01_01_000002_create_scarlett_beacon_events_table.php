<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Every beacon as received, append-only, while beacons.store_raw_events is on. What
 * makes a bug in aggregation recoverable, and about 90% heartbeats, so it is pruned
 * on beacons.retention.events by received_at (the server clock). event_key is
 * sha1(viewId . event . timestamp . sha1(payload)) and unique: a redelivered job
 * inserts nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scarlett_beacon_events', function (Blueprint $table): void {
            $table->id();
            $table->string('view_id', 191)->index();
            $table->string('event', 191);
            $table->char('event_key', 40)->unique();
            $table->dateTime('occurred_at', 3);
            $table->json('payload');
            $table->dateTime('received_at', 3)->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scarlett_beacon_events');
    }
};
