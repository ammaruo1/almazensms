<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->uuid('device_id')->nullable()->index();
            $table->string('idempotency_key', 128);
            $table->char('payload_hash', 64);
            $table->text('destination');
            $table->longText('body');
            $table->string('status', 32)->default('queued')->index();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('queued_at');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'idempotency_key']);
            $table->foreign('device_id')->references('id')->on('devices')->nullOnDelete();
            $table->index(['project_id', 'status', 'queued_at']);
        });

        Schema::create('message_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('message_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 48);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('actor_type', 24);
            $table->string('actor_id', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['message_id', 'created_at']);
        });

        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('aggregate_type', 32);
            $table->string('aggregate_id', 64);
            $table->string('event_type', 64);
            $table->json('payload');
            $table->timestamp('available_at')->useCurrent()->index();
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->timestamp('processed_at')->nullable()->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['processed_at', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('message_events');
        Schema::dropIfExists('messages');
    }
};
