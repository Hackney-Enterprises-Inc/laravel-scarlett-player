<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scarlett_clips', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // A string morph id, so hosts with uuid or ulid keys work (as scarlett_views).
            $table->string('clippable_type')->nullable();
            $table->string('clippable_id', 191)->nullable();
            $table->index(['clippable_type', 'clippable_id']);
            $table->string('media_id');
            $table->string('client_request_id')->unique();

            // Integer user ids. A host with uuid or ulid user keys edits this column
            // after publishing the migration; clippable_id needs no edit.
            $user = $table->foreignId('user_id')->nullable();

            if (Schema::hasTable('users')) {
                $user->constrained()->nullOnDelete();
            } else {
                $user->index();
            }

            $table->string('title')->nullable();
            $table->decimal('start_seconds', 10, 3);
            $table->decimal('end_seconds', 10, 3);
            $table->decimal('duration_seconds', 10, 3);
            $table->timestamp('captured_at')->nullable();
            $table->string('status', 16)->default('pending');
            $table->string('visibility', 16)->default('pending_review');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('rendered_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'dispatched_at']);
            $table->index('visibility');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scarlett_clips');
    }
};
