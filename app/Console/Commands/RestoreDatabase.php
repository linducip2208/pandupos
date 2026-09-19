<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class RestoreDatabase extends Command
{
    protected $signature = 'backup:restore {file : Backup artifact under storage/app/private (e.g. backups/database-20260101-013000.sqlite)} {--force}';

    protected $description = 'Restore database dari artifact backup (sqlite file copy / mysql import) untuk drill';

    public function handle(): int
    {
        $file = $this->argument('file');
        $disk = Storage::disk('local');
        if (! $disk->exists($file)) {
            $this->error('Artifact tidak ditemukan: '.$file);

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm('Restore akan menimpa database aktif. Lanjutkan?', false)) {
            return self::FAILURE;
        }
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");
        $driver = $config['driver'] ?? 'unknown';
        if ($driver === 'sqlite') {
            $target = (string) ($config['database'] ?? '');
            if ($target === ':memory:') {
                $this->error('Refusing to restore into :memory: database.');

                return self::FAILURE;
            }
            file_put_contents($target, $disk->get($file));
            DB::reconnect();
            $tables = DB::select("SELECT name FROM sqlite_master WHERE type='table' LIMIT 5");
            $this->info('Restore OK: '.count($tables).'+ tables visible, artifact='.$file);
        } elseif ($driver === 'mysql') {
            $tmp = tempnam(sys_get_temp_dir(), 'restore-').'.sql';
            file_put_contents($tmp, $disk->get($file));
            $cmd = sprintf('mysql --host=%s --port=%s --user=%s %s < %s', escapeshellarg($config['host']), escapeshellarg((string) $config['port']), escapeshellarg($config['username']), escapeshellarg($config['database']), escapeshellarg($tmp));
            $env = ['MYSQL_PWD' => $config['password']];
            $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            $code = proc_close($proc);
            @unlink($tmp);
            if ($code !== 0) {
                $this->error('mysql restore failed: '.$err.' '.$out);

                return self::FAILURE;
            }
            $this->info('Restore OK via mysql: '.$file);
        } else {
            $this->error('Driver tidak didukung: '.$driver);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
