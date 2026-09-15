<?php

namespace App\Console\Commands;

use App\Models\Module;
use App\Services\ModuleRegistry;
use Illuminate\Console\Command;

class ModuleListCommand extends Command
{
    protected $signature = 'platform:module:list';

    protected $description = 'List registered modules';

    public function handle(ModuleRegistry $registry): int
    {
        $rows = Module::orderBy('slug')->get()
            ->map(fn (Module $m) => [$m->slug, $m->name, $m->version, $m->is_core ? 'core' : '', $m->is_paid ? 'paid' : 'free'])
            ->all();

        $this->table(['slug', 'name', 'version', 'core', 'pricing'], $rows);

        return self::SUCCESS;
    }
}
