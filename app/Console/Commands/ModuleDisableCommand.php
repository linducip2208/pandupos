<?php

namespace App\Console\Commands;

use App\Services\ModuleManager;
use Illuminate\Console\Command;

class ModuleDisableCommand extends Command
{
    protected $signature = 'platform:module:disable {slug} {--tenant=}';

    protected $description = 'Disable a module for a tenant';

    public function handle(ModuleManager $manager): int
    {
        $tenant = (int) ($this->option('tenant') ?? 0);

        if ($tenant <= 0) {
            $this->error('Pass --tenant=<id>.');

            return self::FAILURE;
        }

        try {
            $manager->disable($tenant, $this->argument('slug'));
            $this->info('Disabled.');
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
