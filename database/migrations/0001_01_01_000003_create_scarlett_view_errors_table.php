<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * One row per error beacon, for "which videos are erroring". video_id is copied from
 * the beacon so that question needs no join. event_key is unique, as on
 * scarlett_beacon_events, so a redelivered error inserts nothing and fires nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scarlett_view_errors', function (Blueprint $table): void {
            $table->id();
            $table->string('view_id', 191)->index();
            $table->string('video_id', 191)->index();
            $table->char('event_key', 40)->unique();
            $table->string('type')->nullable();
            $table->text('message')->nullable();
            $table->string('code')->nullable();
            $table->boolean('fatal')->default(false);
            $table->dateTime('occurred_at', 3);
            $table->dateTime('received_at', 3)->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scarlett_view_errors');
    }
};
