<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class BackupDatabase extends Command
{
    protected $signature = 'backup:database';

    protected $description = 'Buat backup database ke storage/app/private/backups';

    public function handle(): int
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");
        $filename = 'backups/database-'.now()->format('Ymd-His');

        if (($config['driver'] ?? null) === 'sqlite') {
            $source = (string) ($config['database'] ?? '');
            if ($source === ':memory:' || ! is_file($source)) {
                $this->warn('Database SQLite memory atau file tidak ditemukan.');

                return self::FAILURE;
            }
            Storage::disk('local')->put($filename.'.sqlite', file_get_contents($source));
        } elseif (($config['driver'] ?? null) === 'mysql') {
            $process = new Process(['mysqldump', '--single-transaction', '--host='.$config['host'], '--port='.$config['port'], '--user='.$config['username'], $config['database']], null, ['MYSQL_PWD' => $config['password']]);
            $process->setTimeout(300)->mustRun();
            Storage::disk('local')->put($filename.'.sql', $process->getOutput());
        } else {
            $this->error('Driver database belum didukung oleh command backup.');

            return self::FAILURE;
        }

        $this->info('Backup tersimpan: '.$filename);

        return self::SUCCESS;
    }
}
