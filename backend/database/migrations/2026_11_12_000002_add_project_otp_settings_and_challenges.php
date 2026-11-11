<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->boolean('otp_enabled')->default(false);
            $table->unsignedSmallInteger('otp_ttl_seconds')->default(300);
            $table->unsignedTinyInteger('otp_max_attempts')->default(5);
            $table->unsignedSmallInteger('otp_resend_cooldown_seconds')->default(60);
        });

        Schema::create('otp_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('api_key_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('message_id')->constrained('messages')->cascadeOnDelete();
            $table->string('purpose', 32)->default('login');
            $table->char('phone_hash', 64);
            $table->string('phone_mask', 32);
            $table->char('code_hash', 64);
            $table->string('status', 24)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts');
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->string('idempotency_key', 128);
            $table->timestamps();
            $table->unique(['project_id', 'idempotency_key']);
            $table->index(['project_id', 'phone_hash', 'purpose', 'created_at'], 'otp_challenges_phone_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_challenges');

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn([
                'otp_enabled',
                'otp_ttl_seconds',
                'otp_max_attempts',
                'otp_resend_cooldown_seconds',
            ]);
        });
    }
};
