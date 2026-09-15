<?php

namespace App\Services;

use App\Models\Module;
use App\Models\TenantModule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Central module registry. Manifests live in Modules/<Slug>/module.json.
 * V1 ships with file-less registry (DB) + manifest validation helper.
 */
final class ModuleRegistry
{
    /** @return array<int, array> */
    public function all(): array
    {
        return Cache::remember('module-registry', 300, fn () => Module::query()
            ->orderBy('slug')
            ->get()
            ->toArray());
    }

    public function isEnabled(int $tenantId, string $slug): bool
    {
        $module = Module::query()->where('slug', $slug)->first();

        if (! $module) {
            return false;
        }

        if (! Schema::hasTable('tenant_modules')) {
            return false;
        }

        return TenantModule::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('module_id', $module->getKey())
            ->where('enabled', true)
            ->exists();
    }

    /** Validate a manifest array. Returns list of errors (empty = valid). */
    public static function validateManifest(array $manifest): array
    {
        $errors = [];

        foreach (['name', 'slug', 'version'] as $required) {
            if (empty($manifest[$required])) {
                $errors[] = "Missing required key: {$required}";
            }
        }

        if (isset($manifest['slug']) && ! preg_match('/^[a-z0-9_.-]+$/', $manifest['slug'])) {
            $errors[] = 'Slug must be lowercase alphanumeric with dashes/underscores/dots.';
        }

        return $errors;
    }

    public function forget(): void
    {
        Cache::forget('module-registry');
    }
}
