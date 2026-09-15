<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantModule extends Model
{
    protected $fillable = ['tenant_id', 'module_id', 'enabled', 'settings', 'enabled_at', 'disabled_at'];

    public $incrementing = false;

    protected $primaryKey = null;

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'settings' => 'array', 'enabled_at' => 'datetime', 'disabled_at' => 'datetime'];
    }
}
