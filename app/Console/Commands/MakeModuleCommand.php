<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MakeModuleCommand extends Command
{
    protected $signature = 'platform:make-module {name}';

    protected $description = 'Scaffold a new business module';

    public function handle(): int
    {
        $name = $this->argument('name');
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
        $base = base_path("Modules/{$name}");

        if (is_dir($base)) {
            $this->error('Module already exists.');

            return self::FAILURE;
        }

        foreach (['Config', 'Database/migrations', 'Domain', 'Http/Controllers', 'Models', 'Providers', 'Resources/views', 'Routes', 'Services', 'Tests'] as $dir) {
            File::makeDirectory("{$base}/{$dir}", 0755, true);
        }

        File::put("{$base}/module.json", json_encode([
            'name' => $name,
            'slug' => $slug,
            'version' => '1.0.0',
            'description' => "{$name} module",
            'dependencies' => [],
            'permissions' => [],
            'entitlements' => ["{$slug}.access"],
            'navigation' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        File::put("{$base}/Routes/web.php", "<?php\n\nuse Illuminate\Support\Facades\Route;\n\n// Routes for {$name} (wrap with tenant + entitlement middleware).\n");
        File::put("{$base}/Providers/{$name}ServiceProvider.php", "<?php\n\nnamespace Modules\\{$name}\\Providers;\n\nuse Illuminate\Support\ServiceProvider;\n\nclass {$name}ServiceProvider extends ServiceProvider\n{\n    public function boot(): void {}\n    public function register(): void {}\n}\n");

        $this->info("Module [{$name}] scaffolded at Modules/{$name}.");

        return self::SUCCESS;
    }
}
