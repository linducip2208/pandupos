<?php

namespace App\Console\Commands;

use App\Models\Module;
use App\Services\ModuleRegistry;
use App\Support\ModuleManifestValidator;
use Illuminate\Console\Command;

class ModuleHealthCommand extends Command
{
    protected $signature = 'platform:module:health {slug? : Optional module slug (e.g. POS)}';

    protected $description = 'Check module manifests and registry health';

    public function handle(ModuleRegistry $registry): int
    {
        $failures = 0;

        // 1. DB registry check.
        $modules = Module::all();
        $this->info("Registry: {$modules->count()} modules.");

        // 2. Manifest check for Modules/*/.
        $loaded = ModuleManifestValidator::loadFromDisk(base_path('Modules'));

        $filter = $this->argument('slug') ? strtolower((string) $this->argument('slug')) : null;
        $manifests = $loaded['manifests'];

        if ($filter) {
            $found = null;
            foreach ($manifests as $slug => $m) {
                if (strtolower($slug) === $filter || strtolower($m['name'] ?? '') === $filter) {
                    $found = [$slug => $m];
                    break;
                }
            }
            if (! $found) {
                $this->error("Module [{$this->argument('slug')}] not found in Modules/*/module.json.");

                return self::FAILURE;
            }
            $manifests = $found;
        }

        if (empty($manifests)) {
            $this->warn('No Modules/ manifests found.');
        }

        foreach ($manifests as $slug => $manifest) {
            $name = $manifest['name'] ?? $slug;
            $version = $manifest['version'] ?? '?';
            $this->line('');
            $this->info($name);
            $this->line("Version: {$version}");

            $errors = ModuleRegistry::validateManifest($manifest);

            // Dependency check.
            $deps = $manifest['dependencies'] ?? [];
            $dbSlugs = $modules->pluck('slug')->all();
            foreach ($deps as $dep) {
                if (! isset($loaded['manifests'][$dep]) && ! in_array($dep, $dbSlugs, true)) {
                    $errors[] = "Missing dependency [{$dep}].";
                }
            }

            if ($errors) {
                $failures++;
                $this->error('Status: BROKEN');
                foreach ($errors as $e) {
                    $this->error("  ✗ {$e}");
                }

                continue;
            }

            $this->info('Status: HEALTHY');
            $this->line('Dependencies:');
            if (empty($deps)) {
                $this->line('  (none)');
            } else {
                foreach ($deps as $dep) {
                    $this->line("  ✓ {$dep}");
                }
            }

            $this->line('Permissions:');
            foreach ($manifest['permissions'] ?? [] as $perm) {
                $this->line("  ✓ {$perm}");
            }

            $this->line('Entitlements:');
            foreach ($manifest['entitlements'] ?? [] as $ent) {
                $this->line("  ✓ {$ent}");
            }

            if (isset($manifest['provider'])) {
                $ok = class_exists($manifest['provider']) ? '✓' : '✗';
                $this->line("Provider: {$ok} {$manifest['provider']}");
                if (! class_exists($manifest['provider'])) {
                    $failures++;
                }
            }
        }

        foreach ($loaded['errors'] as $e) {
            // Already reported per-module; surface set-level errors (duplicates, circular).
            if (str_contains($e, 'Circular') || str_contains($e, 'Duplicate') || str_contains($e, 'Slug mismatch')) {
                $failures++;
                $this->error($e);
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
