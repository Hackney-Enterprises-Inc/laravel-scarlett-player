<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Player 1.22.0 additions. View counters are the player's running totals, merged
 * monotonic like the other counters: element_seek_count (raw element seeks; seek_count
 * coalesces their bursts), reconnect_count (outages, not attempts), reconnect_ms (time
 * in those outages, overlapping rebuffer_ms after the first frame), dvr_ms (live
 * play time behind the edge, null on VOD) and pause_ms (time paused, an open pause
 * included). media_duration is the media's length in seconds, latest by timestamp
 * under media_duration_at, from heartbeats only (recovered and rebufferEnd reuse
 * `duration` for milliseconds) and only when finite, above zero and not on a beacon
 * saying live. Meaningful only where is_live is not true: hls.js reports a live
 * sliding window, though only after the player has classified the view live. Error
 * rows gain the beacon's context.
 *
 * Existing rows are not rewritten: before 1.22.0 the player sent 0 for an unknown
 * bitrate, so historical avg_bitrate and max_bitrate zeroes stay as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scarlett_views', function (Blueprint $table): void {
            $table->unsignedInteger('element_seek_count')->nullable();
            $table->unsignedInteger('reconnect_count')->nullable();
            $table->unsignedBigInteger('reconnect_ms')->nullable();
            $table->unsignedBigInteger('dvr_ms')->nullable();
            $table->unsignedBigInteger('pause_ms')->nullable();
            $table->double('media_duration')->nullable();
            $table->dateTime('media_duration_at', 3)->nullable();
        });

        Schema::table('scarlett_view_errors', function (Blueprint $table): void {
            $table->unsignedInteger('network_state')->nullable();
            $table->unsignedInteger('ready_state')->nullable();
            $table->boolean('online')->nullable();
            $table->string('source_host')->nullable();
            $table->boolean('reconnecting')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('scarlett_view_errors', function (Blueprint $table): void {
            $table->dropColumn(['network_state', 'ready_state', 'online', 'source_host', 'reconnecting']);
        });

        Schema::table('scarlett_views', function (Blueprint $table): void {
            $table->dropColumn([
                'element_seek_count', 'reconnect_count', 'reconnect_ms', 'dvr_ms',
                'pause_ms', 'media_duration', 'media_duration_at',
            ]);
        });
    }
};
