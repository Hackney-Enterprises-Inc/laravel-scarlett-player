<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * One row per player viewId, merged from every beacon of that view. Column classes
 * (EloquentBeaconStore applies them):
 *
 *   set-once             identity and environment (except is_live), started_at,
 *                        first_frame_at, ended_at, exit_type
 *   true-wins            is_live: any beacon saying true sets it, false only
 *                        fills an empty column, nothing clears it
 *   monotonic            the *_ms and *_count columns, max_bitrate, startup_ms
 *   latest-by-timestamp  qoe_score, avg_bitrate, rebuffer_ratio, completion_rate,
 *                        current_position and the live latency summary, each
 *                        written only when the beacon is at least as new as its
 *                        own *_at stamp (the latency summary shares
 *                        live_latency_at); metrics_at is the newest stamp
 *   custom               custom dimensions, merged key by key, each key's newest
 *                        writer winning by its stamp in custom_stamps (epoch ms per
 *                        key); custom_at is the newest stamp
 *   server               what the host's beacons.context resolver asserted (a user
 *                        id, a tenant), kept apart from custom, which is what the
 *                        browser sent; merged key by key like custom, stamps in
 *                        server_stamps. Only beacons that carry server keys read or
 *                        write these two columns
 *
 * *_at columns other than created_at and updated_at are the player's clock (epoch
 * milliseconds, stored as UTC). There is no IP column unless
 * scarlett-player.beacons.store_ip is on when this migration runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scarlett_views', function (Blueprint $table): void {
            $table->id();
            $table->string('view_id', 191)->unique();
            $table->string('session_id', 191)->index();
            $table->string('viewer_id', 191)->index();
            $table->string('video_id', 191);

            // Resolved through ResolvesMedia when the view is first stored. A string
            // id, so integer and uuid host keys both fit.
            $table->string('viewable_type')->nullable();
            $table->string('viewable_id', 191)->nullable();
            $table->index(['viewable_type', 'viewable_id']);

            $table->string('video_title')->nullable();
            $table->boolean('is_live')->nullable();
            $table->string('player_version')->nullable();
            $table->string('player_name')->nullable();
            $table->string('browser')->nullable();
            $table->string('os')->nullable();
            $table->string('device_type')->nullable();
            $table->string('screen_size')->nullable();
            $table->string('player_size')->nullable();
            $table->string('connection_type')->nullable();

            if (config('scarlett-player.beacons.store_ip')) {
                $table->string('ip_address', 45)->nullable();
            }

            $table->dateTime('started_at', 3)->nullable();
            $table->dateTime('first_frame_at', 3)->nullable();
            $table->dateTime('ended_at', 3)->nullable();
            $table->dateTime('last_event_at', 3)->nullable();
            $table->string('exit_type', 32)->nullable();

            $table->unsignedBigInteger('startup_ms')->nullable();
            $table->unsignedBigInteger('watch_ms')->nullable();
            $table->unsignedBigInteger('play_ms')->nullable();
            $table->unsignedBigInteger('rebuffer_ms')->nullable();
            $table->unsignedInteger('rebuffer_count')->nullable();
            $table->unsignedInteger('seek_count')->nullable();
            $table->unsignedInteger('pause_count')->nullable();
            $table->unsignedInteger('quality_changes')->nullable();
            $table->unsignedInteger('error_count')->nullable();
            $table->unsignedBigInteger('max_bitrate')->nullable();

            $table->double('qoe_score')->nullable();
            $table->dateTime('qoe_score_at', 3)->nullable();
            $table->unsignedBigInteger('avg_bitrate')->nullable();
            $table->dateTime('avg_bitrate_at', 3)->nullable();
            $table->double('rebuffer_ratio')->nullable();
            $table->dateTime('rebuffer_ratio_at', 3)->nullable();
            $table->double('completion_rate')->nullable();
            $table->dateTime('completion_rate_at', 3)->nullable();
            $table->double('current_position')->nullable();
            $table->dateTime('current_position_at', 3)->nullable();
            $table->unsignedInteger('live_latency_samples')->nullable();
            $table->double('live_latency_mean')->nullable();
            $table->double('live_latency_p95')->nullable();
            $table->double('live_latency_max')->nullable();
            $table->boolean('low_latency')->nullable();
            $table->dateTime('live_latency_at', 3)->nullable();
            $table->dateTime('metrics_at', 3)->nullable();

            $table->json('custom')->nullable();
            $table->json('custom_stamps')->nullable();
            $table->dateTime('custom_at', 3)->nullable();
            $table->json('server')->nullable();
            $table->json('server_stamps')->nullable();

            $table->timestamps();

            $table->index(['video_id', 'started_at']);
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scarlett_views');
    }
};
