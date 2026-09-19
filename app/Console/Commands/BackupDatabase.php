<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class BackupDatabase extends Command
{
    protected $signature = 'backup:database {--retention=7 : Keep backups for N days} {--destination=local}';

    protected $description = 'Buat backup database + uploads manifest ke storage/app/private/backups dengan retensi dan verifikasi';

    public function handle(): int
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");
        $stamp = now()->format('Ymd-His');
        $filename = 'backups/database-'.$stamp;
        $disk = Storage::disk('local');

        if (($config['driver'] ?? null) === 'sqlite') {
            $source = (string) ($config['database'] ?? '');
            if ($source === ':memory:' || ! is_file($source)) {
                $this->warn('Database SQLite memory atau file tidak ditemukan.');

                return self::FAILURE;
            }
            $disk->put($filename.'.sqlite', file_get_contents($source));
            $artifact = $filename.'.sqlite';
        } elseif (($config['driver'] ?? null) === 'mysql') {
            $process = new Process(['mysqldump', '--single-transaction', '--host='.$config['host'], '--port='.$config['port'], '--user='.$config['username'], $config['database']], null, ['MYSQL_PWD' => $config['password']]);
            $process->setTimeout(300)->mustRun();
            $disk->put($filename.'.sql', $process->getOutput());
            $artifact = $filename.'.sql';
        } else {
            $this->error('Driver database belum didukung oleh command backup.');

            return self::FAILURE;
        }

        // Uploads manifest (important application storage required for restoration).
        $publicFiles = [];
        $publicRoot = storage_path('app/public');
        if (is_dir($publicRoot)) {
            $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($publicRoot, \FilesystemIterator::SKIP_DOTS));
            foreach ($iter as $file) {
                if ($file->isFile()) {
                    $publicFiles[] = substr($file->getPathname(), strlen($publicRoot) + 1).' ('.$file->getSize().' bytes)';
                    if (count($publicFiles) >= 5000) {
                        break;
                    }
                }
            }
        }
        $manifest = [
            'taken_at' => now()->toIso8601String(),
            'app' => config('app.name'),
            'git_sha' => trim((string) @shell_exec('git rev-parse --short HEAD')),
            'db_driver' => $config['driver'] ?? 'unknown',
            'artifact' => $artifact,
            'artifact_size' => $disk->size($artifact),
            'uploads_count' => count($publicFiles),
            'uploads_sample' => array_slice($publicFiles, 0, 50),
            'php' => PHP_VERSION,
            'retention_days' => (int) $this->option('retention'),
        ];
        $disk->put($filename.'.manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

        // Verification: artifact must exist and be non-empty.
        if (! $disk->exists($artifact) || $disk->size($artifact) <= 0) {
            $this->error('Verifikasi backup gagal: artifact kosong/hilang.');

            return self::FAILURE;
        }

        // Retention: prune backups older than N days.
        $retention = max(1, (int) $this->option('retention'));
        $cutoff = now()->subDays($retention)->timestamp;
        foreach ($disk->files('backups') as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $this->line('Pruned: '.$file);
            }
        }

        $this->info('Backup tersimpan: '.$artifact.' ('.$manifest['artifact_size'].' bytes, '.$manifest['uploads_count'].' uploads tracked)');

        return self::SUCCESS;
    }
}
