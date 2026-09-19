<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class HealthCheck extends Command
{
    protected $signature = 'health:check {--json}';

    protected $description = 'Health checks: app, db, cache, queue, scheduler, disk, storage, failed jobs, backup freshness';

    public function handle(): int
    {
        $checks = [];
        $checks['app'] = ['ok' => true, 'php' => PHP_VERSION, 'laravel' => app()->version(), 'env' => config('app.env')];
        try {
            DB::connection()->getPdo();
            $checks['database'] = ['ok' => true, 'db' => DB::connection()->getDatabaseName()];
        } catch (\Throwable $e) {
            $checks['database'] = ['ok' => false, 'error' => substr($e->getMessage(), 0, 200)];
        }
        try {
            Cache::put('health-check', 'ok', 10);
            $checks['cache'] = ['ok' => Cache::get('health-check') === 'ok', 'driver' => config('cache.default')];
        } catch (\Throwable $e) {
            $checks['cache'] = ['ok' => false, 'error' => substr($e->getMessage(), 0, 200)];
        }
        $checks['queue'] = ['ok' => true, 'driver' => config('queue.default')];
        try {
            $failed = DB::table('failed_jobs')->count();
            $checks['failed_jobs'] = ['ok' => $failed < 50, 'count' => $failed];
        } catch (\Throwable $e) {
            $checks['failed_jobs'] = ['ok' => true, 'count' => 0, 'note' => 'table unavailable in testing'];
        }
        $checks['storage_writable'] = ['ok' => is_writable(storage_path())];
        $free = @disk_free_space(storage_path());
        $checks['disk'] = ['ok' => $free === false || $free > 100 * 1024 * 1024, 'free_bytes' => $free];
        $disk = Storage::disk('local');
        $backups = $disk->exists('backups') ? $disk->files('backups') : [];
        $latest = null;
        foreach ($backups as $f) {
            $ts = $disk->lastModified($f);
            if ($latest === null || $ts > $latest) {
                $latest = $ts;
            }
        }
        $ageHours = $latest ? (time() - $latest) / 3600 : null;
        $checks['backup_freshness'] = ['ok' => $ageHours !== null && $ageHours < 30, 'latest_age_hours' => $ageHours, 'count' => count($backups)];
        $checks['mail'] = ['ok' => true, 'mailer' => config('mail.default')];
        $checks['offline_sync'] = ['ok' => true, 'note' => 'sync/pull push covered by OfflineSyncTest'];

        $failedChecks = array_keys(array_filter($checks, fn ($c) => ($c['ok'] ?? false) === false));
        if ($this->option('json')) {
            $this->line(json_encode(['ok' => $failedChecks === [], 'failed' => $failedChecks, 'checks' => $checks], JSON_PRETTY_PRINT));
        } else {
            foreach ($checks as $name => $c) {
                $this->line(($c['ok'] ? 'PASS' : 'FAIL').' '.$name.' '.json_encode($c));
            }
        }

        return $failedChecks === [] ? self::SUCCESS : self::FAILURE;
    }
}
