<?php

namespace App\Services;

use App\Models\Module;
use App\Models\TenantModule;
use App\Support\ModuleManifestValidator;
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
        return ModuleManifestValidator::validate($manifest);
    }

    /** @return array<string,array> slug => manifest */
    public function manifests(): array
    {
        return Cache::remember('module-manifests', 300, function () {
            $loaded = ModuleManifestValidator::loadFromDisk(base_path('Modules'));

            return $loaded['manifests'];
        });
    }

    /** @return list<array{label:string,route:string,icon:string|null}> */
    public function navigationFor(int $tenantId): array
    {
        $nav = [];
        foreach ($this->manifests() as $slug => $manifest) {
            if (! $this->isEnabled($tenantId, $slug)) {
                continue;
            }
            if (! app(EntitlementService::class)->allowed($tenantId, "{$slug}.access")) {
                // Still show if no explicit entitlement required? Only gate when manifest declares it.
                if (in_array("{$slug}.access", $manifest['entitlements'] ?? [], true)) {
                    continue;
                }
            }
            foreach ($manifest['navigation'] ?? [] as $item) {
                $nav[] = [
                    'label' => $item['label'] ?? $slug,
                    'route' => $item['route'] ?? '',
                    'icon' => $item['icon'] ?? null,
                    'module' => $slug,
                ];
            }
        }

        return $nav;
    }

    public function forget(): void
    {
        Cache::forget('module-registry');
        Cache::forget('module-manifests');
    }
}
