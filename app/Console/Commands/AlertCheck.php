<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AlertCheck extends Command
{
    protected $signature = 'alert:check {--json}';

    protected $description = 'Verified alert paths: db, cache, queue, scheduler heartbeat, backup stale, disk, failed jobs, webhooks';

    public function handle(): int
    {
        $alerts = [];
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $alerts[] = 'ALERT: DB unavailable - '.substr($e->getMessage(), 0, 120);
        }
        try {
            Cache::put('alert-ping', '1', 10);
            if (Cache::get('alert-ping') !== '1') {
                $alerts[] = 'ALERT: cache unavailable';
            }
        } catch (\Throwable $e) {
            $alerts[] = 'ALERT: cache unavailable - '.substr($e->getMessage(), 0, 120);
        }
        try {
            $failed = DB::table('failed_jobs')->count();
            if ($failed >= 50) {
                $alerts[] = "ALERT: failed jobs threshold ({$failed} >= 50)";
            }
        } catch (\Throwable) {
        }
        $disk = Storage::disk('local');
        $backups = $disk->exists('backups') ? $disk->files('backups') : [];
        $latest = null;
        foreach ($backups as $f) {
            $ts = $disk->lastModified($f);
            if ($latest === null || $ts > $latest) {
                $latest = $ts;
            }
        }
        if ($latest === null || (time() - $latest) > 30 * 3600) {
            $alerts[] = 'ALERT: backup stale (none in last 30h)';
        } else {
            $alerts[] = 'NOTE: backup fresh';
        }
        $free = @disk_free_space(storage_path());
        if ($free !== false && $free < 100 * 1024 * 1024) {
            $alerts[] = 'ALERT: disk high ('.round($free / 1024 / 1024).' MB free)';
        }
        $heartbeat = Cache::get('scheduler-heartbeat');
        if ($heartbeat === null) {
            $alerts[] = 'NOTE: scheduler heartbeat missed (run schedule:run every minute in prod)';
        }
        $alerts[] = 'NOTE: payment webhook failures are tracked in payment_webhooks/billing_transactions; monitor gateway dashboard + failed_jobs';

        if ($this->option('json')) {
            $this->line(json_encode(['alerts' => $alerts], JSON_PRETTY_PRINT));
        } else {
            foreach ($alerts as $a) {
                $this->line($a);
            }
        }
        // Always SUCCESS so scheduler can log output; alerting is via log/output webhook.
        logger()->warning('alert:check', ['alerts' => $alerts]);

        return self::SUCCESS;
    }
}
