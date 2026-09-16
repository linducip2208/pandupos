<?php

namespace App\Models;

use App\Support\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TenantModule extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'module_id', 'enabled', 'settings', 'enabled_at', 'disabled_at'];

    public $incrementing = false;

    protected $primaryKey = null;

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'settings' => 'array', 'enabled_at' => 'datetime', 'disabled_at' => 'datetime'];
    }

    public function module()
    {
        return $this->belongsTo(Module::class);
    }
}
