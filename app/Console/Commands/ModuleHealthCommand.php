<?php

namespace App\Console\Commands;

use App\Models\Module;
use App\Services\ModuleRegistry;
use Illuminate\Console\Command;

class ModuleHealthCommand extends Command
{
    protected $signature = 'platform:module:health';

    protected $description = 'Check module manifests and registry health';

    public function handle(): int
    {
        $failures = 0;

        // 1. DB registry check.
        $modules = Module::all();
        $this->info("Registry: {$modules->count()} modules.");

        // 2. Manifest check for Modules/*/.
        $base = base_path('Modules');
        if (! is_dir($base)) {
            $this->warn('No Modules/ directory yet (only DB registry).');

            return self::SUCCESS;
        }

        foreach (glob($base.'/*/module.json') ?: [] as $file) {
            $manifest = json_decode((string) file_get_contents($file), true) ?? [];
            $errors = ModuleRegistry::validateManifest($manifest);
            if ($errors) {
                $failures++;
                $this->error(basename(dirname($file)).': '.implode('; ', $errors));
            } else {
                $this->info(basename(dirname($file)).': OK');
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
