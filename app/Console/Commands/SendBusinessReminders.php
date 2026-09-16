<?php

namespace App\Console\Commands;

use App\Models\Announcement;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendBusinessReminders extends Command
{
    protected $signature = 'business:send-reminders';

    protected $description = 'Antrekan pengingat subscription H-1 untuk tenant terkait';

    public function handle(): int
    {
        $subscriptions = Subscription::withoutGlobalScopes()->whereIn('status', Subscription::ACTIVE_STATUSES)
            ->whereBetween('current_period_end', [now()->addDay()->startOfDay(), now()->addDay()->endOfDay()])->get();
        if ($subscriptions->isEmpty()) {
            $this->info('Tidak ada pengingat H-1.');

            return self::SUCCESS;
        }

        $subject = 'Pengingat subscription H-1 · '.now()->toDateString();
        $announcement = Announcement::firstOrCreate(['subject' => $subject], [
            'body' => 'Masa aktif subscription berakhir besok. Periksa invoice dan metode pembayaran agar layanan tetap aktif.',
            'audience' => 'expiring', 'channel' => 'in-app', 'scheduled_at' => now(),
        ]);
        foreach ($subscriptions as $subscription) {
            DB::table('announcement_deliveries')->insertOrIgnore([
                'announcement_id' => $announcement->id, 'tenant_id' => $subscription->tenant_id,
                'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->info($subscriptions->count().' pengingat diantrikan.');

        return self::SUCCESS;
    }
}
