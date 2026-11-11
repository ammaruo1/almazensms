<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecordSchedulerHeartbeat extends Command
{
    protected $signature = 'scheduler:heartbeat';

    protected $description = 'Record that the application scheduler ran. This does not configure hosting cron.';

    public function handle(): int
    {
        $now = now();
        $updated = DB::table('scheduler_heartbeats')->where('name', 'schedule')->update([
            'last_ran_at' => $now,
            'updated_at' => $now,
        ]);

        if ($updated === 0) {
            DB::table('scheduler_heartbeats')->insert([
                'name' => 'schedule',
                'last_ran_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->info('Scheduler heartbeat recorded. Hosting cron is not confirmed by this command existing.');

        return self::SUCCESS;
    }
}
