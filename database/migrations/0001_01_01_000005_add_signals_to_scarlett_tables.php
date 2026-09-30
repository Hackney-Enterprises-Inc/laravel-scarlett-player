<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INTERVALS = [
        'segment_count', 'segment_bytes', 'segment_load_avg_ms', 'segment_load_max_ms',
        'segment_errors', 'segment_throughput_bps', 'decoded_frames', 'dropped_frames',
    ];

    public function up(): void
    {
        Schema::table('scarlett_views', function (Blueprint $table): void {
            $table->unsignedInteger('qoe_version')->nullable();
            $table->unsignedInteger('warning_count')->nullable();
            $table->string('fatal_error_category')->nullable();
            $table->boolean('anonymous')->nullable();
            $table->text('page_url')->nullable();
            $table->text('referrer_origin')->nullable();
            $table->double('page_load_to_init_ms')->nullable();
            $table->double('player_init_ms')->nullable();

            // These are the latest reported intervals, not cumulative view totals.
            foreach (self::INTERVALS as $column) {
                $table->double($column)->nullable();
                $table->dateTime($column.'_at', 3)->nullable();
            }
        });

        // Scores stored before the signals contract used QoE v1.
        DB::table('scarlett_views')->whereNotNull('qoe_score')->update(['qoe_version' => 1]);

        Schema::table('scarlett_view_errors', function (Blueprint $table): void {
            $table->string('category')->nullable();
            $table->string('severity')->nullable();
            $table->unsignedInteger('http_status')->nullable();
            $table->unsignedInteger('media_error_code')->nullable();
            $table->unsignedInteger('attempts')->nullable();
            $table->boolean('retries_exhausted')->nullable();
            $table->boolean('reconnect_exhausted')->nullable();
            $table->boolean('timed_out')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('scarlett_view_errors', function (Blueprint $table): void {
            $table->dropColumn([
                'category', 'severity', 'http_status', 'media_error_code', 'attempts',
                'retries_exhausted', 'reconnect_exhausted', 'timed_out',
            ]);
        });

        Schema::table('scarlett_views', function (Blueprint $table): void {
            $table->dropColumn([
                'qoe_version', 'warning_count', 'fatal_error_category', 'anonymous',
                'page_url', 'referrer_origin', 'page_load_to_init_ms', 'player_init_ms',
                ...self::INTERVALS,
                ...array_map(fn (string $column): string => $column.'_at', self::INTERVALS),
            ]);
        });
    }
};
