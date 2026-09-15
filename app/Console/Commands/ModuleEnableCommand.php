<?php

namespace App\Console\Commands;

use App\Services\ModuleManager;
use Illuminate\Console\Command;

class ModuleEnableCommand extends Command
{
    protected $signature = 'platform:module:enable {slug} {--tenant=}';

    protected $description = 'Enable a module for a tenant';

    public function handle(ModuleManager $manager): int
    {
        $tenant = (int) ($this->option('tenant') ?? 0);

        if ($tenant <= 0) {
            $this->error('Pass --tenant=<id>.');
            return self::FAILURE;
        }

        try {
            $manager->enable($tenant, $this->argument('slug'));
            $this->info('Enabled.');
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
