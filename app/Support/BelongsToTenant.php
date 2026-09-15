<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/** Attach to every tenant-owned model. Auto-fills tenant_id and adds isolation scope. */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model) {
            if (empty($model->getAttribute('tenant_id')) && TenantContext::id() !== null) {
                $model->setAttribute('tenant_id', TenantContext::id());
            }
        });
    }

    public function tenant()
    {
        return $this->belongsTo(\App\Models\Tenant::class);
    }
}
