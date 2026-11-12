<?php

use App\Models\Message;
use App\Support\PhoneMask;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->string('destination_mask', 32)->nullable()->after('destination');
        });

        Message::query()->whereNull('destination_mask')->orderBy('id')->chunkById(100, function ($messages): void {
            foreach ($messages as $message) {
                if (! $message instanceof Message) {
                    continue;
                }
                $mask = PhoneMask::mask((string) $message->destination);
                $message->forceFill(['destination_mask' => $mask])->saveQuietly();
            }
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn('destination_mask');
        });
    }
};
