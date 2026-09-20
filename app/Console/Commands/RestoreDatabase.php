<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

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
            // Array argv (no shell) + stdin feed: portable across Windows/cmd
            // and POSIX shells, unlike string commands with escapeshellarg.
            $process = new Process([
                'mysql',
                '--host='.$config['host'], '--port='.$config['port'],
                '--user='.$config['username'], $config['database'],
            ], null, ['MYSQL_PWD' => $config['password']]);
            $process->setInput(file_get_contents($tmp));
            $process->setTimeout(300)->run();
            @unlink($tmp);
            if (! $process->isSuccessful()) {
                $this->error('mysql restore failed: '.$process->getErrorOutput().' '.$process->getOutput());

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
