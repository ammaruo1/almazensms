<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->unsignedSmallInteger('daily_message_limit')->default(20);
        });

        Schema::create('project_daily_usage', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->date('usage_date');
            $table->unsignedInteger('message_count')->default(0);
            $table->timestamps();
            $table->unique(['project_id', 'usage_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_daily_usage');
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('daily_message_limit');
        });
    }
};
