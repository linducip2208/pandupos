<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendPendingNotifications extends Command
{
    protected $signature = 'notifications:send-pending {--limit=500}';

    protected $description = 'Proses antrean notifikasi in-app yang masih pending';

    public function handle(): int
    {
        $ids = DB::table('announcement_deliveries')->where('status', 'queued')->limit((int) $this->option('limit'))->pluck('id');
        $updated = DB::table('announcement_deliveries')->whereIn('id', $ids)->update(['status' => 'sent', 'updated_at' => now()]);
        $this->info($updated.' notifikasi diproses.');

        return self::SUCCESS;
    }
}
