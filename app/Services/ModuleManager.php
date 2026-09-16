<?php

namespace App\Services;

use App\Models\Module;
use App\Models\TenantModule;
use Illuminate\Support\Facades\DB;

/**
 * Enables/disables modules per tenant with dependency awareness.
 * Manifest dependencies are stored in modules.metadata->dependencies.
 */
final class ModuleManager
{
    public function __construct(private ModuleRegistry $registry, private ?AuditService $audit = null) {}

    public function enable(int $tenantId, string $slug): void
    {
        $module = Module::query()->where('slug', $slug)->firstOrFail();

        $deps = $module->metadata['dependencies'] ?? [];
        foreach ($deps as $dep) {
            if (! $this->registry->isEnabled($tenantId, $dep)) {
                throw new \RuntimeException("Cannot enable [{$slug}]: dependency [{$dep}] is not enabled.");
            }
        }

        DB::transaction(function () use ($tenantId, $module, $slug) {
            TenantModule::query()->withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenantId, 'module_id' => $module->getKey()],
                ['enabled' => true, 'enabled_at' => now(), 'disabled_at' => null]
            );
            $this->audit?->log($tenantId, auth()->id(), 'module.enabled', Module::class, $module->getKey(), null, ['slug' => $slug]);
        });
    }

    public function disable(int $tenantId, string $slug): void
    {
        $module = Module::query()->where('slug', $slug)->firstOrFail();

        // Prevent disabling a module others depend on.
        $dependents = Module::query()->get()->filter(function (Module $m) use ($slug) {
            return in_array($slug, $m->metadata['dependencies'] ?? [], true);
        })->filter(fn (Module $m) => $this->registry->isEnabled($tenantId, $m->slug));

        if ($dependents->isNotEmpty()) {
            $names = $dependents->pluck('slug')->implode(', ');
            throw new \RuntimeException("Cannot disable [{$slug}]: enabled dependents exist [{$names}].");
        }

        TenantModule::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('module_id', $module->getKey())
            ->update(['enabled' => false, 'disabled_at' => now()]);
        $this->audit?->log($tenantId, auth()->id(), 'module.disabled', Module::class, $module->getKey(), null, ['slug' => $slug]);
    }
}
