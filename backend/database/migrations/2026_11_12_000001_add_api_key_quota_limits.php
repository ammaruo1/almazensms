<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->unsignedSmallInteger('daily_message_limit')->default(20);
            $table->unsignedSmallInteger('requests_per_minute')->default(10);
        });

        Schema::create('api_key_daily_usage', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('api_key_id')->constrained()->cascadeOnDelete();
            $table->date('usage_date');
            $table->unsignedInteger('message_count')->default(0);
            $table->timestamps();
            $table->unique(['api_key_id', 'usage_date']);
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->unsignedBigInteger('api_key_id')->nullable();
            $table->index(['api_key_id', 'created_at']);
            $table->foreign('api_key_id')->references('id')->on('api_keys')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropForeign(['api_key_id']);
            $table->dropIndex(['api_key_id', 'created_at']);
            $table->dropColumn('api_key_id');
        });

        Schema::dropIfExists('api_key_daily_usage');

        Schema::table('api_keys', function (Blueprint $table): void {
            $table->dropColumn(['daily_message_limit', 'requests_per_minute']);
        });
    }
};
